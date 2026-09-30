<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginThrottleService;
use App\Services\Phone\PhoneParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
            'phone' => ['required', 'string', 'phone:AUTO,INTERNATIONAL'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.phone' => 'Le numéro de téléphone n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ];
    }

    /**
     * Rate limiting : vérifie les tentatives par compte client et par IP.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        LoginThrottleService::ensureIsNotRateLimited('client', (string) $this->input('phone'), $this->ip());
    }

    /**
     * Enregistre un essai raté.
     */
    public function hitRateLimiter(): void
    {
        LoginThrottleService::hit('client', (string) $this->input('phone'), $this->ip());
    }

    /**
     * Réinitialise le compteur après un login réussi.
     */
    public function clearRateLimiter(): void
    {
        LoginThrottleService::clear('client', (string) $this->input('phone'), $this->ip());
    }

    /**
     * Clé unique pour le rate limiter (rétrocompatibilité).
     */
    public function throttleKey(): string
    {
        return LoginThrottleService::accountKey('client', (string) $this->input('phone'));
    }
}
