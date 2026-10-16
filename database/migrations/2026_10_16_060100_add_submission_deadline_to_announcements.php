<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('announcements', 'deadline_at')) {
            Schema::table('announcements', function (Blueprint $table): void {
                $table->dateTime('deadline_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('announcements', 'deadline_at')) {
            Schema::table('announcements', function (Blueprint $table): void {
                $table->dropIndex(['deadline_at']);
                $table->dropColumn('deadline_at');
            });
        }
    }
};
