<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_success_stories', function (Blueprint $table) {
            $table->id();
            $table->string('student_name', 160);
            $table->string('designation', 180);
            $table->string('category', 40);
            $table->text('description');
            $table->string('achievement_label', 120)->nullable();
            $table->string('achievement_value', 120)->nullable();
            $table->string('video_type', 20)->nullable();
            $table->string('video_path')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->json('social_links')->nullable();
            $table->string('video_duration', 20)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['status', 'category', 'display_order']);
            $table->index(['status', 'is_featured', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_success_stories');
    }
};
