<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_unit_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_application_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('competency_unit_id')
                ->constrained('competency_units')
                ->cascadeOnDelete();

            $table->string('status', 30)->default('pending_review');

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique([
                'enrollment_application_id',
                'competency_unit_id',
            ]);

            $table->index(['status', 'is_locked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_unit_reviews');
    }
};