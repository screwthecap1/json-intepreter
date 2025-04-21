<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\XmlUploadController;

Route::get('/', [XmlUploadController::class, 'index'])->name('xml.index');
Route::post('/upload', [XmlUploadController::class, 'upload'])->name('xml.upload');
Route::post('/filter', [XmlUploadController::class, 'filter'])->name('xml.filter');

