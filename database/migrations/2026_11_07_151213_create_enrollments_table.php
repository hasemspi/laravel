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
        // Enrollment schema is owned by the consolidated LMS learning migration.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retained as a no-op so this legacy migration cannot drop LMS data.
    }
};
