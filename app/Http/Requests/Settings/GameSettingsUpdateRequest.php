<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Shared profile settings
            'prefecture_id' => ['required', 'integer', 'min:0', 'max:47'],
            'is_publish' => ['required', 'boolean'],
            // Only present from Murasaki onward (in-arcade ranking display difficulty).
            'disp_score_type' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'disp_dan_type' => ['required', 'integer', 'min:0', 'max:1'],
            'difficulty_played_course' => ['required', 'integer', Rule::in([0, 1, 2, 3, 4, 5, 99])],
            'difficulty_played_star' => ['required', 'integer', Rule::in([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 99])],
            'difficulty_played_sort' => ['required', 'integer', Rule::in([0, 1, 2, 3, 4, 99])],

            // Version-specific settings. Tone is Murasaki onward; enso options are
            // Momoiro onward, so all are optional and gated server-side by version.
            'default_tone_setting' => ['sometimes', 'integer', 'min:0'],
            'sync_play_options' => ['sometimes', 'boolean'],
            'sync_tone_settings' => ['sometimes', 'boolean'],
            'speed' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'doron' => ['sometimes', 'integer', 'min:0', 'max:1'],
            'abekobe' => ['sometimes', 'integer', 'min:0', 'max:1'],
            'random' => ['sometimes', 'integer', 'min:0', 'max:2'],
        ];
    }
}
