<?php

namespace App\Http\Controllers;

use App\Mail\AdminLoginCodeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            throw ValidationException::withMessages([
                'email' => 'No admin user was found for this email address.',
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        DB::table('admin_login_codes')
            ->where('email', $user->email)
            ->whereNull('consumed_at')
            ->delete();

        DB::table('admin_login_codes')->insert([
            'email' => $user->email,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($user->email)->send(new AdminLoginCodeMail($code));

        return response()->json([
            'message' => 'A 6-digit login code has been sent.',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            throw ValidationException::withMessages([
                'code' => 'The login code is invalid or expired.',
            ]);
        }

        $loginCode = DB::table('admin_login_codes')
            ->where('email', $user->email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $loginCode || ! Hash::check($validated['code'], $loginCode->code_hash)) {
            throw ValidationException::withMessages([
                'code' => 'The login code is invalid or expired.',
            ]);
        }

        DB::table('admin_login_codes')
            ->where('id', $loginCode->id)
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function passwordLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are invalid.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasAnyRole(['admin', 'super_admin'])) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'No admin user was found for this email address.',
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
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
