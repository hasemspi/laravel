<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('url', 2048);
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['parent_id', 'position']);
        });

        // Preserve the existing public navigation on first migration; future changes are admin-managed.
        DB::table('navigation_items')->insert([
            ['label' => 'Home', 'url' => '/', 'position' => 0, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'About', 'url' => '/about', 'position' => 1, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Courses', 'url' => '/courses', 'position' => 2, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Trainers', 'url' => '/trainers', 'position' => 3, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Student Success', 'url' => '/student-success', 'position' => 4, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Blog', 'url' => '/blog', 'position' => 5, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['label' => 'Contact', 'url' => '/contact', 'position' => 6, 'is_visible' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }
};
