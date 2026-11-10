<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainerProfile extends Model
{
    protected $fillable = [
        'user_id', 'professional_title', 'bio', 'professional_experience', 'experience_years',
        'expertise', 'skills', 'teaching_subjects', 'current_job', 'company', 'designation',
        'education', 'certifications', 'awards', 'youtube_intro_url', 'facebook_url',
        'linkedin_url', 'youtube_url', 'website_url', 'languages', 'portfolio_projects',
        'is_verified', 'verified_at', 'verified_by_id',
    ];

    protected function casts(): array
    {
        return [
            'expertise' => 'array',
            'skills' => 'array',
            'teaching_subjects' => 'array',
            'education' => 'array',
            'certifications' => 'array',
            'awards' => 'array',
            'languages' => 'array',
            'portfolio_projects' => 'array',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
