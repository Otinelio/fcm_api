<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Phone\PhoneParser;

class ForgotPasswordRequest extends FormRequest
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
            'phone' => ['required_without:email', 'string', 'phone:AUTO,INTERNATIONAL', 'exists:clients,phone'],
            'email' => ['required_without:phone', 'email', 'exists:clients,email'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.exists' => 'Aucun compte n\'est associé à ce numéro.',
            'phone.phone'  => 'Le numéro de téléphone n\'est pas valide.',
            'email.exists' => 'Aucun compte n\'est associé à cette adresse email.',
            'phone.required_without' => 'Veuillez renseigner votre numéro de téléphone ou votre email.',
            'email.required_without' => 'Veuillez renseigner votre email ou votre numéro de téléphone.',
        ];
    }
}
