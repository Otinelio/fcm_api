<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Fraud\FraudRiskService;
use App\Services\Phone\PhoneParser;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('phone')) {
            $parser = app(PhoneParser::class);
            $normalized = $parser->normalize($this->phone);
            if ($normalized) {
                $this->merge(['phone' => $normalized]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:100'],
            'phone'                 => ['required', 'string', 'phone:AUTO,INTERNATIONAL', 'unique:clients,phone'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'birthdate'             => ['nullable', 'date', 'before:today'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'country'               => ['nullable', 'string', 'max:100'],
            'referral_code'         => ['nullable', 'string', 'exists:clients,referral_code'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (!$validator->errors()->has('phone') && $this->has('phone')) {
                    app(FraudRiskService::class)->throwOnHighRisk($this->phone, $this->ip());
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'  => 'Le prénom est obligatoire.',
            'phone.required'       => 'Le numéro de téléphone est obligatoire.',
            'phone.phone'          => 'Le numéro de téléphone n\'est pas valide.',
            'phone.unique'         => 'Ce numéro de téléphone est déjà utilisé.',
            'password.required'    => 'Le mot de passe est obligatoire.',
            'password.min'         => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed'   => 'Les mots de passe ne correspondent pas.',
            'birthdate.before'     => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'referral_code.exists' => 'Ce code de parrainage est invalide.',
        ];
    }
}
