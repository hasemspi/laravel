<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(Announcement::TYPES)],
            'audience_type' => ['nullable', Rule::in(Announcement::AUDIENCES)],
            'status' => ['nullable', Rule::in(Announcement::STATUSES)],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $announcements = Announcement::query()->with('course:id,title')
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['audience_type'] ?? null, fn ($query, $audience) => $query->where('audience_type', $audience))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($announcements);
    }

    public function store(Request $request): JsonResponse
    {
        $announcement = Announcement::query()->create($this->validated($request));
        return response()->json(['message' => 'Announcement created successfully.', 'announcement' => $announcement->load('course:id,title')], 201);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return response()->json(['announcement' => $announcement->load('course:id,title')]);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($this->validated($request));
        return response()->json(['message' => 'Announcement updated successfully.', 'announcement' => $announcement->fresh()->load('course:id,title')]);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();
        return response()->json(['message' => 'Announcement deleted successfully.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::in(Announcement::TYPES)],
            'audience_type' => ['required', Rule::in(Announcement::AUDIENCES)],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'description' => ['required', 'string', 'max:10000'],
            'external_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', Rule::in(Announcement::STATUSES)],
        ]);

        if ($data['audience_type'] === 'course' && empty($data['course_id'])) {
            abort(422, 'A course is required for a specific course announcement.');
        }

        if ($data['audience_type'] !== 'course') {
            $data['course_id'] = null;
        } elseif (! Course::query()->whereKey($data['course_id'])->exists()) {
            abort(422, 'The selected course is not available.');
        }

        return $data;
    }
}
