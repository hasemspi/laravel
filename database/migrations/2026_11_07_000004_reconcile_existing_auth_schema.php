<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This project may be connected to a legacy database whose users table
        // predates Laravel's migration ledger. Preserve that table and fill in
        // only missing auth support structures.
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'role') || ! Schema::hasColumn('users', 'status')) {
                Schema::table('users', function (Blueprint $table) {
                    if (! Schema::hasColumn('users', 'role')) {
                        $table->string('role', 32)->default('student');
                    }
                    if (! Schema::hasColumn('users', 'status')) {
                        $table->string('status', 32)->default('active');
                    }
                });
            }

            if (! Schema::hasIndex('users', ['role', 'status'])) {
                Schema::table('users', function (Blueprint $table) {
                    $table->index(['role', 'status']);
                });
            }
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        // This is an additive compatibility migration: rollback must not drop
        // legacy auth structures or session/password-reset data.
    }
};
