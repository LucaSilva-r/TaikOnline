<?php

namespace App\Http\Requests\Settings;

use App\Models\PlayerCosmetic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CostumeRequest extends FormRequest
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
        $count = PlayerCosmetic::PRESET_COUNT;

        $rules = [
            'active_preset' => ['required', 'integer', 'min:0', 'max:'.($count - 1)],
            'presets' => ['required', 'array', 'size:'.$count],
        ];

        foreach (PlayerCosmetic::PRESET_KEYS as $key) {
            $rules["presets.*.{$key}"] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }
}
