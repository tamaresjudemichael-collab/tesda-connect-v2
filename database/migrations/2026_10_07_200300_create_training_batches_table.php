<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->default(25);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('status', 30)->default('upcoming')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_batches');
    }
};
