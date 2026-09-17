<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->unique()->nullable()->after('email');
            $table->string('account_status')->default('active')->index()->after('password');
            $table->unsignedInteger('failed_login_count')->default(0)->after('account_status');
            $table->timestamp('last_login_at')->nullable()->after('failed_login_count');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->boolean('two_factor_enabled')->default(false)->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropIndex(['account_status']);
            $table->dropColumn([
                'username',
                'account_status',
                'failed_login_count',
                'last_login_at',
                'password_changed_at',
                'two_factor_enabled',
            ]);
        });
    }
};
