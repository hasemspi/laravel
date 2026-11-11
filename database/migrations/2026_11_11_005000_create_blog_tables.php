<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_category_id')->constrained('blog_categories')->restrictOnDelete();
            $table->string('title', 220);
            $table->string('slug', 240)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('image_path')->nullable();
            $table->string('image_alt', 220)->nullable();
            $table->string('author_name', 160)->default('TrustyGen Team');
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedSmallInteger('read_time_minutes')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->string('type', 40)->default('custom');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_widgets');
        Schema::dropIfExists('blog_categories');
    }
};
