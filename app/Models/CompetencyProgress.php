<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyProgress extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'competency_task_id',
        'status',
        'remarks',
        'assessed_by',
        'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_application_id' => 'integer',
            'competency_task_id' => 'integer',
            'assessed_by' => 'integer',
            'assessed_at' => 'datetime',
        ];
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function competencyTask(): BelongsTo
    {
        return $this->belongsTo(CompetencyTask::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}