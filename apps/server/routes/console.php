<?php

use App\Services\NameReferences\NameReferenceIndexService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('name-references:rebuild', function (NameReferenceIndexService $index) {
    $count = $index->rebuild();

    $this->info("Rebuilt {$count} Name Reference token(s) from IMS and Field Report source text.");
})->purpose('Rebuild the derived Name Reference index from IMS notes, Field Report bodies, and append bodies');
