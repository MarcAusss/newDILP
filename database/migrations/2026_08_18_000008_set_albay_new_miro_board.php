<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $boardId = 'uXjVHxVb-XA=';

    public function up(): void
    {
        // The new board supplied for the Albay mapping reference.
        // Other provinces are intentionally left unchanged.
        DB::table('provinces')
            ->where('name', 'Albay')
            ->update([
                'miro_board_id' => $this->boardId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('provinces')
            ->where('name', 'Albay')
            ->where('miro_board_id', $this->boardId)
            ->update([
                'miro_board_id' => null,
                'updated_at' => now(),
            ]);
    }
};
