<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('mutation_id');
            $table->string('operation', 80);
            $table->char('payload_hash', 64);
            $table->json('result')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'mutation_id']);
            $table->index(['operation', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_mutations');
    }
};
