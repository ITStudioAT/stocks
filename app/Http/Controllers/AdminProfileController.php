<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAdminPasswordRequest;
use App\Models\User;
use App\Services\AdminPasswordUpdater;
use App\Services\AdminSessionManager;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminProfileController extends Controller
{
    public function updateName(Request $request, AdminSessionManager $sessions): JsonResponse
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
            'user' => $this->userPayload(
                $request->user(),
                $sessions->canInitializePassword($request, $request->user()),
            ),
        ]);
    }

    public function updatePassword(
        UpdateAdminPasswordRequest $request,
        AdminPasswordUpdater $passwords,
        AdminSessionManager $sessions,
    ): JsonResponse {
        $validated = $request->validated();
        $canInitializePassword = $sessions->canInitializePassword($request, $request->user());
        $user = $passwords->update(
            $request->user(),
            $validated['current_password'] ?? null,
            $validated['password'],
            $request,
        );

        $sessions->rebindAfterPasswordChange($request, $user);
        $request->session()->passwordConfirmed();

        event(new OtherDeviceLogout('web', $user));

        Log::notice('security.admin_password.changed', [
            'ip' => $request->ip(),
            'user_id' => $user->id,
            'used_otp_initialization' => $canInitializePassword,
        ]);

        return response()->json([
            'message' => 'Password updated.',
            'user' => $this->userPayload($user, false),
        ]);
    }

    /**
     * @return array{
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     roles: array<int, string>,
     *     can_initialize_password: bool,
     * }
     */
    private function userPayload(User $user, bool $canInitializePassword): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'can_initialize_password' => $canInitializePassword,
        ];
    }
}
