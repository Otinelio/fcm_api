<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoyaltyProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode'                    => ['required', 'string', 'in:stamps,points,spend'],
            'goal'                    => ['required', 'integer', 'min:1'],
            'reward_description'      => ['nullable', 'string', 'max:255'],
            'show_review_button'      => ['sometimes', 'boolean'],
            'google_review_url'       => ['nullable', 'string', 'max:255'],
            'color_primary'           => ['required', 'string', 'max:9'],
            'color_secondary'         => ['required', 'string', 'max:9'],
            'stamp_design_type'       => ['required', 'string', 'in:check,icon,emoji'],
            'stamp_emoji'             => ['nullable', 'string', 'max:10'],
            'stamp_icon'              => ['nullable', 'string', 'max:50'],
            'card_decoration_pattern' => ['nullable', 'string', 'max:50'],
            'card_gradient_type'      => ['nullable', 'string', 'max:50'],
            'logo_url'                => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.required' => 'Le mode de récompense est obligatoire.',
            'mode.in'       => 'Mode de récompense invalide.',
            'goal.required' => 'L\'objectif est obligatoire.',
        ];
    }
}
