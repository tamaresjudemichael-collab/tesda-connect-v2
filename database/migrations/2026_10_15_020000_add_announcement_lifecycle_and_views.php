<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('published_at')->index();
            $table->timestamp('archived_at')->nullable()->after('expires_at')->index();
        });

        Schema::create('announcement_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_views');

        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['expires_at', 'archived_at']);
        });
    }
};
