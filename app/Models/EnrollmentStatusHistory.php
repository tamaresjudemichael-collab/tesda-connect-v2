<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentStatusHistory extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'reference_no',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
    ];

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
