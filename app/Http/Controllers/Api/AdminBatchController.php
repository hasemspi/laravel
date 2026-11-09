<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchLesson;
use App\Models\BatchModule;
use App\Models\Course;
use App\Models\CourseBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminBatchController extends Controller
{
    public function indexForTrainer(Request $request): JsonResponse
    {
        $batches = CourseBatch::query()
            ->whereHas('trainers', fn ($query) => $query->whereKey($request->user()->id))
            ->with(['course:id,title,slug', 'trainers:id,name'])
            ->withCount('modules')
            ->orderByDesc('release_date')
            ->get();

        return response()->json(['batches' => $batches]);
    }

    public function index(Course $course): JsonResponse
    {
        $batches = $course->batches()
            ->with(['trainers:id,name,email'])
            ->withCount('modules')
            ->orderByDesc('batch_number')
            ->get();

        return response()->json(['batches' => $batches]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate($this->batchRules());
        $trainerIds = $data['trainer_ids'] ?? [];
        unset($data['trainer_ids']);

        $batch = DB::transaction(function () use ($request, $course, $data, $trainerIds) {
            $batch = $course->batches()->create(array_merge($data, ['created_by' => $request->user()->id]));
            $batch->trainers()->sync($trainerIds);
            return $batch;
        });

        return response()->json(['message' => 'Batch created. Add curriculum before publishing it.', 'batch' => $batch->load('trainers:id,name,email')], 201);
    }

    public function show(CourseBatch $batch): JsonResponse
    {
        return response()->json(['batch' => $this->batchData($batch->load(['course:id,title,slug', 'trainers:id,name,email', 'modules.lessons']))]);
    }

    public function update(Request $request, CourseBatch $batch): JsonResponse
    {
        $data = $request->validate($this->batchRules(true));
        if ($batch->status !== 'draft') {
            abort_unless(($data['status'] ?? $batch->status) !== 'draft', 422, 'Published and archived batches cannot be reopened. Create a new batch for updates.');
            abort_unless(!isset($data['batch_number']) || (int) $data['batch_number'] === (int) $batch->batch_number, 422, 'Published and archived batch numbers cannot change.');
        }
        $trainerIds = array_key_exists('trainer_ids', $data) ? $data['trainer_ids'] : null;
        unset($data['trainer_ids']);

        DB::transaction(function () use ($batch, $data, $trainerIds) {
            $batch->update($data);
            if ($trainerIds !== null) $batch->trainers()->sync($trainerIds);
        });

        return response()->json(['message' => 'Batch settings updated.', 'batch' => $batch->fresh(['trainers:id,name,email'])]);
    }

    public function curriculum(Request $request, CourseBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        return response()->json(['batch' => $this->batchData($batch->load([
            'course:id,title,slug',
            'trainers:id,name,email',
            'modules' => fn ($query) => $query->orderBy('sort_order'),
            'modules.lessons' => fn ($query) => $query->orderBy('sort_order'),
        ]))]);
    }

    public function storeModule(Request $request, CourseBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['nullable', 'integer', 'min:1']]);
        $module = DB::transaction(function () use ($batch, $data) {
            $order = $data['sort_order'] ?? ((int) $batch->modules()->max('sort_order') + 1);
            $this->shiftModuleOrders($batch, $order);
            return $batch->modules()->create(['title' => trim($data['title']), 'description' => $data['description'] ?? null, 'sort_order' => $order]);
        });
        return response()->json(['message' => 'Batch module created.', 'module' => $module->load('lessons')], 201);
    }

    public function updateModule(Request $request, BatchModule $module): JsonResponse
    {
        $batch = $module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['sometimes', 'integer', 'min:1']]);
        if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== (int) $module->sort_order) $this->moveModule($module, (int) $data['sort_order']);
        $module->update($data);
        return response()->json(['message' => 'Batch module updated.', 'module' => $module->fresh('lessons')]);
    }

    public function destroyModule(Request $request, BatchModule $module): JsonResponse
    {
        $batch = $module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $module->delete();
        $this->normalizeModuleOrders($batch);
        return response()->json(['message' => 'Batch module deleted.']);
    }

    public function storeLesson(Request $request, BatchModule $module): JsonResponse
    {
        $batch = $module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $data = $this->lessonInput($request, false);
        $isTrainer = $request->user()->role === 'trainer';
        $lesson = DB::transaction(function () use ($module, $data, $isTrainer) {
            $order = $data['sort_order'] ?? ((int) $module->lessons()->max('sort_order') + 1);
            $this->shiftLessonOrders($module, $order);
            return $module->lessons()->create(array_merge($data, ['sort_order' => $order, 'approval_status' => $isTrainer ? 'draft' : 'approved', 'is_published' => false]));
        });
        return response()->json(['message' => 'Batch lesson created.', 'lesson' => $this->lessonData($lesson)], 201);
    }

    public function updateLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        $batch = $lesson->module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $data = $this->lessonInput($request, true);
        if (array_key_exists('sort_order', $data) && (int) $data['sort_order'] !== (int) $lesson->sort_order) $this->moveLesson($lesson, (int) $data['sort_order']);
        unset($data['sort_order']);
        if ($request->user()->role === 'trainer') $data = array_merge($data, ['approval_status' => 'draft', 'is_published' => false, 'rejection_note' => null]);
        $lesson->update($data);
        return response()->json(['message' => 'Batch lesson updated.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function destroyLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        $batch = $lesson->module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $module = $lesson->module;
        $lesson->delete();
        $this->normalizeLessonOrders($module);
        return response()->json(['message' => 'Batch lesson deleted.']);
    }

    public function submitLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'trainer', 403, 'Only assigned trainers can submit lessons.');
        $batch = $lesson->module->batch;
        $this->authorizeBatch($request, $batch);
        $this->assertDraft($batch);
        $lesson->update(['approval_status' => 'pending', 'submitted_at' => now(), 'rejection_note' => null, 'is_published' => false]);
        return response()->json(['message' => 'Batch lesson submitted for administrator approval.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function approveLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $this->assertDraft($lesson->module->batch);
        $lesson->update(['approval_status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejection_note' => null]);
        return response()->json(['message' => 'Batch lesson approved.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function publishLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $this->assertDraft($lesson->module->batch);
        abort_unless($lesson->approval_status === 'approved', 422, 'Approve this batch lesson before publishing it.');
        $lesson->update(['is_published' => true]);
        return response()->json(['message' => 'Batch lesson published.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function unpublishLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $this->assertDraft($lesson->module->batch);
        $lesson->update(['is_published' => false]);
        return response()->json(['message' => 'Batch lesson unpublished.', 'lesson' => $this->lessonData($lesson->fresh())]);
    }

    public function reorderModule(Request $request, BatchModule $module): JsonResponse
    {
        $this->authorizeBatch($request, $module->batch);
        $this->assertDraft($module->batch);
        $data = $request->validate(['sort_order' => ['required', 'integer', 'min:1']]);
        $this->moveModule($module, (int) $data['sort_order']);
        return response()->json(['message' => 'Batch module order saved.']);
    }

    public function reorderLesson(Request $request, BatchLesson $lesson): JsonResponse
    {
        $this->authorizeBatch($request, $lesson->module->batch);
        $this->assertDraft($lesson->module->batch);
        $data = $request->validate(['sort_order' => ['required', 'integer', 'min:1']]);
        $this->moveLesson($lesson, (int) $data['sort_order']);
        return response()->json(['message' => 'Batch lesson order saved.']);
    }

    private function batchRules(bool $updating = false): array
    {
        $status = ['sometimes', Rule::in(['draft', 'published', 'archived'])];
        if (!$updating) $status = ['required', Rule::in(['draft', 'published', 'archived'])];
        return [
            'batch_number' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1', 'max:2147483647'],
            'release_date' => ['nullable', 'date'],
            'status' => $status,
            'trainer_ids' => ['sometimes', 'array'],
            'trainer_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'trainer')->where('status', 'active'))],
        ];
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
        if (!empty($data['video_id']) && !empty($data['video_url'])) abort(422, 'Use a YouTube video ID or link, not both.');
        $data['video_reference'] = $data['video_id'] ?? $this->youtubeId($data['video_url'] ?? null);
        unset($data['video_id'], $data['video_url']);
        if (isset($data['title'])) $data['title'] = trim($data['title']);
        return $data;
    }

    private function youtubeId(?string $value): ?string
    {
        if (!$value) return null;
        if (preg_match('/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{6,32})/i', $value, $matches)) return $matches[1];
        abort(422, 'Enter a valid YouTube video ID or link.');
    }

    private function authorizeBatch(Request $request, CourseBatch $batch): void
    {
        if ($request->user()->role === 'admin') return;
        abort_unless($batch->trainers()->whereKey($request->user()->id)->exists(), 403, 'This batch is not assigned to you.');
    }

    private function assertDraft(CourseBatch $batch): void
    {
        abort_unless($batch->status === 'draft', 422, 'Published and archived batch content is immutable. Create a new batch for updates.');
    }

    private function batchData(CourseBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'course_id' => $batch->course_id,
            'batch_number' => $batch->batch_number,
            'release_date' => $batch->release_date,
            'status' => $batch->status,
            'course' => $batch->relationLoaded('course') ? $batch->course : null,
            'trainers' => $batch->trainers,
            'modules' => $batch->modules->map(fn ($module) => [
                'id' => $module->id,
                'title' => $module->title,
                'description' => $module->description,
                'sort_order' => $module->sort_order,
                'lessons' => $module->lessons->map(fn ($lesson) => $this->lessonData($lesson))->values(),
            ])->values(),
        ];
    }

    private function lessonData(BatchLesson $lesson): array
    {
        return ['id' => $lesson->id, 'batch_module_id' => $lesson->batch_module_id, 'title' => $lesson->title, 'description' => $lesson->description, 'video_id' => $lesson->video_reference, 'duration_seconds' => $lesson->duration_seconds, 'unlock_type' => $lesson->unlock_type, 'unlock_at' => $lesson->unlock_at, 'unlock_delay_hours' => $lesson->unlock_delay_hours, 'sort_order' => $lesson->sort_order, 'approval_status' => $lesson->approval_status, 'is_published' => (bool) $lesson->is_published, 'submitted_at' => $lesson->submitted_at, 'approved_at' => $lesson->approved_at, 'rejection_note' => $lesson->rejection_note];
    }

    private function shiftModuleOrders(CourseBatch $batch, int $order): void { $batch->modules()->where('sort_order', '>=', $order)->increment('sort_order'); }
    private function shiftLessonOrders(BatchModule $module, int $order): void { $module->lessons()->where('sort_order', '>=', $order)->increment('sort_order'); }
    private function moveModule(BatchModule $module, int $target): void { $old = $module->sort_order; if ($target === $old) return; $query = $module->batch->modules()->whereKeyNot($module->id); $old < $target ? $query->whereBetween('sort_order', [$old + 1, $target])->decrement('sort_order') : $query->whereBetween('sort_order', [$target, $old - 1])->increment('sort_order'); $module->update(['sort_order' => $target]); }
    private function moveLesson(BatchLesson $lesson, int $target): void { $old = $lesson->sort_order; if ($target === $old) return; $query = $lesson->module->lessons()->whereKeyNot($lesson->id); $old < $target ? $query->whereBetween('sort_order', [$old + 1, $target])->decrement('sort_order') : $query->whereBetween('sort_order', [$target, $old - 1])->increment('sort_order'); $lesson->update(['sort_order' => $target]); }
    private function normalizeModuleOrders(CourseBatch $batch): void { $batch->modules()->orderBy('sort_order')->get()->each(fn ($item, $index) => $item->update(['sort_order' => $index + 1])); }
    private function normalizeLessonOrders(BatchModule $module): void { $module->lessons()->orderBy('sort_order')->get()->each(fn ($item, $index) => $item->update(['sort_order' => $index + 1])); }
}
