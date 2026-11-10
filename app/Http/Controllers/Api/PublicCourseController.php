<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:48']]);
        $courses = Course::query()->where('status', 'published')->withAvg(['ratings as approved_positive_average_rating' => fn ($query) => $query->approvedPositive()->verifiedEnrolledStudent()], 'rating')->withCount(['ratings as approved_positive_reviews_count' => fn ($query) => $query->approvedPositive()->verifiedEnrolledStudent()])->withCount(['enrollments as verified_enrollments_count' => fn ($query) => $query->whereIn('status', ['active', 'completed'])])->with(['category:id,name,slug', 'trainers:id,name,profile_image_path', 'trainers.trainerProfile'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('short_description', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas('category', fn ($q) => $q->where('slug', $category)))
            ->latest()->paginate($filters['per_page'] ?? 12);

        $courses->getCollection()->transform(function (Course $course) {
            $course->setAttribute('average_rating', round((float) ($course->approved_positive_average_rating ?? 0), 1));
            $course->setAttribute('total_reviews', (int) ($course->approved_positive_reviews_count ?? 0));
            $course->setAttribute('total_students', (int) ($course->display_student_count ?? ($course->verified_enrollments_count ?: 890)));
            $course->setRelation('trainers', $course->trainers->map(fn ($trainer) => $this->trainerSummary($trainer, $course))->values());

            return $course;
        });

        return response()->json($courses);
    }

    public function show(Course $course): JsonResponse
    {
        abort_unless($course->status === 'published', 404);
        $course->load(['category:id,name,slug', 'trainers:id,name,profile_image_path', 'trainers.trainerProfile', 'modules' => fn ($q) => $q->orderBy('sort_order'), 'modules.lessons' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')]);
        $course->loadAvg(['ratings as approved_positive_average_rating' => fn ($query) => $query->approvedPositive()->verifiedEnrolledStudent()], 'rating')->loadCount(['ratings as approved_positive_reviews_count' => fn ($query) => $query->approvedPositive()->verifiedEnrolledStudent()])->loadCount(['enrollments as verified_enrollments_count' => fn ($query) => $query->whereIn('status', ['active', 'completed'])]);

        return response()->json(['course' => $this->courseData($course)]);
    }

    public function reviews(Request $request, Course $course): JsonResponse
    {
        abort_unless($course->status === 'published', 404);

        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'in:10'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $reviewQuery = CourseRating::query()
            ->select(['id', 'course_id', 'user_id', 'rating', 'review', 'created_at'])
            ->whereBelongsTo($course)
            ->approvedPositive()
            ->verifiedEnrolledStudent()
            ->with('user:id,name,profile_image_path')
            ->latest('created_at');
        $reviews = $reviewQuery->paginate($filters['per_page'] ?? 10)->withQueryString();

        $summary = CourseRating::query()->whereBelongsTo($course)->approvedPositive()->verifiedEnrolledStudent();

        return response()->json(array_merge($reviews->toArray(), [
            'average_rating' => round((float) $summary->clone()->avg('rating'), 1),
            'total_reviews' => (int) $summary->count(),
        ]));
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
            'thumbnail_path' => $course->thumbnail_path, 'benefits' => $course->benefits, 'category' => $course->category,
            'trainers' => $course->trainers->map(fn ($trainer) => $this->trainerSummary($trainer, $course))->values(),
            'average_rating' => round((float) ($course->approved_positive_average_rating ?? 0), 1),
            'total_reviews' => (int) ($course->approved_positive_reviews_count ?? 0),
            'total_students' => (int) ($course->display_student_count ?? ($course->verified_enrollments_count ?: 890)),
            'modules' => $course->modules->map(fn ($module) => ['id' => $module->id, 'title' => $module->title, 'description' => $module->description, 'lessons' => $module->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'duration_seconds' => $lesson->duration_seconds, 'thumbnail_path' => $lesson->thumbnail_path])]),
        ];
    }

    private function trainerSummary($trainer, Course $course): array
    {
        $profile = $trainer->trainerProfile;

        return [
            'id' => $trainer->id,
            'name' => $trainer->name,
            'profile_image_url' => $this->imageUrl($trainer->profile_image_path),
            'professional_title' => $profile?->professional_title,
            'bio' => $profile?->bio ? Str::limit($profile->bio, 180) : null,
            'expertise' => $profile?->expertise ?? [],
            'experience_years' => $profile?->experience_years,
            'youtube_intro_url' => $profile?->youtube_intro_url,
            'social_links' => collect([
                'facebook' => $profile?->facebook_url,
                'linkedin' => $profile?->linkedin_url,
                'youtube' => $profile?->youtube_url,
                'website' => $profile?->website_url,
            ])->filter()->all(),
            'is_verified' => (bool) ($profile?->is_verified ?? false),
            'rating' => round((float) ($course->average_rating ?? $course->approved_positive_average_rating ?? 0), 1),
            'profile_url' => '/trainers/'.$trainer->id,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url('/'.ltrim($path, '/'));
    }
}
