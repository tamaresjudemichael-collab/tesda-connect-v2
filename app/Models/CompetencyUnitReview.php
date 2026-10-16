<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyUnitReview extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'competency_unit_id',
        'status',
        'verified_by',
        'verified_at',
        'is_locked',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_application_id' => 'integer',
            'competency_unit_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'is_locked' => 'boolean',
        ];
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function competencyUnit(): BelongsTo
    {
        return $this->belongsTo(CompetencyUnit::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}