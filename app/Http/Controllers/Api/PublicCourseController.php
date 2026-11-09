<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:48']]);
        $courses = Course::query()->where('status', 'published')->withAvg('ratings', 'rating')->withCount('ratings')->withCount(['enrollments as verified_enrollments_count' => fn ($query) => $query->whereIn('status', ['active', 'completed'])])->with(['category:id,name,slug', 'trainers:id,name'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('short_description', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas('category', fn ($q) => $q->where('slug', $category)))
            ->latest()->paginate($filters['per_page'] ?? 12);

        $courses->getCollection()->transform(function (Course $course) {
            $hasRealRatings = (int) ($course->ratings_count ?? 0) > 0;
            $course->setAttribute('average_rating', $hasRealRatings ? round((float) $course->ratings_avg_rating, 1) : (float) ($course->display_rating ?? 0));
            $course->setAttribute('total_reviews', $hasRealRatings ? (int) $course->ratings_count : (int) ($course->display_review_count ?? 0));
            $course->setAttribute('total_students', (int) ($course->display_student_count ?? ($course->verified_enrollments_count ?: 890)));
            return $course;
        });

        return response()->json($courses);
    }

    public function show(Course $course): JsonResponse
    {
        abort_unless($course->status === 'published', 404);
        $course->load(['category:id,name,slug', 'trainers:id,name', 'modules' => fn ($q) => $q->orderBy('sort_order'), 'modules.lessons' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')]);
        $course->loadAvg('ratings', 'rating')->loadCount('ratings')->loadCount(['enrollments as verified_enrollments_count' => fn ($query) => $query->whereIn('status', ['active', 'completed'])]);
        return response()->json(['course' => $this->courseData($course)]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['categories' => CourseCategory::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug', 'description', 'icon', 'status'])]);
    }

    private function courseData(Course $course): array
    {
        return [
            'id' => $course->id, 'title' => $course->title, 'slug' => $course->slug, 'short_description' => $course->short_description,
            'description' => $course->description, 'price' => $course->price, 'discount_price' => $course->discount_price,
            'level' => $course->level, 'is_featured' => $course->is_featured, 'duration_minutes' => $course->duration_minutes,
            'thumbnail_path' => $course->thumbnail_path, 'benefits' => $course->benefits, 'category' => $course->category, 'trainers' => $course->trainers,
            'average_rating' => (int) ($course->ratings_count ?? 0) > 0 ? round((float) $course->ratings_avg_rating, 1) : (float) ($course->display_rating ?? 0),
            'total_reviews' => (int) ($course->ratings_count ?? 0) > 0 ? (int) $course->ratings_count : (int) ($course->display_review_count ?? 0),
            'total_students' => (int) ($course->display_student_count ?? ($course->verified_enrollments_count ?: 890)),
            'modules' => $course->modules->map(fn ($module) => ['id' => $module->id, 'title' => $module->title, 'description' => $module->description, 'lessons' => $module->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'duration_seconds' => $lesson->duration_seconds, 'thumbnail_path' => $lesson->thumbnail_path])]),
        ];
    }
}
