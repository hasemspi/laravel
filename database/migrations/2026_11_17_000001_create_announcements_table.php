<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->string('type', 30)->index();
            $table->string('audience_type', 30)->index();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->text('description');
            $table->string('external_url', 2048)->nullable();
            $table->string('status', 20)->index();
            $table->timestamps();
            $table->index(['status', 'audience_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
