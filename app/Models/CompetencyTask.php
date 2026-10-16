<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetencyTask extends Model
{
    protected $fillable = [
        'competency_unit_id',
        'chart_type',
        'task_number',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'competency_unit_id' => 'integer',
            'task_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function competencyUnit(): BelongsTo
    {
        return $this->belongsTo(CompetencyUnit::class);
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(CompetencyProgress::class);
    }
}