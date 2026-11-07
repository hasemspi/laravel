<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trainer assignments are owned by the consolidated LMS course migration.
    }

    public function down(): void
    {
        // Retained as a no-op so this legacy migration cannot drop LMS data.
    }
};
