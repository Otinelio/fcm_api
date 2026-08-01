<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:google,apple'],
            'id_token' => ['required', 'string'],
            // Apple envoie parfois le nom uniquement lors de la première connexion
            'name'     => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Le fournisseur OAuth est obligatoire.',
            'provider.in'       => 'Le fournisseur doit être google ou apple.',
            'id_token.required' => 'Le token d\'authentification est obligatoire.',
        ];
    }
}
