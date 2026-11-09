<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseRatingController extends Controller
{
    public function show(Request $request, Course $course): JsonResponse
    {
        $this->verifiedEnrollment($request, $course);
        return response()->json(['rating' => CourseRating::query()->where('course_id', $course->id)->where('user_id', $request->user()->id)->first()]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        $this->verifiedEnrollment($request, $course);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = CourseRating::query()->updateOrCreate(
            ['course_id' => $course->id, 'user_id' => $request->user()->id],
            ['rating' => $data['rating'], 'review' => $data['review'] ?? null],
        );

        return response()->json(['message' => 'Your course rating has been saved.', 'rating' => $rating], $rating->wasRecentlyCreated ? 201 : 200);
    }

    private function verifiedEnrollment(Request $request, Course $course): Enrollment
    {
        abort_unless($course->status === 'published', 404);
        return Enrollment::query()
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->firstOr(fn () => abort(403, 'Only verified enrolled students can rate this course.'));
    }
}
