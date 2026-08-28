<?php

namespace App\Http\Requests\Auth;

use App\Services\Phone\PhoneParser;
use Illuminate\Foundation\Http\FormRequest;

class VerifyResetOtpRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('phone') && ! empty($this->phone)) {
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
            'phone' => ['required_without:email', 'string'],
            'email' => ['required_without:phone', 'email'],
            'otp'   => ['required', 'string', 'size:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Le code OTP est requis.',
            'otp.size'     => 'Le code OTP doit contenir 6 caractères.',
        ];
    }
}
