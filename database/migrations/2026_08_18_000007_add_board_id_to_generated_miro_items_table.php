<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_miro_items', function (Blueprint $table) {
            $table->string('board_id')->nullable()->after('province_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('generated_miro_items', function (Blueprint $table) {
            $table->dropIndex(['board_id']);
            $table->dropColumn('board_id');
        });
    }
};
