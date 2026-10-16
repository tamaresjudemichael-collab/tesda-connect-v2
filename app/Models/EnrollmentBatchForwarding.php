<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnrollmentBatchForwarding extends Model
{
    protected $fillable = [
        'reference_code', 'institution_id', 'course_id', 'training_batch_id',
        'forwarded_by_user_id', 'forwarded_at', 'status', 'application_count',
        'summary_path', 'summary_filename',
    ];

    protected function casts(): array
    {
        return [
            'forwarded_at' => 'datetime',
            'application_count' => 'integer',
        ];
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

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(EnrollmentApplication::class, 'forwarding_id');
    }
}
