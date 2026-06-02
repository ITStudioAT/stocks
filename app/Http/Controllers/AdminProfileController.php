<?php

namespace App\Http\Controllers;

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
     * @return array{name: string, first_name: string, last_name: string, email: string, roles: array<int, string>}
     */
    private function userPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }
}
