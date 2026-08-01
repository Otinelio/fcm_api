<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Fraud\FraudRiskService;
use App\Services\Phone\PhoneParser;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('phone') && !empty($this->phone)) {
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
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'      => ['sometimes', 'nullable', 'email', 'max:255', 'unique:clients,email,' . $this->user()?->id],
            'phone'      => ['sometimes', 'string', 'phone:AUTO,INTERNATIONAL', 'unique:clients,phone,' . $this->user()?->id],
            'city'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'birthdate'  => ['sometimes', 'nullable', 'date', 'before:today'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (!$validator->errors()->has('phone') && $this->has('phone') && !empty($this->phone)) {
                    app(FraudRiskService::class)->throwOnHighRisk($this->phone, $this->ip(), $this->user()?->id);
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'     => 'Cet email est déjà utilisé.',
            'email.email'      => 'L\'email doit être une adresse valide.',
            'phone.phone'      => 'Le numéro de téléphone n\'est pas valide.',
            'phone.unique'     => 'Ce numéro de téléphone est déjà utilisé.',
            'birthdate.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'avatar_url.url'   => 'L\'URL de l\'avatar doit être valide.',
        ];
    }
}
