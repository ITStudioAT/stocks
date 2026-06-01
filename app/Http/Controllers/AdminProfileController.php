<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminProfileController extends Controller
{
    public function updateName(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update([
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
        ]);

        return response()->json([
            'message' => 'Profile name updated.',
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }

    /**
     * @return array{name: string, first_name: string, last_name: string, email: string, company_id: ?int, company_name: ?string, selected_company_id: ?int, selected_company_name: ?string, roles: array<int, string>}
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('company');
        $selectedCompany = $this->selectedCompany($user);

        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'company_name' => $user->company?->company_name_1,
            'selected_company_id' => $selectedCompany?->id,
            'selected_company_name' => $selectedCompany?->company_name_1,
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    private function selectedCompany(User $user): ?Company
    {
        if ($user->hasRole('super_admin')) {
            return Company::query()
                ->where('is_active', true)
                ->first() ?? $user->company;
        }

        return $user->company;
    }
}
