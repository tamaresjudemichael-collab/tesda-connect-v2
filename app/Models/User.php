<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'role', 'account_status', 'last_login_at', 'password_expiry_exempt', 'password_expires_at', 'force_password_change'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_expiry_exempt' => 'boolean',
            'password_expires_at' => 'datetime',
            'force_password_change' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return BelongsToMany<Institution, $this> */
    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function learnerProfile(): HasOne
    {
        return $this->hasOne(LearnerProfile::class);
    }

    /** @return HasMany<LearnerDocument, $this> */
    public function learnerDocuments(): HasMany
    {
        return $this->hasMany(LearnerDocument::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

   
    public function roleLabel(): string
    {
        return match ($this->role) {
            'administrator' => 'System Administrator',
            'registrar' => 'Registrar',
            'focal_person' => 'UTPRAS / Focal Person',
            'applicant' => 'Enrollment Applicant',
            'scholar' => 'Scholar / Student',
            default => 'Unknown Role',
        };
    }

}
