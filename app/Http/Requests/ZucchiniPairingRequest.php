<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ZucchiniPairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'session' => ['nullable', 'string', 'alpha_num', 'size:64'],
            'cabinet_id' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{8}\z/'],
            'state' => ['required', 'string', 'max:32'],
            'accepting' => ['required', 'boolean'],
            'ack' => ['nullable', 'uuid'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response("invalid request\n", 422, ['Content-Type' => 'text/plain; charset=utf-8'])
        );
    }
}
