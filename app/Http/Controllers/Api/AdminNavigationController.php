<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNavigationController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        $items = NavigationItem::query()
            ->where('is_visible', true)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'label', 'url', 'parent_id', 'position']);

        return response()->json(['items' => $this->tree($items)]);
    }

    public function index(): JsonResponse
    {
        $items = NavigationItem::query()
            ->with('parent:id,label')
            ->withCount('children')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['position'] ??= $this->nextPosition($data['parent_id'] ?? null);
        $item = NavigationItem::query()->create($data);

        return response()->json(['message' => 'Navigation item created successfully.', 'item' => $item->load('parent:id,label')->loadCount('children')], 201);
    }

    public function update(Request $request, NavigationItem $navigationItem): JsonResponse
    {
        $data = $this->validated($request, $navigationItem);
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $navigationItem->parent_id;
        abort_if($this->createsCycle($navigationItem, $parentId), 422, 'A menu item cannot be moved inside its own submenu.');
        $navigationItem->update($data);

        return response()->json(['message' => 'Navigation item updated successfully.', 'item' => $navigationItem->fresh()->load('parent:id,label')->loadCount('children')]);
    }

    public function destroy(NavigationItem $navigationItem): JsonResponse
    {
        if ($navigationItem->children()->exists()) {
            return response()->json(['message' => 'Move or delete this item’s submenu items before deleting it.'], 409);
        }

        $navigationItem->delete();

        return response()->json(['message' => 'Navigation item deleted successfully.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:navigation_items,id'],
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer', 'distinct', 'exists:navigation_items,id'],
        ]);
        $parentId = $data['parent_id'] ?? null;
        $itemIds = array_values($data['item_ids']);
        if ($parentId !== null && in_array($parentId, $itemIds, true)) {
            abort(422, 'A menu item cannot be its own parent.');
        }

        $items = NavigationItem::query()->whereIn('id', $itemIds)->get()->keyBy('id');
        abort_unless($items->count() === count($itemIds) && $items->every(fn (NavigationItem $item) => (int) $item->parent_id === (int) $parentId), 422, 'Only items from the same menu level can be reordered together.');

        DB::transaction(function () use ($itemIds, $items, $parentId): void {
            foreach ($itemIds as $position => $itemId) {
                $items[$itemId]->update(['parent_id' => $parentId, 'position' => $position]);
            }
        });

        return response()->json(['message' => 'Navigation order updated successfully.']);
    }

    private function validated(Request $request, ?NavigationItem $item = null): array
    {
        $rules = [
            'label' => [$item ? 'sometimes' : 'required', 'string', 'max:100'],
            'url' => [$item ? 'sometimes' : 'required', 'string', 'max:2048'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:navigation_items,id'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $request->validate($rules);
    }

    private function nextPosition(?int $parentId): int
    {
        return ((int) NavigationItem::query()->where('parent_id', $parentId)->max('position')) + 1;
    }

    private function createsCycle(NavigationItem $item, ?int $parentId): bool
    {
        $current = $parentId ? NavigationItem::query()->find($parentId) : null;
        while ($current) {
            if ((int) $current->id === (int) $item->id) {
                return true;
            }
            $current = $current->parent_id ? NavigationItem::query()->find($current->parent_id) : null;
        }

        return false;
    }

    private function tree($items, ?int $parentId = null): array
    {
        return $items
            ->where('parent_id', $parentId)
            ->sortBy(fn (NavigationItem $item) => [$item->position, $item->id])
            ->map(fn (NavigationItem $item) => [
                'id' => $item->id,
                'label' => $item->label,
                'url' => $item->url,
                'position' => $item->position,
                'children' => $this->tree($items, $item->id),
            ])->values()->all();
    }
}
