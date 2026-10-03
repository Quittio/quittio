<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Quittio\Laravel\Http\PreviewController;

Route::get('/', [PreviewController::class, 'index'])->name('quittio.preview.index');
Route::get('/{scenario}', [PreviewController::class, 'show'])->name('quittio.preview.show');
