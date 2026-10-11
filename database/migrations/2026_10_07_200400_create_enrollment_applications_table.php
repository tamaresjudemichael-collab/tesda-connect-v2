<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 40)->unique();
            $table->foreignId('learner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('learner_name');
            $table->string('email')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('application_status', 30)->default('draft')->index();
            $table->unsignedTinyInteger('record_completeness')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('scholar_id', 40)->nullable()->unique();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_applications');
    }
};
