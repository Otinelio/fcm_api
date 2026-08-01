<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\Fraud\FraudRiskService;
use App\Services\Phone\PhoneParser;

class ValidateRegisterStep1Request extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'phone'      => ['required', 'string', 'phone:AUTO,INTERNATIONAL', 'unique:clients,phone'],
            'birthdate'  => ['nullable', 'date', 'before:today'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (!$validator->errors()->has('phone') && $this->has('phone') && !empty($this->phone)) {
                    app(FraudRiskService::class)->throwOnHighRisk($this->phone, $this->ip());
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
        ];
    }
}
