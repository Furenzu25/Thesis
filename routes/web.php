<?php

use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Video serving routes
Route::get('/videos/{video}/{type}', [VideoController::class, 'serve'])
    ->name('video.serve')
    ->where('type', 'original|processed');
