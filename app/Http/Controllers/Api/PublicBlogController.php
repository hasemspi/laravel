<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogComment;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicBlogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:140'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:12'],
        ]);

        $posts = $this->publishedQuery()
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(fn ($nested) => $nested
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('excerpt', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%"));
            })
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas(
                'category',
                fn ($categoryQuery) => $categoryQuery->where('slug', $category)->where('is_active', true)
            ))
            ->latest('published_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 12)
            ->withQueryString();

        $posts->getCollection()->transform(fn (BlogPost $post) => $this->postSummary($post));

        return response()->json(array_merge($posts->toArray(), [
            'featured' => $this->featuredPosts(),
            'categories' => $this->categoryList(),
            'popular' => $this->popularPosts(),
            'widgets' => $this->widgets(),
            'ad_config' => $this->adConfig(),
        ]));
    }

    public function featured(): JsonResponse
    {
        return response()->json(['featured' => $this->featuredPosts()]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['categories' => $this->categoryList()]);
    }

    public function popular(): JsonResponse
    {
        return response()->json(['popular' => $this->popularPosts()]);
    }

    public function show(BlogPost $post): JsonResponse
    {
        abort_unless(in_array($post->status, ['published', 'scheduled'], true) && $post->published_at?->isPast(), 404);

        $post->load('category:id,name,slug');
        $post->increment('views');

        $related = $this->publishedQuery()
            ->where('id', '!=', $post->id)
            ->where('blog_category_id', $post->blog_category_id)
            ->latest('published_at')
            ->limit(3)
            ->get()
            ->map(fn (BlogPost $relatedPost) => $this->postSummary($relatedPost))
            ->values();

        return response()->json([
            'post' => $this->postDetails($post),
            'related' => $related,
            'comments' => $this->approvedComments($post),
            'widgets' => $this->widgets(),
            'ad_config' => $this->adConfig(),
        ]);
    }

    public function storeComment(Request $request, BlogPost $post): JsonResponse
    {
        abort_unless(in_array($post->status, ['published', 'scheduled'], true) && $post->published_at?->isPast(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:180'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        BlogComment::query()->create([
            'blog_post_id' => $post->id,
            ...$validated,
            'status' => 'pending',
        ]);

        return response()->json(['message' => 'Your comment was submitted for moderation.'], 201);
    }

    private function publishedQuery()
    {
        return BlogPost::query()
            ->published()
            ->with('category:id,name,slug')
            ->select([
                'id', 'blog_category_id', 'title', 'slug', 'excerpt', 'content',
                'image_path', 'image_alt', 'author_name', 'is_featured',
                'published_at', 'read_time_minutes', 'views', 'updated_at',
            ]);
    }

    private function featuredPosts(): Collection
    {
        return $this->publishedQuery()
            ->where('is_featured', true)
            ->latest('published_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (BlogPost $post) => $this->postSummary($post))
            ->values();
    }

    private function categoryList(): Collection
    {
        return BlogCategory::query()
            ->where('is_active', true)
            ->whereHas('publishedPosts')
            ->withCount(['publishedPosts as posts_count'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (BlogCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'posts_count' => (int) $category->posts_count,
            ])
            ->values();
    }

    private function popularPosts(): Collection
    {
        return $this->publishedQuery()
            ->orderByDesc('views')
            ->latest('published_at')
            ->limit(5)
            ->get()
            ->map(fn (BlogPost $post) => $this->postSummary($post))
            ->values();
    }

    private function widgets(): Collection
    {
        return BlogWidget::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'title', 'body', 'type'])
            ->values();
    }

    private function postSummary(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'category' => $post->category?->name,
            'category_slug' => $post->category?->slug,
            'date' => $post->published_at?->format('M j, Y'),
            'published_at' => $post->published_at?->toISOString(),
            'read' => $post->read_time_minutes ? "{$post->read_time_minutes} min read" : null,
            'image' => $this->imageUrl($post->image_path),
            'image_alt' => $post->image_alt ?: $post->title,
            'author' => $post->author_name,
            'is_featured' => (bool) $post->is_featured,
            'views' => (int) $post->views,
        ];
    }

    private function postDetails(BlogPost $post): array
    {
        return array_merge($this->postSummary($post), [
            'content' => $this->contentWithImageUrls($post->content),
            'updated_at' => $post->updated_at?->toISOString(),
        ]);
    }

    private function approvedComments(BlogPost $post): Collection
    {
        return $post->comments()
            ->where('status', 'approved')
            ->latest()
            ->get(['id', 'name', 'body', 'created_at'])
            ->map(fn (BlogComment $comment) => [
                'id' => $comment->id,
                'name' => $comment->name,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toISOString(),
            ])
            ->values();
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $assetPath = parse_url($path, PHP_URL_PATH) ?: $path;

        if (Str::startsWith($assetPath, '/storage/blogs/')) {
            $storagePath = ltrim(Str::after($assetPath, '/storage/'), '/');
            $storageUrl = Storage::disk('public')->url($storagePath);

            return Str::startsWith($storageUrl, ['http://', 'https://']) ? $storageUrl : url($storageUrl);
        }

        if (Str::startsWith($assetPath, 'blogs/')) {
            $storageUrl = Storage::disk('public')->url($assetPath);

            return Str::startsWith($storageUrl, ['http://', 'https://']) ? $storageUrl : url($storageUrl);
        }

        if (Str::startsWith($assetPath, 'storage/blogs/')) {
            $storageUrl = Storage::disk('public')->url(Str::after($assetPath, 'storage/'));

            return Str::startsWith($storageUrl, ['http://', 'https://']) ? $storageUrl : url($storageUrl);
        }

        if (Str::startsWith($assetPath, '/images/blog/')) {
            return url('/api/blog/images/'.rawurlencode(basename($assetPath)));
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url('/'.ltrim($path, '/'));
    }

    private function contentWithImageUrls(?string $content): ?string
    {
        if (! $content) {
            return $content;
        }

        return preg_replace_callback('/(src\s*=\s*["\'])([^"\']+)(["\'])/i', function (array $matches) {
            $source = $matches[2];
            $path = parse_url($source, PHP_URL_PATH) ?: $source;

            if (! Str::startsWith($path, '/images/blog/')) {
                return $matches[0];
            }

            return $matches[1].$this->imageUrl($path).$matches[3];
        }, $content);
    }

    private function adConfig(): array
    {
        $ads = config('blog.ads');
        $enabled = (bool) ($ads['enabled'] ?? false)
            && filled($ads['client'] ?? null)
            && collect($ads['slots'] ?? [])->filter()->isNotEmpty();

        return [
            'enabled' => $enabled,
            'client' => $enabled ? $ads['client'] : null,
            'slots' => $enabled ? collect($ads['slots'])->filter()->all() : [],
        ];
    }
}
