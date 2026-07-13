<?php

namespace App\Http\Requests\Settings;

use App\Enums\TaikoGameVersion;
use App\Models\PlayerCosmetic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDonChanTitleRequest extends FormRequest
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
        $rules = [
            'title' => ['nullable', 'string', 'max:255', 'not_regex:/[\x{0000}-\x{001F}\x{007F}]/u'],
        ];

        $version = $this->attributes->get('taikoGameVersion');
        if ($version instanceof TaikoGameVersion && $version->supportsTitlePlates()) {
            $rules['titleplate_id'] = [
                'required',
                'integer',
                'min:0',
                'max:'.PlayerCosmetic::CUSTOM_TITLE_PLATE_MAX_ID,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.not_regex' => __('The title cannot contain line breaks or control characters.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title') && is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->string('title')->toString())]);
        }
    }
}
