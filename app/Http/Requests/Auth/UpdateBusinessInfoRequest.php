<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:150'],
            'category'    => ['required', 'string', 'max:100'],
            'phone'       => ['required', 'string', 'max:30'],
            'address'     => ['nullable', 'string', 'max:255'],
            'city'        => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'whatsapp'    => ['nullable', 'string', 'max:30'],
            'instagram'   => ['nullable', 'string', 'max:100'],
            'facebook'    => ['nullable', 'string', 'max:150'],
            'tiktok'      => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Le nom du commerce est obligatoire.',
            'category.required' => 'La catégorie est obligatoire.',
            'phone.required'    => 'Le téléphone est obligatoire.',
        ];
    }
}
