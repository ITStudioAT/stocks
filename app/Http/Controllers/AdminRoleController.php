<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount('users')
            ->orderBy('name')
            ->paginate(10)
            ->through(fn (Role $role): array => $this->rolePayload($role));

        return response()->json([
            'roles' => $roles->items(),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'from' => $roles->firstItem(),
                'to' => $roles->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedRoleData($request);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        return response()->json([
            'message' => 'Role created.',
            'role' => $this->rolePayload($role),
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->ensureRoleCanBeModified($role, 'This system role cannot be edited.');

        $validated = $this->validatedRoleData($request, $role);

        $role->update([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Role updated.',
            'role' => $this->rolePayload($role),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->ensureRoleCanBeModified($role, 'This system role cannot be deleted.');

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'This role is assigned to users and cannot be deleted.',
            ]);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted.',
        ]);
    }

    /**
     * @return array{id: int, name: string, guard_name: string, users_count: int, can_edit: bool, can_delete: bool, created_at: ?string, updated_at: ?string}
     */
    private function rolePayload(Role $role): array
    {
        $usersCount = (int) ($role->users_count ?? $role->users()->count());
        $isSystemRole = $this->isSystemRole($role);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'users_count' => $usersCount,
            'can_edit' => ! $isSystemRole,
            'can_delete' => ! $isSystemRole && $usersCount === 0,
            'created_at' => $role->created_at?->toIso8601String(),
            'updated_at' => $role->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string}
     */
    private function validatedRoleData(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Role::class, 'name')
                    ->where('guard_name', 'web')
                    ->ignore($role),
            ],
        ]);
    }

    private function ensureRoleCanBeModified(Role $role, string $message): void
    {
        if (! $this->isSystemRole($role)) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => $message,
        ]);
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, ['admin', 'super_admin'], true);
    }
}
