<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->string('curriculum_reference', 500)->nullable();
        });

        Schema::create('operational_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('training_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 50)->index();
            $table->string('title', 180);
            $table->string('original_name');
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'category']);
        });

        Schema::create('outgoing_document_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 60);
            $table->string('reference_number', 100)->nullable();
            $table->string('recipient', 180)->nullable();
            $table->string('subject', 255);
            $table->date('sent_at');
            $table->string('method', 50)->default('physical');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_document_logs');
        Schema::dropIfExists('operational_documents');
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['description', 'requirements', 'curriculum_reference']);
        });
    }
};
