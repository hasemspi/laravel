<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\BlogWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminBlogController extends Controller
{
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $image = $request->file('image');
        $filename = Str::uuid().'.'.$image->extension();
        $storedPath = $image->storeAs('blogs', $filename, 'public');
        abort_unless($storedPath, 500, 'The blog image could not be stored.');

        $url = $this->publicStorageUrl($storedPath);

        return response()->json([
            'message' => 'Image uploaded successfully.',
            'path' => $storedPath,
            'url' => $url,
        ], 201);
    }

    public function image(string $filename)
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) === 1, 404);

        $path = Storage::disk('frontend_public')->path('images/blog/'.$filename);
        abort_unless(File::isFile($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'scheduled'])],
            'category' => ['nullable', 'string', 'max:140'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = BlogPost::query()
            ->with(['category:id,name,slug', 'tags:id,name,slug'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('title', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas('category', fn ($nested) => $nested->where('slug', $category)))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        $posts->getCollection()->transform(fn (BlogPost $post) => $this->normalisePostImage($post));

        return response()->json($posts);
    }

    public function post(BlogPost $post): JsonResponse
    {
        return response()->json(['post' => $this->normalisePostImage($post->load(['category:id,name,slug', 'tags:id,name,slug']))]);
    }

    public function storePost(Request $request): JsonResponse
    {
        $validated = $this->validatedPost($request);
        $tagIds = $validated['tags'] ?? null;
        unset($validated['tags']);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        $validated = $this->normalisePublication($validated);
        $post = BlogPost::query()->create($validated);
        if ($tagIds !== null) {
            $post->tags()->sync($tagIds);
        }
        $post->load(['category:id,name,slug', 'tags:id,name,slug']);

        $this->normalisePostImage($post);

        return response()->json(['message' => 'Blog post created successfully.', 'post' => $post], 201);
    }

    public function updatePost(Request $request, BlogPost $post): JsonResponse
    {
        $previousImagePath = $post->image_path;
        $validated = $this->validatedPost($request, $post);
        $tagIds = $validated['tags'] ?? null;
        unset($validated['tags']);
        if (array_key_exists('title', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = Str::slug($validated['title']);
        }
        $post->update($this->normalisePublication($validated));
        if ($tagIds !== null) {
            $post->tags()->sync($tagIds);
        }

        if (array_key_exists('image_path', $validated) && $this->storageReference($previousImagePath) !== $this->storageReference($post->image_path)) {
            $this->deleteImageIfUnused($previousImagePath, $post->id);
        }

        return response()->json(['message' => 'Blog post updated successfully.', 'post' => $this->normalisePostImage($post->fresh()->load(['category:id,name,slug', 'tags:id,name,slug']))]);
    }

    public function destroyPost(BlogPost $post): JsonResponse
    {
        $post->delete();

        return response()->json(['message' => 'Blog post deleted successfully.']);
    }

    public function comments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $comments = BlogComment::query()
            ->with('post:id,title,slug')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%")))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json($comments);
    }

    public function updateComment(Request $request, BlogComment $comment): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['pending', 'approved', 'rejected'])]]);
        $comment->update([
            'status' => $validated['status'],
            'approved_at' => $validated['status'] === 'approved' ? now() : null,
        ]);

        return response()->json(['message' => 'Comment status updated.', 'comment' => $comment->fresh()->load('post:id,title,slug')]);
    }

    public function destroyComment(BlogComment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function categories(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $categories = BlogCategory::query()
            ->with(['parent:id,name,slug'])
            ->withCount('posts')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json($categories);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $this->validatedCategory($request);
        $validated['slug'] ??= Str::slug($validated['name']);
        $category = BlogCategory::query()->create($validated);

        return response()->json(['message' => 'Blog category created successfully.', 'category' => $category->loadCount('posts')], 201);
    }

    public function updateCategory(Request $request, BlogCategory $category): JsonResponse
    {
        $validated = $this->validatedCategory($request, $category);
        if (array_key_exists('name', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = Str::slug($validated['name']);
        }
        $category->update($validated);

        return response()->json(['message' => 'Blog category updated successfully.', 'category' => $category->fresh()->loadCount('posts')]);
    }

    public function destroyCategory(BlogCategory $category): JsonResponse
    {
        if ($category->posts()->exists()) {
            return response()->json(['message' => 'This category cannot be deleted while blog posts are associated with it.'], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Blog category deleted successfully.']);
    }

    public function tags(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tags = BlogTag::query()
            ->withCount('posts')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return response()->json($tags);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $validated = $this->validatedTag($request);
        $validated['slug'] ??= Str::slug($validated['name']);
        $tag = BlogTag::query()->create($validated);

        return response()->json(['message' => 'Blog tag created successfully.', 'tag' => $tag->loadCount('posts')], 201);
    }

    public function updateTag(Request $request, BlogTag $tag): JsonResponse
    {
        $validated = $this->validatedTag($request, $tag);
        if (array_key_exists('name', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = Str::slug($validated['name']);
        }
        $tag->update($validated);

        return response()->json(['message' => 'Blog tag updated successfully.', 'tag' => $tag->fresh()->loadCount('posts')]);
    }

    public function destroyTag(BlogTag $tag): JsonResponse
    {
        if ($tag->posts()->exists()) {
            return response()->json(['message' => 'This tag cannot be deleted while blog posts are associated with it.'], 409);
        }

        $tag->delete();

        return response()->json(['message' => 'Blog tag deleted successfully.']);
    }

    public function widgets(): JsonResponse
    {
        return response()->json(['widgets' => BlogWidget::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storeWidget(Request $request): JsonResponse
    {
        $widget = BlogWidget::query()->create($this->validatedWidget($request));

        return response()->json(['message' => 'Blog widget created successfully.', 'widget' => $widget], 201);
    }

    public function updateWidget(Request $request, BlogWidget $widget): JsonResponse
    {
        $widget->update($this->validatedWidget($request, $widget));

        return response()->json(['message' => 'Blog widget updated successfully.', 'widget' => $widget->fresh()]);
    }

    public function destroyWidget(BlogWidget $widget): JsonResponse
    {
        $widget->delete();

        return response()->json(['message' => 'Blog widget deleted successfully.']);
    }

    public function reorderWidgets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:blog_widgets,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $item) {
                BlogWidget::query()->whereKey($item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['message' => 'Blog widget order saved.', 'widgets' => BlogWidget::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    private function validatedPost(Request $request, ?BlogPost $post = null): array
    {
        return $request->validate([
            'blog_category_id' => ['sometimes', 'required', 'integer', 'exists:blog_categories,id'],
            'title' => ['sometimes', 'required', 'string', 'max:220'],
            'slug' => ['nullable', 'string', 'max:240', 'alpha_dash', Rule::unique('blog_posts', 'slug')->ignore($post?->id)],
            'excerpt' => ['nullable', 'string', 'max:5000'],
            'content' => ['sometimes', 'required', 'string'],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'image_alt' => ['nullable', 'string', 'max:220'],
            'author_name' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'scheduled'])],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'read_time_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
            'seo_title' => ['nullable', 'string', 'max:220'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'focus_keyphrase' => ['nullable', 'string', 'max:180'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'distinct', 'exists:blog_tags,id'],
        ]);
    }

    private function normalisePublication(array $validated): array
    {
        if (($validated['status'] ?? null) === 'published') {
            $validated['published_at'] ??= now();
        } elseif (($validated['status'] ?? null) === 'scheduled') {
            $validated['published_at'] ??= now()->addDay();
        } elseif (($validated['status'] ?? null) === 'draft') {
            $validated['published_at'] = null;
        }

        return $validated;
    }

    private function normalisePostImage(BlogPost $post): BlogPost
    {
        $post->setAttribute('image_path', $this->imageUrl($post->image_path));

        return $post;
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $reference = $this->storageReference($path);

        if ($reference && $reference['disk'] === 'public') {
            return $this->publicStorageUrl($reference['path']);
        }

        if ($reference && $reference['disk'] === 'frontend_public') {
            return url('/api/blog/images/'.rawurlencode(basename($reference['path'])));
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
    }

    private function publicStorageUrl(string $path): string
    {
        $storageUrl = Storage::disk('public')->url($path);

        return Str::startsWith($storageUrl, ['http://', 'https://'])
            ? $storageUrl
            : url($storageUrl);
    }

    private function storageReference(?string $value): ?array
    {
        if (! $value) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH) ?: $value;

        if (Str::startsWith($path, '/storage/blogs/')) {
            return ['disk' => 'public', 'path' => ltrim(Str::after($path, '/storage/'), '/')];
        }

        if (Str::startsWith($path, 'blogs/')) {
            return ['disk' => 'public', 'path' => $path];
        }

        if (Str::startsWith($path, 'storage/blogs/')) {
            return ['disk' => 'public', 'path' => Str::after($path, 'storage/')];
        }

        if (Str::startsWith($path, '/images/blog/')) {
            return ['disk' => 'frontend_public', 'path' => ltrim($path, '/')];
        }

        if (Str::startsWith($path, '/api/blog/images/')) {
            return ['disk' => 'frontend_public', 'path' => 'images/blog/'.basename($path)];
        }

        return null;
    }

    private function deleteImageIfUnused(?string $value, int $exceptPostId): void
    {
        $reference = $this->storageReference($value);
        if (! $reference) {
            return;
        }

        $isReferenced = BlogPost::query()
            ->whereKeyNot($exceptPostId)
            ->whereNotNull('image_path')
            ->pluck('image_path')
            ->contains(fn ($path) => $this->storageReference($path) === $reference);

        if (! $isReferenced) {
            Storage::disk($reference['disk'])->delete($reference['path']);
        }
    }

    private function validatedCategory(Request $request, ?BlogCategory $category = null): array
    {
        return $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'alpha_dash', Rule::unique('blog_categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')->where(fn ($query) => $query->where('id', '!=', $category?->id ?? 0))],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validatedWidget(Request $request, ?BlogWidget $widget = null): array
    {
        return $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('blog_widgets', 'key')->ignore($widget?->id)],
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', Rule::in(['custom', 'updates', 'ad', 'search', 'categories', 'popular_posts', 'newsletter', 'adsense'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ]);
    }

    private function validatedTag(Request $request, ?BlogTag $tag = null): array
    {
        return $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'alpha_dash', Rule::unique('blog_tags', 'slug')->ignore($tag?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
