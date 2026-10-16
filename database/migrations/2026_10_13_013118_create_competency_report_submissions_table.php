<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_report_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_application_id')
                ->constrained('enrollment_applications')
                ->cascadeOnDelete();

            $table->unique('enrollment_application_id');

            $table->string('status', 30)->default('draft')->index();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->foreignId('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();

            $table->string('submission_reference')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_report_submissions');
    }
};