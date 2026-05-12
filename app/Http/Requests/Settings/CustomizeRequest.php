<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'color_face' => ['required', 'numeric', 'min:0', 'max:62'],
            'color_body' => ['required', 'numeric', 'min:0', 'max:62'],
            'color_limb' => ['required', 'numeric', 'min:0', 'max:62'],
        ];
    }
}
