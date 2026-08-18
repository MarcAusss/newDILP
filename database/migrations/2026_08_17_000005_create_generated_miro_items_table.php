<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_miro_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('stable_key');
            $table->string('item_type', 40);
            $table->string('miro_item_id');
            $table->string('label')->nullable();
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['province_id', 'stable_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_miro_items');
    }
};
