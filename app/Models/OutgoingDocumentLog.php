<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingDocumentLog extends Model
{
    protected $fillable = [
        'institution_id', 'logged_by_user_id', 'document_type', 'reference_number',
        'recipient', 'subject', 'sent_at', 'method', 'notes',
    ];

    protected function casts(): array { return ['sent_at' => 'date']; }

    public function institution(): BelongsTo { return $this->belongsTo(Institution::class); }
    public function logger(): BelongsTo { return $this->belongsTo(User::class, 'logged_by_user_id'); }
}
