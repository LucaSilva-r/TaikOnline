<?php

namespace App\Http\Requests\Settings;

use App\Models\GameCard;
use App\Models\Player;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AccessCodeBindRequest extends FormRequest
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
        $userId = $this->user()->id;

        return [
            'access_code' => [
                'required',
                'string',
                'exists:cards,access_code',
                function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                    $card = GameCard::query()->with('player')->find($value);

                    if ($card === null) {
                        return;
                    }

                    $boundUserId = $card->player?->user_id;

                    if ($boundUserId !== null && $boundUserId !== $userId) {
                        $fail(__('This access code is already linked to another account.'));
                    }
                },
                function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                    $existing = Player::query()->where('user_id', $userId)->exists();

                    if ($existing) {
                        $fail(__('Your account is already linked to an access code. Unbind it first.'));
                    }
                },
            ],
        ];
    }
}
