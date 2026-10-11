<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('uli_number', 40)->nullable();
            $table->date('entry_date')->nullable();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('extension_name', 20)->nullable();
            $table->string('address_line')->nullable();
            $table->string('barangay')->nullable();
            $table->string('district')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('region')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('nationality')->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('civil_status', 30)->nullable();
            $table->string('employment_status', 30)->nullable();
            $table->string('employment_type', 30)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('birthplace_city')->nullable();
            $table->string('birthplace_province')->nullable();
            $table->string('birthplace_region')->nullable();
            $table->string('educational_attainment')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_address')->nullable();
            $table->json('classifications')->nullable();
            $table->json('disability_types')->nullable();
            $table->string('disability_cause', 30)->nullable();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scholarship_package')->nullable();
            $table->boolean('privacy_consent')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_profiles');
    }
};
