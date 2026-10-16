<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_application_id')->nullable()
                ->constrained('enrollment_applications')->nullOnDelete();
            $table->string('reference_no', 40)->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['reference_no', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_status_histories');
    }
};
