<?php

namespace App\Http\Controllers;

use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10)
            ->through(fn (User $user): array => $this->userPayload($user, $request->user()));

        return response()->json([
            'users' => $users->items(),
            'roles' => $this->availableRoles(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::in($this->availableRoles())],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $this->deletePendingLoginCodesForEmails([$validated['email']]);

            $user = User::create([
                'last_name' => $validated['last_name'],
                'first_name' => $validated['first_name'],
                'email' => $validated['email'],
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($validated['roles']);

            return $user;
        });

        Log::notice('security.admin_user.created', [
            'actor_user_id' => $request->user()?->getKey(),
            'target_user_id' => $user->getKey(),
            'roles' => $validated['roles'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User created.',
            'user' => $this->userPayload($user->load('roles')),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::in($this->availableRoles())],
        ]);

        $user = DB::transaction(function () use ($request, $user, $validated): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $superAdminIds = $this->lockedSuperAdminIds();
            $removesSuperAdmin = $superAdminIds->contains($user->getKey())
                && ! in_array('super_admin', $validated['roles'], true);

            if ($user->is_protected && $removesSuperAdmin) {
                throw ValidationException::withMessages([
                    'roles' => 'This protected account must keep the super admin role.',
                ]);
            }

            if ($request->user()?->is($user) && $removesSuperAdmin) {
                throw ValidationException::withMessages([
                    'roles' => 'You cannot remove your own super admin role.',
                ]);
            }

            if ($removesSuperAdmin && $superAdminIds->count() === 1) {
                throw ValidationException::withMessages([
                    'roles' => 'At least one super admin account is required.',
                ]);
            }

            $oldEmail = $user->email;
            $newEmail = $validated['email'];
            $currentRoles = $user->getRoleNames()->sort()->values()->all();
            $newRoles = collect($validated['roles'])->unique()->sort()->values()->all();
            $emailChanged = $oldEmail !== $newEmail;
            $rolesChanged = $currentRoles !== $newRoles;

            $user->fill([
                'last_name' => $validated['last_name'],
                'first_name' => $validated['first_name'],
                'email' => $newEmail,
            ]);

            if ($emailChanged || $rolesChanged) {
                $user->auth_revision++;
                $this->deletePendingLoginCodesForEmails([$oldEmail, $newEmail]);
            }

            $user->save();
            $user->syncRoles($newRoles);

            return $user->load('roles');
        });

        Log::notice('security.admin_user.updated', [
            'actor_user_id' => $request->user()?->getKey(),
            'target_user_id' => $user->getKey(),
            'roles' => $validated['roles'],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User updated.',
            'user' => $this->userPayload($user, $request->user()),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $superAdminIds = $this->lockedSuperAdminIds();

            if ($request->user()?->is($user) || $user->is_protected) {
                throw ValidationException::withMessages([
                    'user' => 'This user account cannot be deleted.',
                ]);
            }

            if ($superAdminIds->contains($user->getKey()) && $superAdminIds->count() === 1) {
                throw ValidationException::withMessages([
                    'user' => 'At least one super admin account is required.',
                ]);
            }

            $this->deletePendingLoginCodesForEmails([$user->email]);
            $user->delete();
        });

        Log::notice('security.admin_user.deleted', [
            'actor_user_id' => $request->user()?->getKey(),
            'target_user_id' => $user->getKey(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User deleted.',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function availableRoles(): array
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('super_admin');

        return Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @return array{id: int, name: string, first_name: string, last_name: string, email: string, roles: array<int, string>, can_delete: bool, roles_locked: bool, created_at: ?string}
     */
    private function userPayload(User $user, ?User $currentUser = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'can_delete' => $this->canDeleteUser($user, $currentUser),
            'roles_locked' => $user->is_protected,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function canDeleteUser(User $user, ?User $currentUser): bool
    {
        if ($currentUser?->is($user)) {
            return false;
        }

        if ($user->is_protected) {
            return false;
        }

        return ! $user->hasRole('super_admin') || User::role('super_admin')->count() > 1;
    }

    /**
     * @return Collection<int, int>
     */
    private function lockedSuperAdminIds(): Collection
    {
        return User::role('super_admin')
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.id');
    }

    /** @param array<int, string> $emails */
    private function deletePendingLoginCodesForEmails(array $emails): void
    {
        $normalizedEmails = collect($emails)
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($normalizedEmails === []) {
            return;
        }

        AdminLoginCode::query()
            ->whereNull('consumed_at')
            ->where(function (Builder $query) use ($normalizedEmails): void {
                foreach ($normalizedEmails as $email) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->delete();
    }
}
