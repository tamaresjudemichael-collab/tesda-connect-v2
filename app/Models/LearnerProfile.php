<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearnerProfile extends Model
{
    protected $fillable = [
        'user_id', 'institution_id', 'uli_number', 'entry_date', 'last_name', 'first_name',
        'middle_name', 'extension_name', 'address_line', 'barangay', 'district', 'city_municipality',
        'province', 'region', 'contact_number', 'nationality', 'sex', 'civil_status',
        'employment_status', 'employment_type', 'birthdate', 'birthplace_city', 'birthplace_province',
        'birthplace_region', 'educational_attainment', 'guardian_name', 'guardian_address',
        'classifications', 'disability_types', 'disability_cause', 'course_id', 'scholarship_package',
        'privacy_consent', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'birthdate' => 'date',
            'classifications' => 'array',
            'disability_types' => 'array',
            'privacy_consent' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LearnerDocument::class);
    }
}
