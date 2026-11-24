<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentSuccessStory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSuccessStoryController extends Controller
{
    private const CATEGORIES = ['Freelancing', 'Job Placement', 'Entrepreneur', 'Video Stories'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $stories = StudentSuccessStory::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('student_name', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('display_order')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        $stories->getCollection()->transform(fn (StudentSuccessStory $story) => $this->payload($story));

        return response()->json($stories);
    }

    public function show(StudentSuccessStory $successStory): JsonResponse
    {
        return response()->json(['story' => $this->payload($successStory)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $this->ensureVideoSource($request, $validated);
        unset($validated['video_source'], $validated['remove_thumbnail'], $validated['video'], $validated['thumbnail']);
        $storedVideo = null;
        $storedThumbnail = null;

        try {
            [$videoType, $videoPath, $youtubeUrl] = $this->resolveMedia($request, null);
            $storedVideo = $videoPath;
            $storedThumbnail = $request->hasFile('thumbnail') ? $this->storeThumbnail($request) : null;
            $story = StudentSuccessStory::query()->create(array_merge($validated, [
                'video_type' => $videoType,
                'video_path' => $videoPath,
                'youtube_url' => $youtubeUrl,
                'thumbnail_path' => $storedThumbnail,
            ]));
        } catch (Throwable $exception) {
            $this->deleteStoredPath($storedVideo);
            $this->deleteStoredPath($storedThumbnail);
            throw $exception;
        }

        return response()->json(['message' => 'Success story created successfully.', 'story' => $this->payload($story)], 201);
    }

    public function update(Request $request, StudentSuccessStory $successStory): JsonResponse
    {
        $validated = $this->validated($request, true);
        $this->ensureVideoSource($request, $validated, $successStory);
        unset($validated['video_source'], $validated['remove_thumbnail'], $validated['video'], $validated['thumbnail']);
        $oldVideo = $successStory->video_path;
        $oldThumbnail = $successStory->thumbnail_path;
        $storedVideo = null;
        $storedThumbnail = null;

        try {
            [$videoType, $videoPath, $youtubeUrl] = $this->resolveMedia($request, $successStory);
            $storedVideo = $videoPath !== $oldVideo ? $videoPath : null;
            $storedThumbnail = $request->hasFile('thumbnail') ? $this->storeThumbnail($request) : null;
            $nextThumbnail = $storedThumbnail ?: ($request->boolean('remove_thumbnail') ? null : $oldThumbnail);
            $successStory->update(array_merge($validated, [
                'video_type' => $videoType,
                'video_path' => $videoPath,
                'youtube_url' => $youtubeUrl,
                'thumbnail_path' => $nextThumbnail,
            ]));
        } catch (Throwable $exception) {
            $this->deleteStoredPath($storedVideo);
            $this->deleteStoredPath($storedThumbnail);
            throw $exception;
        }

        if ($oldVideo !== $successStory->video_path) {
            $this->deleteIfUnused($oldVideo, $successStory->id);
        }
        if ($oldThumbnail !== $successStory->thumbnail_path) {
            $this->deleteIfUnused($oldThumbnail, $successStory->id);
        }

        return response()->json(['message' => 'Success story updated successfully.', 'story' => $this->payload($successStory->fresh())]);
    }

    public function destroy(StudentSuccessStory $successStory): JsonResponse
    {
        $videoPath = $successStory->video_path;
        $thumbnailPath = $successStory->thumbnail_path;
        $successStory->delete();
        $this->deleteIfUnused($videoPath, $successStory->id);
        $this->deleteIfUnused($thumbnailPath, $successStory->id);

        return response()->json(['message' => 'Success story deleted successfully.']);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        if (is_string($request->input('social_links'))) {
            $decoded = json_decode($request->input('social_links'), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['social_links' => 'Social links must be valid JSON.']);
            }
            $request->merge(['social_links' => $decoded]);
        }

        return $request->validate([
            'student_name' => [$updating ? 'sometimes' : 'required', 'string', 'max:160'],
            'designation' => [$updating ? 'sometimes' : 'required', 'string', 'max:180'],
            'category' => [$updating ? 'sometimes' : 'required', Rule::in(self::CATEGORIES)],
            'description' => [$updating ? 'sometimes' : 'required', 'string', 'max:10000'],
            'achievement_label' => ['nullable', 'string', 'max:120'],
            'achievement_value' => ['nullable', 'string', 'max:120'],
            'youtube_url' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtube\.com\/embed\/|youtu\.be\/)/i'],
            'video_source' => ['nullable', Rule::in(['server', 'youtube', 'none'])],
            'video_duration' => ['nullable', 'string', 'max:20', 'regex:/^\d{1,3}:\d{2}$/'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_featured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'video' => ['nullable', 'file', 'mimes:mp4,webm,mov,ogg', 'mimetypes:video/mp4,video/webm,video/quicktime,video/ogg', 'max:'.max(1, (int) env('SUCCESS_STORY_VIDEO_MAX_KB', 102400))],
            'thumbnail' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_thumbnail' => ['sometimes', 'boolean'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url', 'max:2048'],
        ]);
    }

    private function ensureVideoSource(Request $request, array $validated, ?StudentSuccessStory $story = null): void
    {
        $source = $validated['video_source'] ?? null;
        $hasFile = $request->hasFile('video');
        $hasYoutube = filled($validated['youtube_url'] ?? null);

        if ($story) {
            $hasExisting = filled($story->video_path) || filled($story->youtube_url);
            if ($source === 'youtube' && ! $hasYoutube) {
                throw ValidationException::withMessages(['youtube_url' => 'A YouTube URL is required for this source.']);
            }
            if (($source === 'server' && ! $hasFile && ! $story->video_path) || (! $source && ! $hasExisting && ! $hasFile && ! $hasYoutube)) {
                throw ValidationException::withMessages(['video' => 'Upload a video or provide a valid YouTube URL.']);
            }
            return;
        }

        if (! $hasFile && ! $hasYoutube) {
            throw ValidationException::withMessages(['video' => 'Upload a video or provide a valid YouTube URL.']);
        }
    }

    private function resolveMedia(Request $request, ?StudentSuccessStory $story): array
    {
        $source = $request->input('video_source');
        $youtubeUrl = $request->input('youtube_url');

        if ($source === 'none') {
            return [null, null, null];
        }
        if ($request->hasFile('video') || $source === 'server' || (! $source && $story?->video_path)) {
            $path = $request->hasFile('video') ? $this->storeVideo($request) : $story?->video_path;

            return ['server', $path, $youtubeUrl ?: $story?->youtube_url];
        }

        return ['youtube', null, $youtubeUrl ?: $story?->youtube_url];
    }

    private function storeVideo(Request $request): string
    {
        $path = $request->file('video')->store('success-stories', 'public');
        abort_unless($path, 500, 'The success story video could not be stored.');

        return $path;
    }

    private function storeThumbnail(Request $request): string
    {
        $path = $request->file('thumbnail')->store('success-stories/thumbnails', 'public');
        abort_unless($path, 500, 'The success story thumbnail could not be stored.');

        return $path;
    }

    private function payload(StudentSuccessStory $story): array
    {
        return [
            'id' => $story->id,
            'student_name' => $story->student_name,
            'designation' => $story->designation,
            'category' => $story->category,
            'description' => $story->description,
            'achievement_label' => $story->achievement_label,
            'achievement_value' => $story->achievement_value,
            'video_type' => $story->video_type,
            'video_url' => $story->video_type === 'server' ? $this->storageUrl($story->video_path) : null,
            'youtube_url' => $story->youtube_url,
            'thumbnail_url' => $this->storageUrl($story->thumbnail_path),
            'social_links' => $story->social_links ?? [],
            'video_duration' => $story->video_duration,
            'display_order' => $story->display_order,
            'is_featured' => $story->is_featured,
            'status' => $story->status,
            'created_at' => $story->created_at,
            'updated_at' => $story->updated_at,
        ];
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $url = Storage::disk('public')->url($path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }

    private function deleteIfUnused(?string $path, int $exceptId): void
    {
        $normalized = $this->normaliseStoragePath($path);
        if (! $normalized) {
            return;
        }
        $referenced = StudentSuccessStory::query()
            ->whereKeyNot($exceptId)
            ->where(fn ($query) => $query->where('video_path', $normalized)->orWhere('thumbnail_path', $normalized))
            ->exists();
        if (! $referenced) {
            $this->deleteStoredPath($normalized);
        }
    }

    private function deleteStoredPath(?string $path): void
    {
        $normalized = $this->normaliseStoragePath($path);
        if ($normalized) {
            Storage::disk('public')->delete($normalized);
        }
    }

    private function normaliseStoragePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }

        return Str::startsWith($path, 'success-stories/') ? $path : null;
    }
}
