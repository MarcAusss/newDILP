<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipality_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('municipality');
            $table->string('municipality_key');
            $table->integer('sort_order')->default(0);
            $table->integer('anchor_x')->default(0);
            $table->integer('anchor_y')->default(0);
            $table->integer('panel_x')->default(500);
            $table->integer('panel_y')->default(0);
            $table->enum('flow_direction', ['right', 'left', 'down', 'up'])->default('right');
            $table->boolean('configured')->default(false);
            $table->timestamps();

            $table->unique(['province_id', 'municipality_key']);
            $table->index(['province_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_mappings');
    }
};
