<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = ['course_category_id', 'title', 'slug', 'short_description', 'description', 'price', 'discount_price', 'level', 'duration_minutes', 'thumbnail_path', 'benefits', 'status', 'is_featured', 'display_student_count', 'display_rating', 'display_review_count'];
    protected function casts(): array { return ['price' => 'decimal:2', 'discount_price' => 'decimal:2', 'display_rating' => 'decimal:1', 'benefits' => 'array', 'is_featured' => 'boolean']; }
    public function category(): BelongsTo { return $this->belongsTo(CourseCategory::class, 'course_category_id'); }
    public function trainers(): BelongsToMany { return $this->belongsToMany(User::class, 'course_trainer')->withTimestamps(); }
    public function modules(): HasMany { return $this->hasMany(CourseModule::class); }
    public function batches(): HasMany { return $this->hasMany(CourseBatch::class); }
    public function accessRule(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(CourseAccessRule::class); }
    public function enrollments(): HasMany { return $this->hasMany(Enrollment::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function ratings(): HasMany { return $this->hasMany(CourseRating::class); }
}
