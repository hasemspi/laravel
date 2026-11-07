<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'discount_price')) {
                $table->decimal('discount_price', 10, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('courses', 'level')) {
                $table->string('level', 32)->default('beginner')->after('discount_price');
            }
            if (! Schema::hasColumn('courses', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('level')->index();
            }
            if (! Schema::hasColumn('courses', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            foreach (['discount_price', 'level', 'is_featured', 'deleted_at'] as $column) {
                if (Schema::hasColumn('courses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
