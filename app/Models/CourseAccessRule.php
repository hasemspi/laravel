<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAccessRule extends Model
{
    protected $fillable = ['course_id', 'lifetime_access_enabled', 'future_batch_access', 'release_rule'];

    protected function casts(): array
    {
        return ['lifetime_access_enabled' => 'boolean', 'future_batch_access' => 'boolean'];
    }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
}
