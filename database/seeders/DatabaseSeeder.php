<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            'Albay',
            'Camarines Norte',
            'Camarines Sur',
            'Catanduanes',
            'Masbate',
            'Sorsogon',
        ];

        foreach ($provinces as $index => $name) {
            Province::updateOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
                    'sheet_name' => $name,
                    'base_x' => ($index % 2) * 9000,
                    'base_y' => intdiv($index, 2) * 9000,
                    'active' => true,
                ],
            );
        }
    }
}
