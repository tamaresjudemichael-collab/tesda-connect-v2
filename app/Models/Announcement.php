<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = [
        'institution_id',
        'author_user_id',
        'title',
        'body',
        'audience',
        'published_at',
        'deadline_at',
        'expires_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'deadline_at' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(AnnouncementView::class);
    }
}
