<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearningController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollments = Enrollment::query()->with('course:id,title,slug,thumbnail_path')->where('user_id', $user->id)->where('status', 'active')->latest('enrolled_at')->get();
        $completed = LessonProgress::query()->where('user_id', $user->id)->where('is_completed', true)->count();
        $started = LessonProgress::query()->where('user_id', $user->id)->count();
        return response()->json(['stats' => ['enrolled_courses' => $enrollments->count(), 'completed_lessons' => $completed, 'overall_progress_percent' => $started ? (int) round(($completed / $started) * 100) : 0], 'courses' => $enrollments]);
    }

    public function courses(Request $request): JsonResponse
    {
        return response()->json(['courses' => Enrollment::query()->with('course:id,title,slug,short_description,thumbnail_path,duration_minutes')->where('user_id', $request->user()->id)->where('status', 'active')->latest('enrolled_at')->get()]);
    }

    public function lesson(Request $request, Lesson $lesson): JsonResponse
    {
        $lesson->load('module.course');
        $course = $lesson->module->course;
        $enrollment = $this->enrollmentFor($request, $course);
        if (! $lesson->is_published || ! $this->isUnlocked($request->user()->id, $course->id, $lesson)) abort(423, 'This lesson is locked.');
        return response()->json(['lesson' => ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'duration_seconds' => $lesson->duration_seconds, 'embed_url' => $lesson->video_reference ? 'https://www.youtube-nocookie.com/embed/'.rawurlencode($lesson->video_reference).'?rel=0&modestbranding=1' : null], 'watermark' => ['email' => $request->user()->email, 'enrollment_id' => $enrollment->id]]);
    }

    private function enrollmentFor(Request $request, Course $course): Enrollment
    {
        return Enrollment::query()->where('user_id', $request->user()->id)->where('course_id', $course->id)->where('status', 'active')->firstOr(fn () => abort(403, 'You do not have access to this course.'));
    }

    private function isUnlocked(int $userId, int $courseId, Lesson $lesson): bool
    {
        if ($lesson->unlock_type === 'immediate') return true;
        $lessons = Lesson::query()->whereHas('module', fn ($query) => $query->where('course_id', $courseId))->where('is_published', true)->with('module:id,course_id,sort_order')->get()->sortBy(fn ($item) => sprintf('%010d:%010d', $item->module->sort_order, $item->sort_order))->values();
        $index = $lessons->search(fn ($item) => $item->id === $lesson->id);
        $previous = $index !== false && $index > 0 ? $lessons->get($index - 1) : null;
        $previousProgress = $previous ? LessonProgress::query()->where('user_id', $userId)->where('lesson_id', $previous->id)->where('is_completed', true)->first() : null;
        if (in_array($lesson->unlock_type, ['sequential', 'sequential_scheduled'], true) && ! $previousProgress) return false;
        if (in_array($lesson->unlock_type, ['scheduled', 'sequential_scheduled'], true)) {
            $availableAt = $lesson->unlock_at ?? ($previousProgress?->completed_at?->copy()->addHours($lesson->unlock_delay_hours ?? 0));
            return $availableAt && $availableAt->isPast();
        }
        return true;
    }
}
