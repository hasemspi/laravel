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
        // Course schema is owned by the consolidated LMS migration
        // 2026_11_07_100000_create_lms_course_tables.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retained as a no-op so this legacy migration cannot drop LMS data.
    }
};
