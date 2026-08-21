<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about-importer', function () {
    $this->info('Provincial Miro Importer');
    $this->line('Upload one province spreadsheet, preview the aggregation, then sync it to Miro.');
})->purpose('Display importer information');
   