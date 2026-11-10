<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseRating extends Model
{
    protected $fillable = ['course_id', 'user_id', 'rating', 'review', 'approval_status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'approved_at' => 'datetime'];
    }

    public function scopeApprovedPositive(Builder $query): void
    {
        $query->where('approval_status', 'approved')
            ->where('rating', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '');
    }

    public function scopeVerifiedEnrolledStudent(Builder $query): void
    {
        $query->whereHas('user', fn ($userQuery) => $userQuery
            ->where('role', 'student')
            ->where('status', 'active')
            ->whereHas('enrollments', fn ($enrollmentQuery) => $enrollmentQuery
                ->whereColumn('enrollments.course_id', 'course_ratings.course_id')
                ->whereIn('status', ['active', 'completed'])));
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
