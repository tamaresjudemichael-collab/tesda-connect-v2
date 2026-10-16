<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetencyUnit extends Model
{
    protected $fillable = [
        'course_id',
        'unit_number',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'unit_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CompetencyTask::class)->orderBy('sort_order');
    }
    public function reviews(): HasMany
{
    return $this->hasMany(CompetencyUnitReview::class);
}
}