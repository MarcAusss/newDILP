<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('regular_project_count')->default(0)->after('undertaking_total');
            $table->unsignedInteger('total_approved_projects')->default(0)->after('group_project_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'regular_project_count',
                'total_approved_projects',
            ]);
        });
    }
};
