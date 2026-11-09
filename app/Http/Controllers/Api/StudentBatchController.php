<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchLesson;
use App\Models\Course;
use App\Models\CourseBatch;
use App\Models\Enrollment;
use App\Models\BatchLessonProgress;
use App\Services\BatchAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentBatchController extends Controller
{
    public function __construct(private readonly BatchAccessService $access) {}

    public function batches(Request $request, Course $course): JsonResponse
    {
        $enrollment = $this->enrollmentFor($request, $course);
        return response()->json(['batches' => $this->access->accessibleBatches($enrollment)]);
    }

    public function batch(Request $request, Course $course, CourseBatch $batch): JsonResponse
    {
        abort_unless((int) $batch->course_id === (int) $course->id, 404);
        $enrollment = $this->enrollmentFor($request, $course);
        abort_unless($this->access->hasAccess($enrollment, $batch), 403, 'You do not have access to this batch.');

        $batch->load([
            'modules' => fn ($query) => $query->orderBy('sort_order'),
            'modules.lessons' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order'),
        ]);
        $progress = BatchLessonProgress::query()->where('enrollment_id', $enrollment->id)->where('course_batch_id', $batch->id)->get()->keyBy('batch_lesson_id');
        $lessons = $batch->modules->flatMap->lessons;
        $completed = $lessons->filter(fn ($lesson) => (bool) ($progress[$lesson->id]->is_completed ?? false))->count();

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'course_id' => $batch->course_id,
                'batch_number' => $batch->batch_number,
                'release_date' => $batch->release_date,
                'status' => $batch->status,
                'modules' => $batch->modules->map(fn ($module) => [
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
                        'is_locked' => !$this->isUnlocked($request->user()->id, $enrollment->id, $batch->id, $lesson),
                    ]),
                ]),
            ],
            'enrollment' => ['id' => $enrollment->id, 'status' => $enrollment->status, 'enrolled_at' => $enrollment->enrolled_at],
            'progress' => ['completed_lessons' => $completed, 'total_lessons' => $lessons->count(), 'percent' => $lessons->count() ? (int) round(($completed / $lessons->count()) * 100) : 0],
        ]);
    }

    public function lesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        $lesson->load('module.batch.course');
        $batch = $lesson->module->batch;
        $course = $batch->course;
        $enrollment = $this->enrollmentFor($request, $course);
        abort_unless($this->access->hasAccess($enrollment, $batch), 403, 'You do not have access to this batch.');
        abort_unless($lesson->is_published && $this->isUnlocked($request->user()->id, $enrollment->id, $batch->id, $lesson), 423, 'This batch lesson is locked.');

        $progress = BatchLessonProgress::query()->where('enrollment_id', $enrollment->id)->where('batch_lesson_id', $lesson->id)->first();
        return response()->json([
            'lesson' => ['id' => $lesson->id, 'batch_id' => $batch->id, 'title' => $lesson->title, 'description' => $lesson->description, 'duration_seconds' => $lesson->duration_seconds, 'embed_url' => $lesson->video_reference ? 'https://www.youtube-nocookie.com/embed/'.rawurlencode($lesson->video_reference).'?rel=0&modestbranding=1' : null],
            'progress' => $progress,
            'watermark' => ['email' => $request->user()->email, 'enrollment_id' => $enrollment->id, 'batch_number' => $batch->batch_number],
        ]);
    }

    public function updateProgress(Request $request, BatchLesson $lesson): JsonResponse
    {
        $lesson->load('module.batch.course');
        $batch = $lesson->module->batch;
        $enrollment = $this->enrollmentFor($request, $batch->course);
        abort_unless($this->access->hasAccess($enrollment, $batch), 403, 'You do not have access to this batch.');
        abort_unless($lesson->is_published && $this->isUnlocked($request->user()->id, $enrollment->id, $batch->id, $lesson), 423, 'This batch lesson is locked.');

        $data = $request->validate(['watched_seconds' => ['required', 'integer', 'min:0'], 'video_duration_seconds' => ['nullable', 'integer', 'min:0'], 'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'], 'is_completed' => ['sometimes', 'boolean']]);
        $duration = $data['video_duration_seconds'] ?? $lesson->duration_seconds;
        $percent = $data['progress_percent'] ?? ($duration ? min(100, (int) round(($data['watched_seconds'] / $duration) * 100)) : 0);
        $completed = (bool) ($data['is_completed'] ?? false) || $percent >= 90;
        $progress = BatchLessonProgress::query()->updateOrCreate(
            ['enrollment_id' => $enrollment->id, 'batch_lesson_id' => $lesson->id],
            ['user_id' => $request->user()->id, 'course_batch_id' => $batch->id, 'watched_seconds' => $data['watched_seconds'], 'video_duration_seconds' => $duration, 'progress_percent' => $percent, 'is_completed' => $completed, 'last_watched_at' => now(), 'completed_at' => $completed ? now() : null],
        );

        return response()->json(['message' => $completed ? 'Batch lesson completed.' : 'Batch progress saved.', 'progress' => $progress]);
    }

    private function enrollmentFor(Request $request, Course $course): Enrollment
    {
        return Enrollment::query()->where('user_id', $request->user()->id)->where('course_id', $course->id)->whereIn('status', ['active', 'completed'])->firstOr(fn () => abort(403, 'You do not have access to this course.'));
    }

    private function isUnlocked(int $userId, int $enrollmentId, int $batchId, BatchLesson $lesson): bool
    {
        if ($lesson->unlock_type === 'immediate') return true;
        $lessons = BatchLesson::query()->whereHas('module', fn ($query) => $query->where('course_batch_id', $batchId))->where('is_published', true)->with('module:id,course_batch_id,sort_order')->get()->sortBy(fn ($item) => sprintf('%010d:%010d', $item->module->sort_order, $item->sort_order))->values();
        $index = $lessons->search(fn ($item) => $item->id === $lesson->id);
        $previous = $index !== false && $index > 0 ? $lessons->get($index - 1) : null;
        $previousProgress = $previous ? BatchLessonProgress::query()->where('enrollment_id', $enrollmentId)->where('batch_lesson_id', $previous->id)->where('is_completed', true)->first() : null;
        if ($index !== false && $index > 0 && in_array($lesson->unlock_type, ['sequential', 'sequential_scheduled'], true) && !$previousProgress) return false;
        if (in_array($lesson->unlock_type, ['scheduled', 'sequential_scheduled'], true)) {
            $availableAt = $lesson->unlock_at ?? ($previousProgress?->completed_at?->copy()->addHours($lesson->unlock_delay_hours ?? 0));
            return $availableAt && $availableAt->isPast();
        }
        return true;
    }
}
