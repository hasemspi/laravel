<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'approval_status')) {
                $table->string('approval_status', 24)->default('approved')->after('is_published')->index();
            }
            if (! Schema::hasColumn('lessons', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('approval_status');
            }
            if (! Schema::hasColumn('lessons', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('lessons', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('lessons', 'rejection_note')) {
                $table->text('rejection_note')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (Schema::hasColumn('lessons', 'approved_by')) {
                $table->dropForeign(['approved_by']);
            }
            foreach (['approved_by', 'approval_status', 'submitted_at', 'approved_at', 'rejection_note'] as $column) {
                if (Schema::hasColumn('lessons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
