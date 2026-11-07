<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    protected $table = 'lesson_progress';
    protected $fillable = ['user_id', 'course_id', 'module_id', 'lesson_id', 'watched_seconds', 'video_duration_seconds', 'progress_percent', 'is_completed', 'last_watched_at', 'completed_at'];
    protected function casts(): array { return ['is_completed' => 'boolean', 'last_watched_at' => 'datetime', 'completed_at' => 'datetime']; }
}
