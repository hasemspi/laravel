<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $fillable = ['course_module_id', 'title', 'description', 'thumbnail_path', 'video_reference', 'duration_seconds', 'unlock_type', 'unlock_at', 'unlock_delay_hours', 'sort_order', 'is_published'];
    protected function casts(): array { return ['unlock_at' => 'datetime', 'is_published' => 'boolean']; }
    public function module(): BelongsTo { return $this->belongsTo(CourseModule::class, 'course_module_id'); }
}
