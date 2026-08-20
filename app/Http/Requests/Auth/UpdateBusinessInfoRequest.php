<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantId = $this->user()?->id;

        return [
            'name'        => ['required', 'string', 'max:150', Rule::unique('restaurants', 'name')->ignore($restaurantId)],
            'category'    => ['required', 'string', 'max:100'],
            'phone'       => ['required', 'string', 'max:30', Rule::unique('restaurants', 'phone')->ignore($restaurantId)],
            'address'     => ['nullable', 'string', 'max:255'],
            'city'        => ['nullable', 'string', 'max:100'],
            'country'     => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'whatsapp'    => ['nullable', 'string', 'max:30'],
            'instagram'   => ['nullable', 'string', 'max:100'],
            'facebook'    => ['nullable', 'string', 'max:150'],
            'tiktok'      => ['nullable', 'string', 'max:100'],
            'latitude'    => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Le nom du commerce est obligatoire.',
            'name.unique'       => 'Ce nom de commerce est déjà utilisé.',
            'category.required' => 'La catégorie est obligatoire.',
            'phone.required'    => 'Le téléphone est obligatoire.',
            'phone.unique'      => 'Ce numéro de téléphone est déjà utilisé par un autre commerce.',
        ];
    }
}

