<?php

namespace App\Http\Requests\Admin;

use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserAccessCodeBindRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');
        $targetId = $target->id;

        return [
            'access_code' => [
                'required',
                'string',
                'exists:cards,access_code',
                function (string $attribute, mixed $value, Closure $fail) use ($targetId): void {
                    $card = GameCard::query()->with('player')->find($value);

                    if ($card === null) {
                        return;
                    }

                    $boundUserId = $card->player?->user_id;

                    if ($boundUserId !== null && $boundUserId !== $targetId) {
                        $fail(__('This access code is already linked to another account.'));
                    }
                },
                function (string $attribute, mixed $value, Closure $fail) use ($targetId): void {
                    $existing = Player::query()->where('user_id', $targetId)->exists();

                    if ($existing) {
                        $fail(__('This user is already linked to an access code. Unbind it first.'));
                    }
                },
            ],
        ];
    }
}
