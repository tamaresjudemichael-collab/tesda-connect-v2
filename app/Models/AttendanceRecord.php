<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'attendance_date',
        'status',
        'remarks',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_application_id' => 'integer',
            'recorded_by_user_id' => 'integer',
            'attendance_date' => 'date',
        ];
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
