<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeCourseQuota extends Model
{
    protected $fillable = ['intake_period_id', 'course_id', 'quota', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['quota' => 'integer'];
    }

    public function intakePeriod(): BelongsTo
    {
        return $this->belongsTo(IntakePeriod::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
