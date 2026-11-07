<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('video_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            // Keep this private reference out of public course serializers.
            $table->string('video_reference')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->enum('unlock_type', ['immediate', 'sequential', 'scheduled', 'sequential_scheduled'])->default('sequential');
            $table->timestamp('unlock_at')->nullable();
            $table->unsignedInteger('unlock_delay_hours')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamps();
            $table->unique(['module_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_lessons');
    }
};
