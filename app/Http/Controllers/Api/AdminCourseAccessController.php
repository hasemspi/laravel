<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCourseAccessController extends Controller
{
    public function show(Course $course): JsonResponse
    {
        $rule = $course->accessRule ?: [
            'course_id' => $course->id,
            'lifetime_access_enabled' => false,
            'future_batch_access' => false,
            'release_rule' => 'published_after_enrollment',
        ];

        return response()->json(['access_rule' => $rule]);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'lifetime_access_enabled' => ['required', 'boolean'],
            'future_batch_access' => ['required', 'boolean'],
            'release_rule' => ['required', Rule::in(['published_after_enrollment', 'all_published'])],
        ]);

        $rule = $course->accessRule()->updateOrCreate([], $data);
        return response()->json(['message' => 'Enrollment and access rules saved.', 'access_rule' => $rule]);
    }
}
