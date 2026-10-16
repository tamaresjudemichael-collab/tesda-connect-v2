<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('training_batches', 'next_class_at')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->dateTime('next_class_at')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('training_batches', 'venue')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->string('venue')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('training_batches', 'next_class_at')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->dropIndex(['next_class_at']);
                $table->dropColumn('next_class_at');
            });
        }

        if (Schema::hasColumn('training_batches', 'venue')) {
            Schema::table('training_batches', function (Blueprint $table): void {
                $table->dropColumn('venue');
            });
        }
    }
};
