<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone'       => ['required_without:email', 'string'],
            'email'       => ['required_without:phone', 'email'],
            'reset_token' => ['required', 'string'],
            'password'    => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.required' => 'Le jeton de réinitialisation est manquant.',
            'password.required'    => 'Le mot de passe est obligatoire.',
            'password.min'         => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed'   => 'Les mots de passe ne correspondent pas.',
        ];
    }
}
