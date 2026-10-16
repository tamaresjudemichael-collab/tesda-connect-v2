
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(
                ['institution_id', 'created_at'],
                'activity_logs_inst_created_idx'
            );

            $table->index(
                ['event', 'created_at'],
                'activity_logs_event_created_idx'
            );

            $table->index(
                ['user_id', 'created_at'],
                'activity_logs_user_created_idx'
            );

            $table->index(
                'created_at',
                'activity_logs_created_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_inst_created_idx');
            $table->dropIndex('activity_logs_event_created_idx');
            $table->dropIndex('activity_logs_user_created_idx');
            $table->dropIndex('activity_logs_created_at_idx');
        });
    }
};