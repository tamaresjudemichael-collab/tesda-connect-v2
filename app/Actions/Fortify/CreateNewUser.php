<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param array<string, string> $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'institution_id' => [
                'required',
                'integer',
                Rule::exists('institutions', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => 'applicant',
                'account_status' => 'active',
            ]);

            // Users and institutions are connected through the institution_user pivot table.
            $user->institutions()->attach((int) $input['institution_id'], [
                'is_primary' => true,
            ]);

            return $user;
        });
    }
}
