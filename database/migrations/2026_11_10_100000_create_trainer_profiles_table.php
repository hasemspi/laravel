<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('professional_title', 180)->nullable();
            $table->text('bio')->nullable();
            $table->text('professional_experience')->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->json('expertise')->nullable();
            $table->json('skills')->nullable();
            $table->json('teaching_subjects')->nullable();
            $table->string('current_job', 180)->nullable();
            $table->string('company', 180)->nullable();
            $table->string('designation', 180)->nullable();
            $table->json('education')->nullable();
            $table->json('certifications')->nullable();
            $table->json('awards')->nullable();
            $table->string('youtube_intro_url', 2048)->nullable();
            $table->string('facebook_url', 2048)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('youtube_url', 2048)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->json('languages')->nullable();
            $table->json('portfolio_projects')->nullable();
            $table->boolean('is_verified')->default(false)->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_profiles');
    }
};
