<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'message' => 'Use /api/v1 for the API.',
]));

