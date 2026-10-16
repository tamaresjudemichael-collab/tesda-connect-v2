<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competency_unit_id')
                ->constrained('competency_units')
                ->cascadeOnDelete();

            $table->string('chart_type', 30);
            $table->unsignedSmallInteger('task_number');
            $table->string('name', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique([
                'competency_unit_id',
                'chart_type',
                'task_number',
            ]);

            $table->index([
                'competency_unit_id',
                'chart_type',
                'sort_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_tasks');
    }
};