<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchModule extends Model
{
    protected $fillable = ['course_batch_id', 'title', 'description', 'sort_order'];

    public function batch(): BelongsTo { return $this->belongsTo(CourseBatch::class, 'course_batch_id'); }
    public function lessons(): HasMany { return $this->hasMany(BatchLesson::class); }
}
