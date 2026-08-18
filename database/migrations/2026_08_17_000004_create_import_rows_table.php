<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('municipality');
            $table->string('municipality_key');
            $table->string('barangay');
            $table->string('barangay_key');
            $table->decimal('beneficiary_total', 14, 2)->default(0);
            $table->unsignedInteger('undertaking_count')->default(0);
            $table->json('undertakings');
            $table->json('source_rows')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'municipality_key', 'barangay_key'], 'import_rows_batch_muni_brgy_unique');
            $table->index(['import_batch_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
