<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyReportSubmission extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'finalized_by',
        'finalized_at',
        'submitted_by',
        'submitted_at',
        'submission_reference',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'finalized_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}