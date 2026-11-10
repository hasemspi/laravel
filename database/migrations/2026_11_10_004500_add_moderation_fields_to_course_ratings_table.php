<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_ratings', function (Blueprint $table) {
            $table->string('approval_status', 32)->default('pending')->after('review');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->index(['course_id', 'approval_status', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::table('course_ratings', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['course_id', 'approval_status', 'rating']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at']);
        });
    }
};
