<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('group_project_count')->default(0)->after('undertaking_total');
            $table->decimal('group_beneficiary_total', 14, 2)->default(0)->after('group_project_count');
            $table->unsignedInteger('group_undertaking_total')->default(0)->after('group_beneficiary_total');
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropUnique('import_rows_batch_muni_brgy_unique');

            $table->boolean('is_group_project')->default(false)->after('barangay_key');
            $table->string('group_project_key')->nullable()->after('is_group_project');
            $table->string('group_project_label')->nullable()->after('group_project_key');

            $table->index(
                ['import_batch_id', 'is_group_project', 'municipality_key', 'barangay_key'],
                'import_rows_batch_type_muni_brgy_idx'
            );
            $table->index(
                ['import_batch_id', 'is_group_project', 'group_project_key'],
                'import_rows_batch_group_project_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropIndex('import_rows_batch_type_muni_brgy_idx');
            $table->dropIndex('import_rows_batch_group_project_idx');
            $table->dropColumn([
                'is_group_project',
                'group_project_key',
                'group_project_label',
            ]);

            $table->unique(
                ['import_batch_id', 'municipality_key', 'barangay_key'],
                'import_rows_batch_muni_brgy_unique'
            );
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'group_project_count',
                'group_beneficiary_total',
                'group_undertaking_total',
            ]);
        });
    }
};
