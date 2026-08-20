<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=100,min_height=100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required'   => 'Une image est requise.',
            'logo.image'      => 'Le fichier doit être une image.',
            'logo.mimes'      => 'Formats acceptés : JPG, PNG, WEBP.',
            'logo.max'        => 'L\'image ne doit pas dépasser 5 Mo.',
            'logo.dimensions' => 'L\'image doit faire au moins 100x100 pixels.',
        ];
    }
}
