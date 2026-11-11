<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('description')->constrained('blog_categories')->nullOnDelete();
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->string('seo_title', 220)->nullable()->after('read_time_minutes');
            $table->text('meta_description')->nullable()->after('seo_title');
            $table->string('focus_keyphrase', 180)->nullable()->after('meta_description');
        });

        Schema::create('blog_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained('blog_tags')->cascadeOnDelete();
            $table->primary(['blog_post_id', 'blog_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_tags');
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'meta_description', 'focus_keyphrase']);
        });
        Schema::table('blog_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
