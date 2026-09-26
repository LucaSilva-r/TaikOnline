<?php

namespace App\Http\Requests\Admin;

use App\Models\Player;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BaidAccessCodeReplaceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Player $player */
        $player = $this->route('player');

        return [
            'access_code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cards', 'access_code')->where(
                    fn ($query) => $query->where('baid', '!=', $player->baid)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_code.unique' => __('That access code is already registered to another BAID.'),
        ];
    }
}
