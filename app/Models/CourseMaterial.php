<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseMaterial extends Model
{
    protected $fillable = [
        'course_id', 'uploaded_by_user_id', 'title', 'description', 'original_name',
        'path', 'mime_type', 'size_bytes', 'is_published',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
