<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnerDocument extends Model
{
    protected $fillable = [
        'user_id', 'learner_profile_id', 'document_type', 'original_name', 'path',
        'mime_type', 'size', 'status', 'reviewed_by_user_id', 'reviewed_at', 'remarks',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class, 'learner_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
