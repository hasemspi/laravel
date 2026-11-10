<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchLesson;
use App\Models\BatchLessonProgress;
use App\Models\Course;
use App\Models\CourseBatch;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'batch_id' => ['sometimes', 'nullable', 'integer', 'exists:course_batches,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $enrollments = Enrollment::query()
            ->whereIn('status', ['active', 'completed'])
            ->with(['user:id,name,email', 'course:id,title', 'batchAccess' => fn ($query) => $query->whereNull('revoked_at')->with('batch:id,course_id,batch_number')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($filters['batch_id'] ?? null, fn ($query, $batchId) => $query->whereHas('batchAccess', fn ($accessQuery) => $accessQuery->where('course_batch_id', $batchId)->whereNull('revoked_at')))
            ->latest('enrolled_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        $pageEnrollments = $enrollments->getCollection();
        $courseIds = $pageEnrollments->pluck('course_id')->unique()->values();
        $enrollmentIds = $pageEnrollments->modelKeys();
        $batchIds = $pageEnrollments->flatMap(fn (Enrollment $enrollment) => $enrollment->batchAccess->pluck('course_batch_id'))->unique()->values();

        $regularLessons = Lesson::query()->where('is_published', true)->whereHas('module', fn ($query) => $query->whereIn('course_id', $courseIds))->with('module:id,course_id')->get(['id', 'course_module_id', 'duration_seconds']);
        $batchLessons = BatchLesson::query()->where('is_published', true)->whereHas('module', fn ($query) => $query->whereIn('course_batch_id', $batchIds))->with('module:id,course_batch_id')->get(['id', 'batch_module_id', 'duration_seconds']);
        $regularProgress = LessonProgress::query()->whereIn('user_id', $pageEnrollments->pluck('user_id'))->whereIn('course_id', $courseIds)->get(['user_id', 'course_id', 'lesson_id', 'watched_seconds', 'video_duration_seconds', 'progress_percent', 'is_completed', 'last_watched_at']);
        $batchProgress = BatchLessonProgress::query()->whereIn('enrollment_id', $enrollmentIds)->whereIn('course_batch_id', $batchIds)->get(['enrollment_id', 'course_batch_id', 'batch_lesson_id', 'watched_seconds', 'video_duration_seconds', 'progress_percent', 'is_completed', 'last_watched_at']);

        $rows = $pageEnrollments->flatMap(function (Enrollment $enrollment) use ($regularLessons, $batchLessons, $regularProgress, $batchProgress): array {
            if ($enrollment->batchAccess->isEmpty()) {
                return [$this->attendanceRow($enrollment, null, $regularLessons->filter(fn ($lesson) => (int) $lesson->module->course_id === (int) $enrollment->course_id), $regularProgress->where('user_id', $enrollment->user_id)->where('course_id', $enrollment->course_id))];
            }

            return $enrollment->batchAccess->map(function ($access) use ($enrollment, $batchLessons, $batchProgress): array {
                $batch = $access->batch;

                return $this->attendanceRow($enrollment, $batch, $batchLessons->filter(fn ($lesson) => (int) $lesson->module->course_batch_id === (int) $batch->id), $batchProgress->where('enrollment_id', $enrollment->id)->where('course_batch_id', $batch->id));
            })->all();
        })->values();

        return response()->json(array_merge($enrollments->toArray(), [
            'data' => $rows,
            'filters' => [
                'courses' => Course::query()->whereHas('enrollments', fn ($query) => $query->whereIn('status', ['active', 'completed']))->orderBy('title')->get(['id', 'title']),
                'batches' => CourseBatch::query()->whereHas('accessGrants', fn ($query) => $query->whereNull('revoked_at'))->with('course:id,title')->orderByDesc('batch_number')->get(['id', 'course_id', 'batch_number']),
            ],
        ]));
    }

    public function show(Request $request, Enrollment $enrollment): JsonResponse
    {
        $validated = $request->validate(['batch_id' => ['sometimes', 'nullable', 'integer', 'exists:course_batches,id']]);
        abort_unless(in_array($enrollment->status, ['active', 'completed'], true), 404);

        $enrollment->load(['user:id,name,email', 'course:id,title,slug']);
        $batch = null;
        if (! empty($validated['batch_id'])) {
            $batch = $enrollment->batchAccess()->where('course_batch_id', $validated['batch_id'])->whereNull('revoked_at')->with('batch:id,course_id,batch_number')->firstOrFail()->batch;
        }

        if ($batch) {
            $batch->load(['modules' => fn ($query) => $query->orderBy('sort_order'), 'modules.lessons' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order')]);
            $modules = $batch->modules;
            $lessons = $modules->flatMap->lessons;
            $progress = BatchLessonProgress::query()->where('enrollment_id', $enrollment->id)->where('course_batch_id', $batch->id)->get()->keyBy('batch_lesson_id');
        } else {
            $enrollment->course->load(['modules' => fn ($query) => $query->orderBy('sort_order'), 'modules.lessons' => fn ($query) => $query->where('is_published', true)->orderBy('sort_order')]);
            $modules = $enrollment->course->modules;
            $lessons = $modules->flatMap->lessons;
            $progress = LessonProgress::query()->where('user_id', $enrollment->user_id)->where('course_id', $enrollment->course_id)->get()->keyBy('lesson_id');
        }

        $details = $this->progressDetails($lessons, $progress);

        return response()->json([
            'student' => ['id' => $enrollment->user->id, 'name' => $enrollment->user->name, 'email' => $enrollment->user->email],
            'course' => ['id' => $enrollment->course->id, 'title' => $enrollment->course->title, 'slug' => $enrollment->course->slug],
            'batch' => $batch ? ['id' => $batch->id, 'batch_number' => $batch->batch_number] : null,
            'summary' => $details['summary'],
            'modules' => $modules->map(fn ($module) => [
                'id' => $module->id,
                'title' => $module->title,
                'lessons' => $module->lessons->map(fn ($lesson) => $details['lessons'][$lesson->id] ?? $this->lessonProgressData($lesson, null))->values(),
            ])->values(),
        ]);
    }

    private function attendanceRow(Enrollment $enrollment, ?CourseBatch $batch, Collection $lessons, Collection $progress): array
    {
        $details = $this->progressDetails($lessons, $progress->keyBy(fn ($item) => $batch ? $item->batch_lesson_id : $item->lesson_id));

        return [
            'id' => $enrollment->id,
            'student' => ['id' => $enrollment->user->id, 'name' => $enrollment->user->name, 'email' => $enrollment->user->email],
            'course' => ['id' => $enrollment->course->id, 'title' => $enrollment->course->title],
            'batch' => $batch ? ['id' => $batch->id, 'batch_number' => $batch->batch_number] : null,
            'learning_progress_percent' => $details['summary']['course_completion_percent'],
            'attendance_percent' => $details['summary']['attendance_percent'],
            'progress_percent' => $details['summary']['course_completion_percent'],
            'total_lessons' => $details['summary']['total_lessons'],
            'completed_lessons' => $details['summary']['completed_lessons'],
            'video_watch_time_minutes' => $details['summary']['video_watch_time_minutes'],
            'watched_percentage' => $details['summary']['watched_percentage'],
            'last_activity' => $details['summary']['last_activity'],
        ];
    }

    private function progressDetails(Collection $lessons, Collection $progress): array
    {
        $lessonDetails = [];
        $completed = 0;
        $attended = 0;
        $watchedSeconds = 0;
        $videoSeconds = 0;
        $lastActivity = null;

        foreach ($lessons as $lesson) {
            $item = $progress->get($lesson->id);
            $lessonData = $this->lessonProgressData($lesson, $item);
            $lessonDetails[$lesson->id] = $lessonData;
            $completed += $lessonData['is_completed'] ? 1 : 0;
            $attended += $lessonData['watched_seconds'] > 0 || $lessonData['progress_percent'] > 0 ? 1 : 0;
            $watchedSeconds += $lessonData['watched_seconds'];
            $videoSeconds += $lessonData['video_duration_seconds'];
            if ($lessonData['last_watched_at'] && (! $lastActivity || $lessonData['last_watched_at'] > $lastActivity)) {
                $lastActivity = $lessonData['last_watched_at'];
            }
        }

        $total = $lessons->count();

        return [
            'lessons' => $lessonDetails,
            'summary' => [
                'total_lessons' => $total,
                'completed_lessons' => $completed,
                'video_watch_time_minutes' => (int) round($watchedSeconds / 60),
                'watched_percentage' => $videoSeconds ? min(100, (int) round(($watchedSeconds / $videoSeconds) * 100)) : 0,
                'attendance_percent' => $total ? (int) round(($attended / $total) * 100) : 0,
                'course_completion_percent' => $total ? (int) round(($completed / $total) * 100) : 0,
                'last_activity' => $lastActivity,
            ],
        ];
    }

    private function lessonProgressData($lesson, $progress): array
    {
        $duration = (int) ($progress?->video_duration_seconds ?: $lesson->duration_seconds ?: 0);

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'duration_seconds' => (int) ($lesson->duration_seconds ?? 0),
            'watched_seconds' => (int) ($progress?->watched_seconds ?? 0),
            'video_duration_seconds' => $duration,
            'progress_percent' => (int) ($progress?->progress_percent ?? 0),
            'is_completed' => (bool) ($progress?->is_completed ?? false),
            'last_watched_at' => $progress?->last_watched_at,
        ];
    }
}
