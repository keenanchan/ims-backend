<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('current_version_id');
            $table->index('updated_at');
        });

        Schema::table('form_submission_versions', function (Blueprint $table) {
            $table->unsignedInteger('base_version_number')->nullable()->after('version_number');
            $table->uuid('client_uuid')->nullable()->unique()->after('base_version_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropColumn('client_uuid');
        });

        Schema::table('form_submission_versions', function (Blueprint $table) {
            $table->dropColumn(['base_version_number', 'client_uuid']);
        });
    }
};
