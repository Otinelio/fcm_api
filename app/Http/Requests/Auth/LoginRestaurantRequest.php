<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\LoginThrottleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class LoginRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'L\'adresse email n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ];
    }

    /**
     * Rate limiting : vérifie les tentatives par compte restaurant et par IP.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        LoginThrottleService::ensureIsNotRateLimited('restaurant', (string) $this->input('email'), $this->ip());
    }

    public function hitRateLimiter(): void
    {
        LoginThrottleService::hit('restaurant', (string) $this->input('email'), $this->ip());
    }

    public function clearRateLimiter(): void
    {
        LoginThrottleService::clear('restaurant', (string) $this->input('email'), $this->ip());
    }

    public function throttleKey(): string
    {
        return LoginThrottleService::accountKey('restaurant', (string) $this->input('email'));
    }
}
