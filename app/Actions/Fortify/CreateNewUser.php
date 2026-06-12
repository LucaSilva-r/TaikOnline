<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessCodeOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly AccessCodeOwnershipService $accessCodes) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['username'] = Str::lower($input['username'] ?? '');

        Validator::make($input, [
            ...$this->profileRules(),
            'username' => $this->usernameRules(),
            'access_code' => ['nullable', 'string', 'exists:cards,access_code'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'username' => $input['username'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => User::count() === 0 ? UserRole::Admin : UserRole::User,
            ]);

            if (! empty($input['access_code'])) {
                $this->accessCodes->claim($user, $input['access_code']);
            }

            return $user;
        }, attempts: 3);
    }
}
