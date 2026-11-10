<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCourseRatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $ratings = CourseRating::query()
            ->with(['course:id,title', 'user:id,name,email,profile_image_path'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('approval_status', $status))
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->latest()
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($ratings);
    }

    public function update(Request $request, CourseRating $courseRating): JsonResponse
    {
        $data = $request->validate(['approval_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])]]);

        if ($data['approval_status'] === 'approved') {
            abort_if($courseRating->rating < 4 || blank($courseRating->review), 422, 'Only positive reviews with text can be approved.');
        }

        $courseRating->update([
            'approval_status' => $data['approval_status'],
            'approved_by' => $data['approval_status'] === 'approved' ? $request->user()->id : null,
            'approved_at' => $data['approval_status'] === 'approved' ? now() : null,
        ]);

        return response()->json(['message' => 'Review moderation status updated.', 'rating' => $courseRating->fresh(['course:id,title', 'user:id,name,email,profile_image_path'])]);
    }
}
