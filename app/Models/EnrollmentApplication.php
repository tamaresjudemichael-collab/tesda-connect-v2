<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentApplication extends Model
{
    protected $fillable = [
        'reference_no', 'learner_user_id', 'institution_id', 'course_id', 'training_batch_id',
        'learner_name', 'email', 'contact_number', 'application_status', 'record_completeness',
        'submitted_at', 'verified_at', 'confirmed_at', 'scholar_id', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'record_completeness' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
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
}
