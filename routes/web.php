<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\PdfUploadController;

Route::get('/upload', [PdfUploadController::class, 'showForm'])->name('upload.form');
Route::post('/upload', [PdfUploadController::class, 'handleUpload'])->name('upload.handle');



