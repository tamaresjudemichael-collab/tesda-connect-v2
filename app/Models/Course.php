<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'institution_id', 'code', 'name', 'qualification_level', 'training_hours', 'is_active',
        'description', 'requirements', 'curriculum_reference',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(TrainingBatch::class);
    }

    public function competencyUnits(): HasMany
{
    return $this->hasMany(CompetencyUnit::class)->orderBy('sort_order');
}
}
