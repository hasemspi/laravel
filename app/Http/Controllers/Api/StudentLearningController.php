<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearningController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollments = Enrollment::query()->with('course:id,title,slug,short_description,thumbnail_path,duration_minutes')->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])->latest('enrolled_at')->get();
        $courseIds = $enrollments->pluck('course_id');
        $lessons = Lesson::query()->where('is_published', true)->whereHas('module', fn ($query) => $query->whereIn('course_id', $courseIds))->with('module:id,course_id')->get(['id', 'course_module_id']);
        $totalByCourse = $lessons->groupBy(fn ($lesson) => $lesson->module->course_id)->map->count();
        $completedByCourse = LessonProgress::query()->where('user_id', $user->id)->whereIn('course_id', $courseIds)->where('is_completed', true)->selectRaw('course_id, COUNT(*) as completed_count')->groupBy('course_id')->pluck('completed_count', 'course_id');
        $enrollments->each(function (Enrollment $enrollment) use ($totalByCourse, $completedByCourse): void {
            $total = (int) ($totalByCourse[$enrollment->course_id] ?? 0);
            $completed = (int) ($completedByCourse[$enrollment->course_id] ?? 0);
            $enrollment->setAttribute('progress', ['completed_lessons' => $completed, 'total_lessons' => $total, 'percent' => $total ? (int) round(($completed / $total) * 100) : 0]);
        });
        $completed = (int) $completedByCourse->sum();
        $total = (int) $totalByCourse->sum();
        return response()->json(['stats' => ['enrolled_courses' => $enrollments->count(), 'completed_lessons' => $completed, 'overall_progress_percent' => $total ? (int) round(($completed / $total) * 100) : 0], 'courses' => $enrollments]);
    }

    public function courses(Request $request): JsonResponse
    {
        return response()->json(['courses' => Enrollment::query()->with('course:id,title,slug,short_description,thumbnail_path,duration_minutes')->where('user_id', $request->user()->id)->whereIn('status', ['active', 'completed'])->latest('enrolled_at')->get()]);
    }

    public function access(Request $request, Course $course): JsonResponse
    {
        abort_unless($course->status === 'published', 404);
        $userId = $request->user()->id;
        $enrollment = Enrollment::query()->where('user_id', $userId)->where('course_id', $course->id)->first();
        $payment = Payment::query()->where('user_id', $userId)->where('course_id', $course->id)->latest('id')->first();
        $status = match (true) {
            $enrollment?->status === 'completed' => 'completed',
            $enrollment?->status === 'active' => 'approved',
            $payment?->status === 'pending' => 'payment_pending',
            $payment?->status === 'rejected' => 'rejected',
            $payment?->status === 'refunded' => 'cancelled',
            $enrollment?->status === 'cancelled' => 'cancelled',
            default => 'not_enrolled',
        };
        return response()->json([
            'course_id' => $course->id,
            'status' => $status,
            'has_access' => in_array($status, ['approved', 'completed'], true),
            'can_enroll' => in_array($status, ['not_enrolled', 'rejected', 'cancelled'], true),
            'enrollment' => $enrollment ? ['id' => $enrollment->id, 'status' => $enrollment->status, 'enrolled_at' => $enrollment->enrolled_at, 'completed_at' => $enrollment->completed_at] : null,
            'payment' => $payment ? ['id' => $payment->id, 'status' => $payment->status, 'verified_at' => $payment->verified_at] : null,
            'certificate_url' => null,
        ]);
    }

    public function course(Request $request, Course $course): JsonResponse
    {
        $enrollment = $this->enrollmentFor($request, $course);
        $course->load(['modules' => fn ($query) => $query->orderBy('sort_order'), 'modules.lessons' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order')]);
        $progress = LessonProgress::query()->where('user_id', $request->user()->id)->where('course_id', $course->id)->get()->keyBy('lesson_id');
        $lessons = $course->modules->flatMap->lessons;
        $completed = $lessons->filter(fn ($lesson) => (bool) ($progress[$lesson->id]->is_completed ?? false))->count();

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'short_description' => $course->short_description,
                'modules' => $course->modules->map(fn ($module) => [
                    'id' => $module->id,
                    'title' => $module->title,
                    'description' => $module->description,
                    'lessons' => $module->lessons->map(fn ($lesson) => [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'duration_seconds' => $lesson->duration_seconds,
                        'sort_order' => $lesson->sort_order,
                        'progress_percent' => (int) ($progress[$lesson->id]->progress_percent ?? 0),
                        'is_completed' => (bool) ($progress[$lesson->id]->is_completed ?? false),
                        'is_locked' => ! $this->isUnlocked($request->user()->id, $course->id, $lesson),
                    ]),
                ]),
            ],
            'enrollment' => ['id' => $enrollment->id, 'status' => $enrollment->status, 'enrolled_at' => $enrollment->enrolled_at],
            'progress' => ['completed_lessons' => $completed, 'total_lessons' => $lessons->count(), 'percent' => $lessons->count() ? (int) round(($completed / $lessons->count()) * 100) : 0],
        ]);
    }

    public function lesson(Request $request, Lesson $lesson): JsonResponse
    {
        $lesson->load('module.course');
        $course = $lesson->module->course;
        $enrollment = $this->enrollmentFor($request, $course);
        if (! $lesson->is_published || ! $this->isUnlocked($request->user()->id, $course->id, $lesson)) abort(423, 'This lesson is locked.');
        $progress = LessonProgress::query()->where('user_id', $request->user()->id)->where('lesson_id', $lesson->id)->first();
        return response()->json(['lesson' => ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'duration_seconds' => $lesson->duration_seconds, 'embed_url' => $lesson->video_reference ? 'https://www.youtube-nocookie.com/embed/'.rawurlencode($lesson->video_reference).'?rel=0&modestbranding=1' : null], 'progress' => $progress, 'watermark' => ['email' => $request->user()->email, 'enrollment_id' => $enrollment->id]]);
    }

    public function updateProgress(Request $request, Lesson $lesson): JsonResponse
    {
        $lesson->load('module.course');
        $course = $lesson->module->course;
        $enrollment = $this->enrollmentFor($request, $course);
        abort_unless($lesson->is_published, 404);
        abort_unless($this->isUnlocked($request->user()->id, $course->id, $lesson), 423, 'This lesson is locked.');
        $data = $request->validate(['watched_seconds' => ['required', 'integer', 'min:0'], 'video_duration_seconds' => ['nullable', 'integer', 'min:0'], 'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'], 'is_completed' => ['sometimes', 'boolean']]);
        $duration = $data['video_duration_seconds'] ?? $lesson->duration_seconds;
        $percent = $data['progress_percent'] ?? ($duration ? min(100, (int) round(($data['watched_seconds'] / $duration) * 100)) : 0);
        $completed = (bool) ($data['is_completed'] ?? false) || $percent >= 90;
        $progress = LessonProgress::query()->updateOrCreate(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id], ['course_id' => $course->id, 'course_module_id' => $lesson->course_module_id, 'watched_seconds' => $data['watched_seconds'], 'video_duration_seconds' => $duration, 'progress_percent' => $percent, 'is_completed' => $completed, 'last_watched_at' => now(), 'completed_at' => $completed ? now() : null]);
        $totalLessons = Lesson::query()->where('is_published', true)->whereHas('module', fn ($query) => $query->where('course_id', $course->id))->count();
        $completedLessons = LessonProgress::query()->where('user_id', $request->user()->id)->where('course_id', $course->id)->where('is_completed', true)->count();
        if ($totalLessons > 0 && $completedLessons >= $totalLessons) {
            $enrollment->update(['status' => 'completed', 'completed_at' => $enrollment->completed_at ?? now()]);
        } elseif ($enrollment->status === 'completed') {
            $enrollment->update(['status' => 'active', 'completed_at' => null]);
        }
        return response()->json(['message' => $completed ? 'Lesson completed.' : 'Progress saved.', 'progress' => $progress, 'enrollment' => ['status' => $enrollment->fresh()->status]]);
    }

    private function enrollmentFor(Request $request, Course $course): Enrollment
    {
        return Enrollment::query()->where('user_id', $request->user()->id)->where('course_id', $course->id)->whereIn('status', ['active', 'completed'])->firstOr(fn () => abort(403, 'You do not have access to this course.'));
    }

    private function isUnlocked(int $userId, int $courseId, Lesson $lesson): bool
    {
        if ($lesson->unlock_type === 'immediate') return true;
        $lessons = Lesson::query()->whereHas('module', fn ($query) => $query->where('course_id', $courseId))->where('is_published', true)->with('module:id,course_id,sort_order')->get()->sortBy(fn ($item) => sprintf('%010d:%010d', $item->module->sort_order, $item->sort_order))->values();
        $index = $lessons->search(fn ($item) => $item->id === $lesson->id);
        $previous = $index !== false && $index > 0 ? $lessons->get($index - 1) : null;
        $previousProgress = $previous ? LessonProgress::query()->where('user_id', $userId)->where('lesson_id', $previous->id)->where('is_completed', true)->first() : null;
        if ($index !== false && $index > 0 && in_array($lesson->unlock_type, ['sequential', 'sequential_scheduled'], true) && ! $previousProgress) return false;
        if (in_array($lesson->unlock_type, ['scheduled', 'sequential_scheduled'], true)) {
            $availableAt = $lesson->unlock_at ?? ($previousProgress?->completed_at?->copy()->addHours($lesson->unlock_delay_hours ?? 0));
            return $availableAt && $availableAt->isPast();
        }
        return true;
    }
}
