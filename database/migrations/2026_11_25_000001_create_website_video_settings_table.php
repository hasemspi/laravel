<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_video_settings', function (Blueprint $table) {
            $table->id();
            $table->string('video_type', 20);
            $table->string('video_path')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('youtube_video_id', 20)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_video_settings');
    }
};
