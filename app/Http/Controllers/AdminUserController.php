<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->selectedCompany($request);

        $users = User::query()
            ->with(['company', 'roles'])
            ->where('company_id', $company->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10)
            ->through(fn (User $user): array => $this->userPayload($user, $request->user()));

        return response()->json([
            'users' => $users->items(),
            'roles' => $this->availableRoles(),
            'companies' => Company::query()
                ->orderBy('company_name_1')
                ->get(['id', 'company_name_1']),
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
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'company_id' => ['required', Rule::exists(Company::class, 'id')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::in($this->availableRoles())],
        ]);

        $user = User::create([
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'email' => $validated['email'],
            'company_id' => $validated['company_id'],
            'password' => Str::password(32),
            'email_verified_at' => now(),
        ]);

        $user->syncRoles($validated['roles']);

        return response()->json([
            'message' => 'User created.',
            'user' => $this->userPayload($user->load(['company', 'roles'])),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'company_id' => ['required', Rule::exists(Company::class, 'id')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::in($this->availableRoles())],
        ]);

        if ($this->hasProtectedSuperAdminRole($user) && ! in_array('super_admin', $validated['roles'], true)) {
            throw ValidationException::withMessages([
                'roles' => 'Kron Günther must keep the super admin role.',
            ]);
        }

        $user->fill([
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'email' => $validated['email'],
            'company_id' => $validated['company_id'],
        ]);

        $user->save();
        $user->syncRoles($validated['roles']);

        return response()->json([
            'message' => 'User updated.',
            'user' => $this->userPayload($user->load(['company', 'roles'])),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (! $this->canDeleteUser($user, $request->user())) {
            throw ValidationException::withMessages([
                'user' => 'This user account cannot be deleted.',
            ]);
        }

        $user->delete();

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
     * @return array{id: int, name: string, first_name: string, last_name: string, email: string, company_id: int, company_name: ?string, roles: array<int, string>, can_delete: bool, roles_locked: bool, created_at: ?string}
     */
    private function userPayload(User $user, ?User $currentUser = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'company_name' => $user->company?->company_name_1,
            'roles' => $user->getRoleNames()->values()->all(),
            'can_delete' => $this->canDeleteUser($user, $currentUser),
            'roles_locked' => $this->hasProtectedSuperAdminRole($user),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function canDeleteUser(User $user, ?User $currentUser): bool
    {
        if ($currentUser?->is($user)) {
            return false;
        }

        return $user->email !== 'kron@naturwelt.at';
    }

    private function hasProtectedSuperAdminRole(User $user): bool
    {
        return $user->email === 'kron@naturwelt.at';
    }

    private function selectedCompany(Request $request): Company
    {
        return Company::query()
            ->where('is_active', true)
            ->first() ?? $request->user()->company;
    }
}
