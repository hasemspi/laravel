<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CourseCategory::query()
            ->withCount('courses')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%"));
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name');

        $categories = $query->paginate($filters['per_page'] ?? 10)->withQueryString();

        return response()->json(array_merge($categories->toArray(), [
            'stats' => [
                'total' => CourseCategory::query()->count(),
                'active' => CourseCategory::query()->where('status', 'active')->count(),
                'inactive' => CourseCategory::query()->where('status', 'inactive')->count(),
                'assigned_courses' => DB::table('courses')->whereNull('deleted_at')->whereNotNull('course_category_id')->count(),
            ],
        ]));
    }

    public function show(CourseCategory $category): JsonResponse
    {
        return response()->json(['category' => $category->loadCount('courses')]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $validated['status'] === 'active';

        $category = CourseCategory::query()->create($validated);

        return response()->json(['message' => 'Category created successfully.', 'category' => $category->loadCount('courses')], 201);
    }

    public function update(Request $request, CourseCategory $category): JsonResponse
    {
        $validated = $this->validated($request, $category);
        if (array_key_exists('name', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = Str::slug($validated['name']);
        }
        if (array_key_exists('status', $validated)) {
            $validated['is_active'] = $validated['status'] === 'active';
        }

        $category->update($validated);

        return response()->json(['message' => 'Category updated successfully.', 'category' => $category->fresh()->loadCount('courses')]);
    }

    public function destroy(CourseCategory $category): JsonResponse
    {
        if ($category->courses()->withTrashed()->exists()) {
            return response()->json(['message' => 'This category cannot be deleted while courses are associated with it.'], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    private function validated(Request $request, ?CourseCategory $category = null): array
    {
        $id = $category?->id;
        if (! $request->filled('slug') && $request->filled('name')) {
            $request->merge(['slug' => Str::slug($request->input('name'))]);
        }
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', Rule::unique('course_categories', 'slug')->ignore($id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];

        if (! $category) {
            $rules['name'] = ['required', 'string', 'max:100'];
            $rules['status'] = ['sometimes', Rule::in(['active', 'inactive'])];
        }

        $validated = $request->validate($rules);
        if (! $category) {
            $validated['status'] ??= 'active';
        }

        return $validated;
    }
}
