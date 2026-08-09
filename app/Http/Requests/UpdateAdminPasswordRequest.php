<?php

namespace App\Http\Requests;

use App\Services\AdminSessionManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(AdminSessionManager $sessions): array
    {
        $canInitializePassword = $sessions->canInitializePassword($this, $this->user());
        $currentPasswordRules = $canInitializePassword
            ? ['prohibited']
            : ['required', 'string', 'max:1024', 'current_password:web'];
        $newPasswordRules = [
            'required',
            'string',
            'max:1024',
            'confirmed',
        ];

        if (! $canInitializePassword) {
            $newPasswordRules[] = 'different:current_password';
        }

        $newPasswordRules[] = Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();

        return [
            'current_password' => $currentPasswordRules,
            'password' => $newPasswordRules,
        ];
    }
}
