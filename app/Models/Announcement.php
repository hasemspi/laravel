<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    public const TYPES = ['general', 'blog', 'notice', 'video'];
    public const AUDIENCES = ['students', 'trainers', 'course'];
    public const STATUSES = ['draft', 'published'];

    protected $fillable = ['title', 'type', 'audience_type', 'course_id', 'description', 'external_url', 'status'];

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function reads(): HasMany { return $this->hasMany(AnnouncementRead::class); }
}
