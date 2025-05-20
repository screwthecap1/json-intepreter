<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\XmlUploadController;

Route::get('/', [XmlUploadController::class, 'index'])->name('xml.index');
Route::post('/upload', [XmlUploadController::class, 'upload'])->name('xml.upload');
Route::post('/filter', [XmlUploadController::class, 'filter'])->name('xml.filter');
Route::put('/term/update', [XmlUploadController::class, 'updateTerm'])->name('term.update');
Route::put('/term/update-custom', [XmlUploadController::class, 'customUpdateTerm'])->name('term.update.custom');
Route::delete('/terms/reset', [XmlUploadController::class, 'resetDefinitions'])->name('terms.reset');
Route::post('/xml/update-relationship', [XmlUploadController::class, 'updateRelationship'])->name('xml.relationship.update');
Route::get('/xml/export', [XmlUploadController::class, 'export'])->name('xml.export');


