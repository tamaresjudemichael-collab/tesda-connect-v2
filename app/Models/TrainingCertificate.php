<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingCertificate extends Model
{
    protected $fillable = [
        'enrollment_application_id',
        'certificate_number',
        'status',
        'pending_units',
        'reason',
        'issued_by_user_id',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_application_id' => 'integer',
            'pending_units' => 'array',
            'issued_by_user_id' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
