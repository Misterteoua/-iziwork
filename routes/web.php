<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\SubmissionController;
use App\Http\Middleware\AdminAuth;

// Home page
Route::get('/', function () {
    return view('welcome');
});

// Auth routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Student routes (public)
Route::get('/s/{token}', [SubmissionController::class, 'showForm'])->name('submit.form');
Route::post('/s/{token}', [SubmissionController::class, 'submit'])->name('submit.process');
Route::get('/s/{token}/recap/{submission}', [SubmissionController::class, 'recap'])->name('submission.recap');
Route::get('/s/{token}/recap/{submission}/pdf', [SubmissionController::class, 'downloadRecap'])->name('submission.recap.pdf');

// Admin routes (protected)
Route::prefix('admin')->middleware(AdminAuth::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    
    // Forms - using admin.forms prefix
    Route::get('forms', [FormController::class, 'index'])->name('admin.forms.index');
    Route::get('forms/create', [FormController::class, 'create'])->name('admin.forms.create');
    Route::post('forms', [FormController::class, 'store'])->name('admin.forms.store');
    Route::get('forms/{form}', [FormController::class, 'show'])->name('admin.forms.show');
    Route::get('forms/{form}/edit', [FormController::class, 'edit'])->name('admin.forms.edit');
    Route::put('forms/{form}', [FormController::class, 'update'])->name('admin.forms.update');
    Route::delete('forms/{form}', [FormController::class, 'destroy'])->name('admin.forms.destroy');
    Route::patch('forms/{form}/toggle-status', [FormController::class, 'toggleStatus'])->name('admin.forms.toggle');
    Route::post('forms/{form}/copy-link', [FormController::class, 'copyLink'])->name('admin.forms.copyLink');
    
    // Submissions
    Route::get('forms/{form}/submissions', [SubmissionController::class, 'adminIndex'])->name('admin.submissions.index');
    Route::get('forms/{form}/submissions/{submission}', [SubmissionController::class, 'adminShow'])->name('admin.submissions.show');
    Route::get('submissions/{file}/download', [SubmissionController::class, 'downloadFile'])->name('admin.submissions.download');
    Route::get('forms/{form}/submissions/bulk-download', [SubmissionController::class, 'downloadBulk'])->name('admin.submissions.bulk');
});
