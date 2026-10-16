<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingBatch extends Model
{
    protected $fillable = [
        'institution_id', 'course_id', 'code', 'name', 'capacity', 'starts_at', 'ends_at', 'next_class_at', 'venue', 'status',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'next_class_at' => 'datetime'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(EnrollmentApplication::class);
    }
}
