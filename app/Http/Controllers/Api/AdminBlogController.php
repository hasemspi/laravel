<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminBlogController extends Controller
{
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $directory = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'blog';
        File::ensureDirectoryExists($directory);
        $image = $request->file('image');
        $filename = Str::uuid().'.'.$image->extension();
        $image->move($directory, $filename);

        return response()->json([
            'message' => 'Image uploaded successfully.',
            'path' => '/images/blog/'.$filename,
        ], 201);
    }

    public function posts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['draft', 'published'])],
            'category' => ['nullable', 'string', 'max:140'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $posts = BlogPost::query()
            ->with('category:id,name,slug')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('title', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas('category', fn ($nested) => $nested->where('slug', $category)))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return response()->json($posts);
    }

    public function post(BlogPost $post): JsonResponse
    {
        return response()->json(['post' => $post->load('category:id,name,slug')]);
    }

    public function storePost(Request $request): JsonResponse
    {
        $validated = $this->validatedPost($request);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);
        $validated = $this->normalisePublication($validated);
        $post = BlogPost::query()->create($validated)->load('category:id,name,slug');

        return response()->json(['message' => 'Blog post created successfully.', 'post' => $post], 201);
    }

    public function updatePost(Request $request, BlogPost $post): JsonResponse
    {
        $validated = $this->validatedPost($request, $post);
        if (array_key_exists('title', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = Str::slug($validated['title']);
        }
        $post->update($this->normalisePublication($validated));

        return response()->json(['message' => 'Blog post updated successfully.', 'post' => $post->fresh()->load('category:id,name,slug')]);
    }

    public function destroyPost(BlogPost $post): JsonResponse
    {
        $post->delete();

        return response()->json(['message' => 'Blog post deleted successfully.']);
    }

    public function categories(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $categories = BlogCategory::query()
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
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'read_time_minutes' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
    }

    private function normalisePublication(array $validated): array
    {
        if (($validated['status'] ?? null) === 'published') {
            $validated['published_at'] ??= now();
        } elseif (($validated['status'] ?? null) === 'draft') {
            $validated['published_at'] = null;
        }

        return $validated;
    }

    private function validatedCategory(Request $request, ?BlogCategory $category = null): array
    {
        return $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'alpha_dash', Rule::unique('blog_categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validatedWidget(Request $request, ?BlogWidget $widget = null): array
    {
        return $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('blog_widgets', 'key')->ignore($widget?->id)],
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', Rule::in(['custom', 'updates', 'ad'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ]);
    }
}
