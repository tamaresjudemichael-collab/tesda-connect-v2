<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakePeriod extends Model
{
    protected $fillable = [
        'institution_id', 'code', 'name', 'starts_at', 'ends_at', 'quota', 'is_locked',
        'locked_at', 'locked_by_user_id', 'created_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'quota' => 'integer',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function courseQuotas(): HasMany
    {
        return $this->hasMany(IntakeCourseQuota::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
