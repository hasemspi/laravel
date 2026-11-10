<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:course_categories,id'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $courses = Course::query()
            ->with(['category:id,name,slug', 'trainers:id,name'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('title', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%')
                        ->orWhere('short_description', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('course_category_id', $categoryId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($courses);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $trainerIds = $validated['trainer_ids'] ?? [];
        unset($validated['trainer_ids']);

        $course = DB::transaction(function () use ($validated, $trainerIds) {
            $course = Course::query()->create($validated);
            $course->trainers()->sync($trainerIds);

            return $course;
        });

        return response()->json(['message' => 'Course created successfully.', 'course' => $course->load(['category', 'trainers'])], 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json(['course' => $course->load(['category', 'trainers', 'modules.lessons'])]);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $validated = $request->validated();
        $hasTrainerIds = array_key_exists('trainer_ids', $validated);
        $trainerIds = $validated['trainer_ids'] ?? [];
        unset($validated['trainer_ids']);

        DB::transaction(function () use ($course, $validated, $hasTrainerIds, $trainerIds) {
            $course->update($validated);
            if ($hasTrainerIds) {
                $course->trainers()->sync($trainerIds);
            }
        });

        return response()->json(['message' => 'Course updated successfully.', 'course' => $course->fresh(['category', 'trainers'])]);
    }

    public function uploadThumbnail(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'thumbnail' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $path = $data['thumbnail']->store('images/course-thumbnails', 'frontend_public');
        $course->update(['thumbnail_path' => $path]);

        return response()->json([
            'message' => 'Course thumbnail uploaded successfully.',
            'thumbnail_path' => $course->thumbnail_path,
            'thumbnail_url' => '/'.ltrim($path, '/'),
            'course' => $course->fresh(['category', 'trainers']),
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json(['message' => 'Course removed from the active catalog. Existing enrollment and payment records were retained.']);
    }
}
