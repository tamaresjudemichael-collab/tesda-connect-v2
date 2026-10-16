<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EnrollmentApplication extends Model
{
    protected $fillable = [
        'reference_no', 'learner_user_id', 'institution_id', 'course_id', 'training_batch_id',
        'learner_name', 'email', 'contact_number', 'application_status', 'record_completeness',
        'submitted_at', 'verified_at', 'confirmed_at', 'scholar_id', 'remarks',
        'archived_at', 'archived_by_user_id', 'archive_reason', 'forwarding_id',
    ];

    protected function casts(): array
    {
        return [
            'record_completeness' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_user_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function trainingBatch(): BelongsTo
    {
        return $this->belongsTo(TrainingBatch::class);
    }

    public function competencyProgress(): HasMany
{
    return $this->hasMany(CompetencyProgress::class);
}

public function competencyUnitReviews(): HasMany
{
    return $this->hasMany(CompetencyUnitReview::class);
}

    public function competencyReportSubmission(): HasOne
    {
        return $this->hasOne(CompetencyReportSubmission::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(EnrollmentStatusHistory::class)->latest();
    }

    public function forwarding(): BelongsTo
    {
        return $this->belongsTo(EnrollmentBatchForwarding::class, 'forwarding_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }
}
