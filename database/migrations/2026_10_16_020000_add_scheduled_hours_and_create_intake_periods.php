<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('training_batches') && ! Schema::hasColumn('training_batches', 'scheduled_hours')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->unsignedSmallInteger('scheduled_hours')->nullable()->after('capacity');
            });
        }

        if (! Schema::hasTable('intake_periods')) {
            Schema::create('intake_periods', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->date('starts_at');
                $table->date('ends_at');
                $table->unsignedInteger('quota')->default(100);
                $table->boolean('is_locked')->default(false)->index();
                $table->timestamp('locked_at')->nullable();
                $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['starts_at', 'ends_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_periods');

        if (Schema::hasTable('training_batches') && Schema::hasColumn('training_batches', 'scheduled_hours')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->dropColumn('scheduled_hours');
            });
        }
    }
};
