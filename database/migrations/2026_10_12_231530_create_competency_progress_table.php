<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_application_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('competency_task_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status', 30)->default('not_assessed');
            $table->text('remarks')->nullable();

            $table->foreignId('assessed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assessed_at')->nullable();

            $table->timestamps();

            $table->unique([
                'enrollment_application_id',
                'competency_task_id',
            ]);

            $table->index(['status', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_progress');
    }
};