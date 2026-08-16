<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Fraud\FraudRiskService;
use App\Services\Phone\PhoneParser;

class CompleteSocialProfileRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'      => ['required', 'string', 'phone:AUTO,INTERNATIONAL', 'unique:clients,phone,' . $this->user()?->id],
            'birthdate'  => ['nullable', 'date', 'before:today'],
            'city'       => ['nullable', 'string', 'max:100'],
            'country'    => ['nullable', 'string', 'max:100'],
            'email'      => ['nullable', 'email', 'max:255', 'unique:clients,email,' . $this->user()?->id],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (!$validator->errors()->has('phone') && $this->has('phone')) {
                    app(FraudRiskService::class)->throwOnHighRisk($this->phone, $this->ip(), $this->user()?->id);
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Le prénom est obligatoire.',
            'phone.required'      => 'Le numéro de téléphone est obligatoire.',
            'phone.phone'         => 'Le numéro de téléphone n\'est pas valide.',
            'phone.unique'        => 'Ce numéro de téléphone est déjà utilisé.',
            'birthdate.before'    => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'email.email'         => 'L\'email doit être une adresse valide.',
            'email.unique'        => 'Cet email est déjà utilisé.',
        ];
    }
}
