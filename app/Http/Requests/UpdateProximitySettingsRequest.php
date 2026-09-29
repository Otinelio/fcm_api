<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProximitySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'radius_m' => 'required|integer|min:50|max:5000',
            'title' => 'required_if:enabled,true|nullable|string|max:100',
            'message' => 'required_if:enabled,true|nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required_if' => 'Le titre de la notification est requis lorsque la fonctionnalité est activée.',
            'message.required_if' => 'Le message de la notification est requis lorsque la fonctionnalité est activée.',
            'radius_m.min' => 'Le rayon minimum est de 50 mètres.',
            'radius_m.max' => 'Le rayon maximum est de 5 000 mètres.',
        ];
    }
}
