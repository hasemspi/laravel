<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'display_rating')) {
                $table->decimal('display_rating', 3, 1)->nullable()->after('display_student_count');
            }
            if (! Schema::hasColumn('courses', 'display_review_count')) {
                $table->unsignedInteger('display_review_count')->nullable()->after('display_rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            foreach (['display_rating', 'display_review_count'] as $column) {
                if (Schema::hasColumn('courses', $column)) $table->dropColumn($column);
            }
        });
    }
};
