<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The image arrives as a base64 PNG data URL produced by the Three.js viewer's
     * canvas.toDataURL(). Cap the raw string so an oversized payload is rejected
     * before we attempt to decode it; the controller re-encodes via GD afterwards.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'string',
                'starts_with:data:image/png;base64,',
                'max:2000000',
            ],
            'costume' => ['required', 'integer', 'min:0'],
            'color_face' => ['required', 'integer', 'min:0', 'max:62'],
            'color_body' => ['required', 'integer', 'min:0', 'max:62'],
            'color_limb' => ['required', 'integer', 'min:0', 'max:62'],
            'face' => ['nullable', 'string', 'max:64'],
            'face_frame' => ['required', 'integer', 'min:0', 'max:11'],
        ];
    }
}
