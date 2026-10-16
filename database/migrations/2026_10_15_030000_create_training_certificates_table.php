<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_application_id')
                ->constrained('enrollment_applications')
                ->cascadeOnDelete();
            $table->unique('enrollment_application_id');
            $table->string('certificate_number', 80)->nullable()->unique();
            $table->string('status', 30)->default('not_completed')->index();
            $table->json('pending_units')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_certificates');
    }
};
