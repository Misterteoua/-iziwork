<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\VersionController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Route;

// Aucune route par closure dans ce fichier : `php artisan route:cache` doit
// pouvoir sérialiser l'ensemble du projet (voir VersionController).
Route::view('/', 'welcome');

Route::get('/version', VersionController::class);

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:admin-login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/s/{token}', [SubmissionController::class, 'showForm'])->name('submit.form');
Route::post('/s/{token}', [SubmissionController::class, 'submit'])
    ->middleware('throttle:submissions')
    ->name('submit.process');
Route::get('/s/{token}/recap/{receiptToken}', [SubmissionController::class, 'recap'])
    ->where('receiptToken', '[A-Za-z0-9]{64}')
    ->name('submission.recap');
Route::get('/s/{token}/recap/{receiptToken}/pdf', [SubmissionController::class, 'downloadRecap'])
    ->where('receiptToken', '[A-Za-z0-9]{64}')
    ->name('submission.recap.pdf');

Route::prefix('admin')->middleware(AdminAuth::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('forms', [FormController::class, 'index'])->name('admin.forms.index');
    Route::get('forms/create', [FormController::class, 'create'])->name('admin.forms.create');
    Route::post('forms', [FormController::class, 'store'])->name('admin.forms.store');
    Route::get('forms/{form}', [FormController::class, 'show'])->whereNumber('form')->name('admin.forms.show');
    Route::get('forms/{form}/edit', [FormController::class, 'edit'])->whereNumber('form')->name('admin.forms.edit');
    Route::put('forms/{form}', [FormController::class, 'update'])->whereNumber('form')->name('admin.forms.update');
    Route::delete('forms/{form}', [FormController::class, 'destroy'])->whereNumber('form')->name('admin.forms.destroy');
    Route::patch('forms/{form}/toggle-status', [FormController::class, 'toggleStatus'])->whereNumber('form')->name('admin.forms.toggle');
    Route::post('forms/{form}/copy-link', [FormController::class, 'copyLink'])->whereNumber('form')->name('admin.forms.copyLink');

    Route::get('forms/{form}/submissions', [SubmissionController::class, 'adminIndex'])->whereNumber('form')->name('admin.submissions.index');
    Route::get('forms/{form}/submissions/bulk-download', [SubmissionController::class, 'downloadBulk'])->whereNumber('form')->name('admin.submissions.bulk');
    Route::get('forms/{form}/submissions/{submission}/download', [SubmissionController::class, 'downloadSubmission'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.download.submission');
    Route::get('forms/{form}/submissions/{submission}', [SubmissionController::class, 'adminShow'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.show');
    Route::get('submissions/{file}/download', [SubmissionController::class, 'downloadFile'])
        ->whereNumber('file')->name('admin.submissions.download');

    Route::get('forms/{form}/submissions/{submission}/edit', [SubmissionController::class, 'adminEdit'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.edit');
    Route::put('forms/{form}/submissions/{submission}', [SubmissionController::class, 'adminUpdate'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.update');
    Route::delete('forms/{form}/submissions/{submission}', [SubmissionController::class, 'adminDestroy'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.destroy');
    Route::post('forms/{form}/submissions/{submission}/files', [SubmissionController::class, 'adminAddFile'])
        ->whereNumber('form')->whereNumber('submission')->name('admin.submissions.files.store');
    Route::delete('forms/{form}/submissions/{submission}/files/{file}', [SubmissionController::class, 'adminDestroyFile'])
        ->whereNumber('form')->whereNumber('submission')->whereNumber('file')->name('admin.submissions.files.destroy');
    Route::put('forms/{form}/submissions/{submission}/files/{file}', [SubmissionController::class, 'adminReplaceFile'])
        ->whereNumber('form')->whereNumber('submission')->whereNumber('file')->name('admin.submissions.files.replace');

    Route::get('profile', [ProfileController::class, 'edit'])->name('admin.profile');
    Route::patch('profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:admin-login')
        ->name('admin.profile.password');
});
