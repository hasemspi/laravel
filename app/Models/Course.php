<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['course_category_id', 'title', 'slug', 'short_description', 'description', 'price', 'duration_minutes', 'thumbnail_path', 'benefits', 'status'];
    protected function casts(): array { return ['price' => 'decimal:2', 'benefits' => 'array']; }
    public function category(): BelongsTo { return $this->belongsTo(CourseCategory::class, 'course_category_id'); }
    public function trainers(): BelongsToMany { return $this->belongsToMany(User::class, 'course_trainer')->withTimestamps(); }
    public function modules(): HasMany { return $this->hasMany(CourseModule::class); }
    public function enrollments(): HasMany { return $this->hasMany(Enrollment::class); }
}
