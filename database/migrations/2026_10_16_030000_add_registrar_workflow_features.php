<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intake_periods') && ! Schema::hasColumn('intake_periods', 'institution_id')) {
            Schema::table('intake_periods', function (Blueprint $table): void {
                $table->foreignId('institution_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['institution_id', 'starts_at', 'ends_at'], 'intake_periods_institution_dates_index');
            });
        }

        if (! Schema::hasTable('intake_course_quotas')) {
            Schema::create('intake_course_quotas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('intake_period_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('quota')->default(1);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['intake_period_id', 'course_id'], 'intake_course_quotas_period_course_unique');
            });
        }

        if (! Schema::hasTable('enrollment_batch_forwardings')) {
            Schema::create('enrollment_batch_forwardings', function (Blueprint $table): void {
                $table->id();
                $table->string('reference_code', 40)->unique();
                $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->restrictOnDelete();
                $table->foreignId('training_batch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('forwarded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('forwarded_at')->index();
                $table->string('status', 40)->default('ready_for_scheduling')->index();
                $table->unsignedInteger('application_count')->default(0);
                $table->string('summary_path', 500);
                $table->string('summary_filename', 255);
                $table->timestamps();
                $table->index(['institution_id', 'course_id', 'forwarded_at'], 'forwarding_institution_course_date_index');
            });
        }

        if (Schema::hasTable('enrollment_applications')) {
            Schema::table('enrollment_applications', function (Blueprint $table): void {
                if (! Schema::hasColumn('enrollment_applications', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->index();
                }
                if (! Schema::hasColumn('enrollment_applications', 'archived_by_user_id')) {
                    $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('enrollment_applications', 'archive_reason')) {
                    $table->text('archive_reason')->nullable();
                }
                if (! Schema::hasColumn('enrollment_applications', 'forwarding_id')) {
                    $table->foreignId('forwarding_id')->nullable()->constrained('enrollment_batch_forwardings')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table): void {
                if (! Schema::hasColumn('announcements', 'attachment_path')) {
                    $table->string('attachment_path', 500)->nullable();
                }
                if (! Schema::hasColumn('announcements', 'attachment_name')) {
                    $table->string('attachment_name', 255)->nullable();
                }
                if (! Schema::hasColumn('announcements', 'attachment_mime')) {
                    $table->string('attachment_mime', 100)->nullable();
                }
                if (! Schema::hasColumn('announcements', 'attachment_size')) {
                    $table->unsignedBigInteger('attachment_size')->nullable();
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'password_expiry_exempt')) {
                    $table->boolean('password_expiry_exempt')->default(false);
                }
                if (! Schema::hasColumn('users', 'password_expires_at')) {
                    $table->timestamp('password_expires_at')->nullable();
                }
                if (! Schema::hasColumn('users', 'force_password_change')) {
                    $table->boolean('force_password_change')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                foreach (['password_expiry_exempt', 'password_expires_at', 'force_password_change'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table): void {
                foreach (['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'] as $column) {
                    if (Schema::hasColumn('announcements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('enrollment_applications')) {
            Schema::table('enrollment_applications', function (Blueprint $table): void {
                if (Schema::hasColumn('enrollment_applications', 'forwarding_id')) {
                    $table->dropConstrainedForeignId('forwarding_id');
                }
                if (Schema::hasColumn('enrollment_applications', 'archived_by_user_id')) {
                    $table->dropConstrainedForeignId('archived_by_user_id');
                }
                foreach (['archived_at', 'archive_reason'] as $column) {
                    if (Schema::hasColumn('enrollment_applications', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('intake_course_quotas');
        Schema::dropIfExists('enrollment_batch_forwardings');

        if (Schema::hasTable('intake_periods') && Schema::hasColumn('intake_periods', 'institution_id')) {
            Schema::table('intake_periods', function (Blueprint $table): void {
                $table->dropIndex('intake_periods_institution_dates_index');
                $table->dropConstrainedForeignId('institution_id');
            });
        }
    }
};
