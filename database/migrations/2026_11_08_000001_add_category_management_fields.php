<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('course_categories', 'icon')) {
                $table->string('icon', 80)->nullable()->after('description');
            }
            if (! Schema::hasColumn('course_categories', 'status')) {
                $table->string('status', 16)->default('active')->after('icon')->index();
            }
        });

        DB::table('course_categories')->where('is_active', false)->update(['status' => 'inactive']);
        DB::table('course_categories')->where('is_active', true)->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('course_categories', function (Blueprint $table) {
            if (Schema::hasColumn('course_categories', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('course_categories', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
