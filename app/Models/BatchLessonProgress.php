<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchLessonProgress extends Model
{
    protected $table = 'batch_lesson_progress';
    protected $fillable = ['user_id', 'enrollment_id', 'course_batch_id', 'batch_lesson_id', 'watched_seconds', 'video_duration_seconds', 'progress_percent', 'is_completed', 'last_watched_at', 'completed_at'];

    protected function casts(): array
    {
        return ['is_completed' => 'boolean', 'last_watched_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lesson(): BelongsTo { return $this->belongsTo(BatchLesson::class, 'batch_lesson_id'); }
    public function enrollment(): BelongsTo { return $this->belongsTo(Enrollment::class); }
    public function batch(): BelongsTo { return $this->belongsTo(CourseBatch::class, 'course_batch_id'); }
}
