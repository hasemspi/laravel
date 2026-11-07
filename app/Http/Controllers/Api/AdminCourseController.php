<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()->with(['category:id,name,slug', 'trainers:id,name'])->latest()->paginate(min($request->integer('per_page', 15), 100));
        return response()->json($courses);
    }
    public function store(StoreCourseRequest $request): JsonResponse
    {
        $data = $request->safe()->except('trainer_ids');
        $course = Course::query()->create($data);
        $course->trainers()->sync($request->validated('trainer_ids', []));
        return response()->json(['message' => 'Course created successfully.', 'course' => $course->load(['category', 'trainers'])], 201);
    }
    public function show(Course $course): JsonResponse { return response()->json(['course' => $course->load(['category', 'trainers', 'modules.lessons'])]); }
    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $course->update($request->safe()->except('trainer_ids'));
        if ($request->has('trainer_ids')) $course->trainers()->sync($request->validated('trainer_ids'));
        return response()->json(['message' => 'Course updated successfully.', 'course' => $course->fresh(['category', 'trainers'])]);
    }
    public function destroy(Course $course): JsonResponse { $course->delete(); return response()->json(['message' => 'Course deleted successfully.']); }
}
