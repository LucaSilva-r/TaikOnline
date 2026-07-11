<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreExtraSongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'edition' => ['nullable', 'string', 'max:255'],
            'charts' => ['required', 'array'],
            'charts.*' => ['nullable', 'file', 'max:4096'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = collect($this->file('charts', []))->filter();
            if ($files->isEmpty()) {
                $validator->errors()->add('charts', 'Upload at least one fumen file.');
            }

            foreach ($files as $difficulty => $file) {
                if (! in_array((int) $difficulty, [1, 2, 3, 4, 5], true)) {
                    $validator->errors()->add("charts.{$difficulty}", 'Unknown difficulty slot.');
                }
                if (strtolower($file->getClientOriginalExtension()) !== 'bin') {
                    $validator->errors()->add("charts.{$difficulty}", 'The fumen must use the .bin extension.');
                }
            }
        }];
    }
}
