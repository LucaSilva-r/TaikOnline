<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * Username is intentionally excluded: it is immutable after registration
     * and only validated during user creation via {@see usernameRules()}.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules($userId),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate usernames (creation only).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function usernameRules(): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:30',
            'alpha_dash',
            Rule::unique(User::class),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(?int $userId = null): array
    {
        return ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($userId): void {
            // Only a new or changed name must fit: an existing longer one stays saveable (e-mail, role edits).
            if (is_string($value) && self::nameWidth($value) > self::MAX_NAME_WIDTH
                && ($userId === null || User::query()->whereKey($userId)->value('name') !== $value)) {
                $fail(__('The name is too long to fit on the game\'s name board.'));
            }
        }];
    }

    /**
     * Waddamburo's name board fits "[LAWN] Red" (182.4): about 7 Japanese characters, more narrow
     * letters (i, l), fewer wide ones (W, M). Units: the game font's advances at the board's size.
     */
    public const MAX_NAME_WIDTH = 183;

    /** Advances of printable ASCII (U+0020-U+007E) in the game's board font; anything else is a kana's. */
    private const NAME_ADVANCES = [7.8, 11.3, 9.9, 21.1, 15.7, 21.9, 20.7, 8.2, 13.9, 13.9, 18.8, 20.2, 9.1, 20.6, 8.8, 18.6, 17.6, 13.1, 18.5, 17.5, 19.3, 17.6, 18.2, 18.0, 18.3, 17.5, 14.3, 11.6, 13.6, 18.1, 13.6, 19.8, 20.9, 19.5, 18.4, 18.1, 19.0, 17.6, 17.8, 19.0, 19.1, 9.0, 15.0, 19.6, 17.5, 23.2, 20.8, 18.7, 17.7, 18.6, 18.3, 17.0, 18.5, 18.5, 19.7, 24.9, 18.4, 19.7, 19.3, 14.8, 19.1, 14.8, 12.0, 13.7, 9.9, 15.2, 16.0, 14.0, 15.7, 15.3, 14.6, 17.5, 15.8, 8.5, 9.8, 14.9, 8.5, 22.1, 15.8, 15.4, 15.6, 15.2, 13.2, 13.8, 13.5, 16.5, 16.4, 23.3, 15.4, 16.2, 15.6, 13.1, 8.1, 13.1, 11.0];

    private const NAME_WIDE_ADVANCE = 22.0;

    /** Letter spacing the board's name lettering adds between characters. */
    private const NAME_GAP = 1.44;

    /** The name's drawn width on the game's name board (see {@see MAX_NAME_WIDTH}). */
    public static function nameWidth(string $name): float
    {
        $width = 0.0;
        $characters = mb_str_split($name);
        foreach ($characters as $character) {
            $code = mb_ord($character);
            $width += $code >= 0x20 && $code <= 0x7E ? self::NAME_ADVANCES[$code - 0x20] : self::NAME_WIDE_ADVANCE;
        }

        return $width + self::NAME_GAP * max(0, count($characters) - 1);
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
