<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseRating;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrainerProfileController extends Controller
{
    private const PROFILE_FIELDS = [
        'professional_title', 'bio', 'professional_experience', 'experience_years', 'expertise',
        'skills', 'teaching_subjects', 'current_job', 'company', 'designation', 'education',
        'certifications', 'awards', 'youtube_intro_url', 'facebook_url', 'linkedin_url',
        'youtube_url', 'website_url', 'languages', 'portfolio_projects',
    ];

    public function index(): JsonResponse
    {
        $trainers = User::query()
            ->where('role', 'trainer')
            ->where('status', 'active')
            ->with('trainerProfile')
            ->withCount(['assignedCourses as published_courses_count' => fn ($query) => $query->where('status', 'published')])
            ->latest('name')
            ->get(['id', 'name', 'profile_image_path']);

        return response()->json([
            'trainers' => $trainers->map(fn (User $trainer) => $this->summary($trainer))->values(),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        abort_unless($user->role === 'trainer' && $user->status === 'active', 404);

        $user->load([
            'trainerProfile',
            'assignedCourses' => fn ($query) => $query
                ->where('status', 'published')
                ->with(['category:id,name,slug'])
                ->withAvg(['ratings as approved_positive_average_rating' => fn ($ratingQuery) => $ratingQuery->approvedPositive()->verifiedEnrolledStudent()], 'rating')
                ->withCount(['ratings as approved_positive_reviews_count' => fn ($ratingQuery) => $ratingQuery->approvedPositive()->verifiedEnrolledStudent()]),
        ]);

        $courseIds = $user->assignedCourses->modelKeys();
        $ratingSummary = CourseRating::query()
            ->whereIn('course_id', $courseIds ?: [0])
            ->approvedPositive()
            ->verifiedEnrolledStudent();

        return response()->json([
            'trainer' => array_merge($this->summary($user), [
                'bio' => $user->trainerProfile?->bio,
                'professional_experience' => $user->trainerProfile?->professional_experience,
                'skills' => $user->trainerProfile?->skills ?? [],
                'teaching_subjects' => $user->trainerProfile?->teaching_subjects ?? [],
                'current_job' => $user->trainerProfile?->current_job,
                'company' => $user->trainerProfile?->company,
                'designation' => $user->trainerProfile?->designation,
                'education' => $user->trainerProfile?->education ?? [],
                'certifications' => $user->trainerProfile?->certifications ?? [],
                'awards' => $user->trainerProfile?->awards ?? [],
                'languages' => $user->trainerProfile?->languages ?? [],
                'portfolio_projects' => $user->trainerProfile?->portfolio_projects ?? [],
                'rating' => round((float) ($ratingSummary->clone()->avg('rating') ?? 0), 1),
                'total_reviews' => (int) $ratingSummary->clone()->count(),
                'assigned_courses' => $user->assignedCourses->map(fn ($course) => [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'short_description' => $course->short_description,
                    'thumbnail_path' => $course->thumbnail_path,
                    'category' => $course->category,
                    'average_rating' => round((float) ($course->approved_positive_average_rating ?? 0), 1),
                    'total_reviews' => (int) ($course->approved_positive_reviews_count ?? 0),
                ])->values(),
            ]),
        ]);
    }

    public function own(Request $request): JsonResponse
    {
        $user = $request->user()->load('trainerProfile');

        return response()->json(['trainer' => $this->editData($user)]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'professional_title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'professional_experience' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
            'expertise' => ['sometimes', 'nullable', 'array', 'max:30'],
            'expertise.*' => ['string', 'max:100'],
            'skills' => ['sometimes', 'nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:100'],
            'teaching_subjects' => ['sometimes', 'nullable', 'array', 'max:30'],
            'teaching_subjects.*' => ['string', 'max:100'],
            'current_job' => ['sometimes', 'nullable', 'string', 'max:180'],
            'company' => ['sometimes', 'nullable', 'string', 'max:180'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:180'],
            'education' => ['sometimes', 'nullable', 'array', 'max:20'],
            'education.*' => ['string', 'max:300'],
            'certifications' => ['sometimes', 'nullable', 'array', 'max:20'],
            'certifications.*' => ['string', 'max:300'],
            'awards' => ['sometimes', 'nullable', 'array', 'max:20'],
            'awards.*' => ['string', 'max:300'],
            'youtube_intro_url' => ['sometimes', 'nullable', 'url', 'regex:/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i', 'max:2048'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'youtube_url' => ['sometimes', 'nullable', 'url', 'regex:/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//i', 'max:2048'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'languages' => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*' => ['string', 'max:100'],
            'portfolio_projects' => ['sometimes', 'nullable', 'array', 'max:30'],
            'portfolio_projects.*' => ['string', 'max:500'],
            'profile_photo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $profile = $user->trainerProfile()->firstOrCreate([]);
        $profileAttributes = collect($validated)->only(self::PROFILE_FIELDS)->map(function ($value, $key) {
            return is_array($value) ? $this->cleanList($value) : $value;
        })->all();

        $profileAttributes['is_verified'] = false;
        $profileAttributes['verified_at'] = null;
        $profileAttributes['verified_by_id'] = null;
        $profile->update($profileAttributes);

        if (array_key_exists('name', $validated)) {
            $user->update(['name' => $validated['name']]);
        }

        if ($request->hasFile('profile_photo')) {
            if (Str::startsWith($user->profile_image_path ?? '', 'storage/')) {
                Storage::disk('public')->delete(Str::after($user->profile_image_path, 'storage/'));
            }

            $path = $request->file('profile_photo')->store('trainer-avatars', 'public');
            $user->update(['profile_image_path' => 'storage/'.$path]);
        }

        $user->load('trainerProfile');

        return response()->json([
            'message' => 'Trainer profile saved and submitted for administrator review.',
            'trainer' => $this->editData($user),
        ]);
    }

    private function summary(User $user): array
    {
        $profile = $user->trainerProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'profile_image_url' => $this->imageUrl($user->profile_image_path),
            'professional_title' => $profile?->professional_title,
            'bio' => $profile?->bio,
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
            'published_courses_count' => (int) ($user->published_courses_count ?? $user->assignedCourses()->where('status', 'published')->count()),
        ];
    }

    private function editData(User $user): array
    {
        $profile = $user->trainerProfile;

        return array_merge($this->summary($user), [
            'name' => $user->name,
            'professional_experience' => $profile?->professional_experience,
            'skills' => $profile?->skills ?? [],
            'teaching_subjects' => $profile?->teaching_subjects ?? [],
            'current_job' => $profile?->current_job,
            'company' => $profile?->company,
            'designation' => $profile?->designation,
            'education' => $profile?->education ?? [],
            'certifications' => $profile?->certifications ?? [],
            'awards' => $profile?->awards ?? [],
            'languages' => $profile?->languages ?? [],
            'portfolio_projects' => $profile?->portfolio_projects ?? [],
            'verification_status' => $profile?->is_verified ? 'verified' : 'pending_review',
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url('/'.ltrim($path, '/'));
    }

    private function cleanList(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }
}
