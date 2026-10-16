<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Collection;

class InstitutionScope
{
    /**
     * Administrators oversee all institutions. Other staff are limited to
     * explicitly assigned institutions.
     *
     * @return Collection<int, int|string>
     */
    public static function idsFor(User $user): Collection
    {
        if ($user->hasRole('administrator')) {
            return Institution::query()->orderBy('id')->pluck('id');
        }

        return $user->institutions()->pluck('institutions.id');
    }
}
