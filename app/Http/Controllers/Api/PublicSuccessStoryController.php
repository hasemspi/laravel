<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentSuccessStory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicSuccessStoryController extends Controller
{
    private const CATEGORIES = ['Freelancing', 'Job Placement', 'Entrepreneur', 'Video Stories'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $stories = StudentSuccessStory::query()
            ->published()
            ->when($filters['category'] ?? null, function ($query, $category) {
                if ($category === 'Video Stories') {
                    return $query->whereNotNull('video_type');
                }

                return $query->where('category', $category);
            })
            ->when($filters['featured'] ?? false, fn ($query) => $query->where('is_featured', true))
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 12)
            ->withQueryString();

        $stories->getCollection()->transform(fn (StudentSuccessStory $story) => $this->payload($story));

        return response()->json($stories);
    }

    public function show(StudentSuccessStory $successStory): JsonResponse
    {
        abort_unless($successStory->status === 'published', 404);

        return response()->json(['story' => $this->payload($successStory)]);
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
        ];
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $url = Storage::disk('public')->url($path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
