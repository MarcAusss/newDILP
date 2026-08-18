<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('sheet_name');
            $table->enum('status', ['preview', 'processing', 'completed', 'failed'])->default('preview');
            $table->unsignedInteger('source_rows')->default(0);
            $table->unsignedInteger('municipality_count')->default(0);
            $table->unsignedInteger('barangay_count')->default(0);
            $table->decimal('beneficiary_total', 14, 2)->default(0);
            $table->unsignedInteger('undertaking_total')->default(0);
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
