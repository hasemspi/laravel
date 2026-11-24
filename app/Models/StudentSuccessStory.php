<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentSuccessStory extends Model
{
    /** @use HasFactory<\Database\Factories\StudentSuccessStoryFactory> */
    use HasFactory;

    protected $fillable = [
        'student_name', 'designation', 'category', 'description',
        'achievement_label', 'achievement_value', 'video_type', 'video_path',
        'youtube_url', 'thumbnail_path', 'social_links', 'video_duration',
        'display_order', 'is_featured', 'status',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'display_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
