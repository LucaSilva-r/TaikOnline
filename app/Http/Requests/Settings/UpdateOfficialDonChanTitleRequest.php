<?php

namespace App\Http\Requests\Settings;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\TaikoTitleCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficialDonChanTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(TaikoTitleCatalog $catalog): array
    {
        $version = $this->attributes->get('taikoGameVersion');
        $titleRules = ['bail', 'required', 'integer'];

        if ($version instanceof TaikoGameVersion && $version->supportsCostumeSlots()) {
            $titleRules[] = Rule::in($catalog->ids($version));
        }

        return [
            'title_id' => $titleRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title_id.in' => __('That title is not available in this game version.'),
        ];
    }
}
