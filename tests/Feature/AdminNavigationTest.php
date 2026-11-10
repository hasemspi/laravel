<?php

namespace Tests\Feature;

use App\Models\NavigationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_nest_reorder_and_delete_navigation_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $parentResponse = $this->actingAs($admin)->postJson('/api/admin/navigation', ['label' => 'Resources', 'url' => '/resources']);
        $parentResponse->assertCreated();
        $parent = NavigationItem::query()->where('label', 'Resources')->firstOrFail();

        $childResponse = $this->actingAs($admin)->postJson('/api/admin/navigation', ['label' => 'Guides', 'url' => '/guides', 'parent_id' => $parent->id]);
        $childResponse->assertCreated()->assertJsonPath('item.parent_id', $parent->id);
        $child = NavigationItem::query()->where('label', 'Guides')->firstOrFail();

        $this->actingAs($admin)->patchJson('/api/admin/navigation/'.$child->id, ['label' => 'Learning Guides', 'is_visible' => false])->assertOk()->assertJsonPath('item.label', 'Learning Guides');
        $this->actingAs($admin)->patchJson('/api/admin/navigation/'.$parent->id, ['parent_id' => $child->id])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson('/api/admin/navigation/'.$parent->id)->assertConflict();

        $second = NavigationItem::query()->create(['label' => 'About second', 'url' => '/about-second', 'position' => 20, 'is_visible' => true, 'is_active' => true]);
        $this->actingAs($admin)->postJson('/api/admin/navigation/reorder', ['item_ids' => [$second->id, $parent->id]])->assertOk();
        $this->getJson('/api/navigation')->assertOk()->assertJsonFragment(['label' => 'Resources'])->assertJsonFragment(['label' => 'Home']);
        $customItems = collect($this->actingAs($admin)->getJson('/api/admin/navigation')->json('items'))->whereIn('id', [$second->id, $parent->id])->sortBy('position')->values();
        $this->assertSame('About second', $customItems[0]['label']);

        $this->actingAs($admin)->deleteJson('/api/admin/navigation/'.$child->id)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/admin/navigation/'.$parent->id)->assertOk();
        $this->assertDatabaseMissing('navigation_items', ['id' => $parent->id]);
    }

    public function test_navigation_admin_api_requires_an_active_admin(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/admin/navigation')->assertForbidden();
    }
}
