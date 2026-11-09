<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('batch_number');
            $table->timestamp('release_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'batch_number']);
            $table->index(['course_id', 'status', 'release_date']);
        });

        Schema::create('batch_trainers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_batch_id')->constrained('course_batches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['course_batch_id', 'user_id']);
        });

        Schema::create('batch_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_batch_id')->constrained('course_batches')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->unique(['course_batch_id', 'sort_order']);
        });

        Schema::create('batch_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_module_id')->constrained('batch_modules')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('video_reference')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('unlock_type', 32)->default('sequential');
            $table->timestamp('unlock_at')->nullable();
            $table->unsignedInteger('unlock_delay_hours')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_published')->default(false)->index();
            $table->string('approval_status', 24)->default('approved')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();
            $table->unique(['batch_module_id', 'sort_order']);
        });

        Schema::create('course_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->boolean('lifetime_access_enabled')->default(false);
            $table->boolean('future_batch_access')->default(false);
            $table->string('release_rule', 48)->default('published_after_enrollment');
            $table->timestamps();
            $table->unique('course_id');
        });

        Schema::create('enrollment_batch_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_batch_id')->constrained('course_batches')->cascadeOnDelete();
            $table->string('access_source', 24)->default('manual');
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'course_batch_id']);
            $table->index(['enrollment_id', 'revoked_at']);
        });

        Schema::create('batch_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_batch_id')->constrained('course_batches')->cascadeOnDelete();
            $table->foreignId('batch_lesson_id')->constrained('batch_lessons')->cascadeOnDelete();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->boolean('is_completed')->default(false)->index();
            $table->timestamp('last_watched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'batch_lesson_id']);
            $table->index(['user_id', 'course_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_lesson_progress');
        Schema::dropIfExists('enrollment_batch_access');
        Schema::dropIfExists('course_access_rules');
        Schema::dropIfExists('batch_lessons');
        Schema::dropIfExists('batch_modules');
        Schema::dropIfExists('batch_trainers');
        Schema::dropIfExists('course_batches');
    }
};
