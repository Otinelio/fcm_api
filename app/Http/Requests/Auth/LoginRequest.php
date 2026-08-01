<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Services\Phone\PhoneParser;

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
            'phone'    => ['required', 'string', 'phone:AUTO,INTERNATIONAL'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required'    => 'Le numéro de téléphone est obligatoire.',
            'phone.phone'       => 'Le numéro de téléphone n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ];
    }

    /**
     * Rate limiting : 5 tentatives par minute par combinaison IP + phone.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $key = $this->throttleKey();

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'phone' => [
                "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ],
        ])->status(429);
    }

    /**
     * Enregistre un essai raté.
     */
    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
    }

    /**
     * Réinitialise le compteur après un login réussi.
     */
    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Clé unique pour le rate limiter.
     */
    private function throttleKey(): string
    {
        return Str::lower($this->input('phone')) . '|' . $this->ip();
    }
}
