<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDonChanNameRequest extends FormRequest
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
            'mydon_name' => ['bail', 'required', 'string', 'max:5', 'regex:/\A\p{Hiragana}+\z/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mydon_name.required' => __('Enter a DonChan name.'),
            'mydon_name.max' => __('The DonChan name must not be more than 5 characters.'),
            'mydon_name.regex' => __('The DonChan name may only contain hiragana.'),
        ];
    }
}
