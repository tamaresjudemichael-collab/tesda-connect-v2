<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_views') && ! Schema::hasColumn('announcement_views', 'read_at')) {
            Schema::table('announcement_views', function (Blueprint $table): void {
                $table->timestamp('read_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('announcement_views') && Schema::hasColumn('announcement_views', 'read_at')) {
            Schema::table('announcement_views', function (Blueprint $table): void {
                $table->dropColumn('read_at');
            });
        }
    }
};
