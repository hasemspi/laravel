<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CurriculumController extends Controller
{
    public function trainerCourses(Request $request): JsonResponse
    {
        $courses = $request->user()->assignedCourses()
            ->withCount('modules')
            ->with('category:id,name')
            ->whereIn('status', ['draft', 'published'])
            ->latest('courses.updated_at')
            ->get(['courses.id', 'courses.title', 'courses.slug', 'courses.short_description', 'courses.status', 'courses.thumbnail_path']);

        return response()->json(['courses' => $courses]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course);
        $course->load([
            'trainers:id,name,email',
            'modules' => fn ($query) => $query->orderBy('sort_order'),
            'modules.lessons' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        return response()->json(['course' => $this->courseData($course)]);
    }

    public function storeModule(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course);
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['nullable', 'integer', 'min:1']]);
        $module = DB::transaction(function () use ($course, $data) {
            $order = $data['sort_order'] ?? ((int) $course->modules()->max('sort_order') + 1);
            $this->shiftModuleOrders($course, $order);
            return $course->modules()->create(['title' => trim($data['title']), 'description' => $data['description'] ?? null, 'sort_order' => $order]);
        });
        return response()->json(['message' => 'Module created.', 'module' => $module->load('lessons')], 201);
    }

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorizeCourse($request, $module->course);
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['sometimes', 'integer', 'min:1']]);
        if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== (int) $module->sort_order) {
            $this->moveModule($module, (int) $data['sort_order']);
        }
        $module->update($data);
        return response()->json(['message' => 'Module updated.', 'module' => $module->fresh('lessons')]);
    }

    public function destroyModule(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorizeCourse($request, $module->course);
        $module->delete();
        $this->normalizeModuleOrders($module->course);
        return response()->json(['message' => 'Module deleted.']);
    }

    public function storeLesson(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorizeCourse($request, $module->course);
        $data = $this->lessonInput($request, false);
        $isTrainer = $request->user()->role === 'trainer';
        $lesson = DB::transaction(function () use ($module, $data, $isTrainer) {
            $order = $data['sort_order'] ?? ((int) $module->lessons()->max('sort_order') + 1);
            $this->shiftLessonOrders($module, $order);
            return $module->lessons()->create(array_merge($data, [
                'sort_order' => $order,
                'approval_status' => $isTrainer ? 'draft' : 'approved',
                'is_published' => false,
            ]));
        });
        return response()->json(['message' => 'Lesson created.', 'lesson' => $this->lessonData($lesson)], 201);
    }

    public function updateLesson(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeCourse($request, $lesson->module->course);
        $data = $this->lessonInput($request, true);
        if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== (int) $lesson->sort_order) {
            $this->moveLesson($lesson, (int) $data['sort_order']);
        }
        unset($data['sort_order']);
        if ($request->user()->role === 'trainer') {
            $data = array_merge($data, ['approval_status' => 'draft', 'is_published' => false, 'rejection_note' => null]);
        }
        $lesson->update($data);
        return response()->json(['message' => 'Lesson updated.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function destroyLesson(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeCourse($request, $lesson->module->course);
        $module = $lesson->module;
        $lesson->delete();
        $this->normalizeLessonOrders($module);
        return response()->json(['message' => 'Lesson deleted.']);
    }

    public function submitLesson(Request $request, Lesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'trainer', 403, 'Only assigned trainers can submit lessons.');
        $this->authorizeCourse($request, $lesson->module->course);
        $lesson->update(['approval_status' => 'pending', 'submitted_at' => now(), 'rejection_note' => null, 'is_published' => false]);
        return response()->json(['message' => 'Lesson submitted for administrator approval.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function approveLesson(Request $request, Lesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $lesson->update(['approval_status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejection_note' => null]);
        return response()->json(['message' => 'Lesson approved. It can now be published.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function publishLesson(Request $request, Lesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless($lesson->approval_status === 'approved', 422, 'Approve this lesson before publishing it.');
        $lesson->update(['is_published' => true]);
        return response()->json(['message' => 'Lesson published.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function unpublishLesson(Request $request, Lesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $lesson->update(['is_published' => false]);
        return response()->json(['message' => 'Lesson unpublished.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function reorderModule(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorizeCourse($request, $module->course);
        $data = $request->validate(['sort_order' => ['required', 'integer', 'min:1']]);
        $this->moveModule($module, (int) $data['sort_order']);
        return response()->json(['message' => 'Module order saved.']);
    }

    public function reorderLesson(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeCourse($request, $lesson->module->course);
        $data = $request->validate(['sort_order' => ['required', 'integer', 'min:1']]);
        $this->moveLesson($lesson, (int) $data['sort_order']);
        return response()->json(['message' => 'Lesson order saved.']);
    }

    private function lessonInput(Request $request, bool $updating): array
    {
        $data = $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:50000'],
            'video_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]{6,32}$/'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'unlock_type' => ['sometimes', Rule::in(['immediate', 'sequential', 'scheduled', 'sequential_scheduled'])],
            'unlock_at' => ['nullable', 'date'],
            'unlock_delay_hours' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (! empty($data['video_id']) && ! empty($data['video_url'])) abort(422, 'Use a YouTube video ID or link, not both.');
        $data['video_reference'] = $data['video_id'] ?? $this->youtubeId($data['video_url'] ?? null);
        unset($data['video_id'], $data['video_url']);
        $data['title'] = isset($data['title']) ? trim($data['title']) : null;
        return array_filter($data, static fn ($value) => $value !== null || in_array($value, ['description', 'video_reference', 'unlock_at', 'unlock_delay_hours'], true));
    }

    private function youtubeId(?string $value): ?string
    {
        if (! $value) return null;
        if (preg_match('/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{6,32})/i', $value, $matches)) return $matches[1];
        abort(422, 'Enter a valid YouTube video ID or link.');
    }

    private function authorizeCourse(Request $request, Course $course): void
    {
        if ($request->user()->role === 'admin') return;
        abort_unless($request->user()->assignedCourses()->whereKey($course->id)->exists(), 403, 'This course is not assigned to you.');
    }

    private function courseData(Course $course): array
    {
        return ['id' => $course->id, 'title' => $course->title, 'slug' => $course->slug, 'status' => $course->status, 'trainers' => $course->trainers, 'modules' => $course->modules->map(fn ($module) => ['id' => $module->id, 'title' => $module->title, 'description' => $module->description, 'sort_order' => $module->sort_order, 'lessons' => $module->lessons->map(fn ($lesson) => $this->lessonData($lesson))->values()])->values()];
    }

    private function lessonData(Lesson $lesson): array
    {
        return ['id' => $lesson->id, 'course_module_id' => $lesson->course_module_id, 'title' => $lesson->title, 'description' => $lesson->description, 'video_id' => $lesson->video_reference, 'duration_seconds' => $lesson->duration_seconds, 'unlock_type' => $lesson->unlock_type, 'unlock_at' => $lesson->unlock_at, 'unlock_delay_hours' => $lesson->unlock_delay_hours, 'sort_order' => $lesson->sort_order, 'approval_status' => $lesson->approval_status ?? 'approved', 'is_published' => (bool) $lesson->is_published, 'submitted_at' => $lesson->submitted_at, 'approved_at' => $lesson->approved_at, 'rejection_note' => $lesson->rejection_note];
    }

    private function shiftModuleOrders(Course $course, int $order): void { $course->modules()->where('sort_order', '>=', $order)->increment('sort_order'); }
    private function shiftLessonOrders(CourseModule $module, int $order): void { $module->lessons()->where('sort_order', '>=', $order)->increment('sort_order'); }
    private function moveModule(CourseModule $module, int $target): void { $old = $module->sort_order; if ($target === $old) return; $query = $module->course->modules()->whereKeyNot($module->id); $old < $target ? $query->whereBetween('sort_order', [$old + 1, $target])->decrement('sort_order') : $query->whereBetween('sort_order', [$target, $old - 1])->increment('sort_order'); $module->update(['sort_order' => $target]); }
    private function moveLesson(Lesson $lesson, int $target): void { $old = $lesson->sort_order; if ($target === $old) return; $query = $lesson->module->lessons()->whereKeyNot($lesson->id); $old < $target ? $query->whereBetween('sort_order', [$old + 1, $target])->decrement('sort_order') : $query->whereBetween('sort_order', [$target, $old - 1])->increment('sort_order'); $lesson->update(['sort_order' => $target]); }
    private function normalizeModuleOrders(Course $course): void { $course->modules()->orderBy('sort_order')->get()->each(fn ($item, $index) => $item->update(['sort_order' => $index + 1])); }
    private function normalizeLessonOrders(CourseModule $module): void { $module->lessons()->orderBy('sort_order')->get()->each(fn ($item, $index) => $item->update(['sort_order' => $index + 1])); }
}
