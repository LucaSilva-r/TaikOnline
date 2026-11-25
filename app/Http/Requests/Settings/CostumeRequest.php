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
            // Make (costume_4) is optional: a missing one saves as 0.
            $rules["presets.*.{$key}"] = [$key === 'costume_4' ? 'sometimes' : 'required', 'integer', 'min:0'];
        }

        return $rules;
    }
}
