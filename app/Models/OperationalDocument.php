<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalDocument extends Model
{
    protected $fillable = [
        'institution_id', 'uploaded_by_user_id', 'course_id', 'training_batch_id',
        'enrollment_application_id', 'category', 'title', 'original_name', 'path',
        'mime_type', 'size', 'notes',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function institution(): BelongsTo { return $this->belongsTo(Institution::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function trainingBatch(): BelongsTo { return $this->belongsTo(TrainingBatch::class); }
    public function enrollmentApplication(): BelongsTo { return $this->belongsTo(EnrollmentApplication::class); }
}
