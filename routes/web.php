<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizGradingController;
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

// Évaluation en ligne : parcours entièrement séparé du dépôt de travaux.
Route::get('/q/{quiz:token}', [QuizAttemptController::class, 'start'])->name('quiz.start');
Route::post('/q/{quiz:token}/start', [QuizAttemptController::class, 'begin'])->name('quiz.begin');
Route::get('/q/{quiz:token}/question', [QuizAttemptController::class, 'question'])->name('quiz.question');
Route::post('/q/{quiz:token}/answer', [QuizAttemptController::class, 'answer'])->name('quiz.answer');
Route::get('/q/{quiz:token}/finish', [QuizAttemptController::class, 'submitPage'])->name('quiz.submit.page');
Route::post('/q/{quiz:token}/submit', [QuizAttemptController::class, 'submit'])->name('quiz.submit');
Route::post('/q/{quiz:token}/infraction', [QuizAttemptController::class, 'infraction'])->name('quiz.infraction');

// Salle informatique : laisser le poste au candidat suivant, une fois la copie
// rendue. La route refuse d'abandonner une épreuve en cours.
Route::post('/q/{quiz:token}/nouveau-candidat', [QuizAttemptController::class, 'newCandidate'])
    ->name('quiz.new-candidate');
Route::get('/q/{quiz:token}/resultat', [QuizAttemptController::class, 'result'])->name('quiz.result');
Route::get('/q/{quiz:token}/recap/{reference}/pdf', [QuizAttemptController::class, 'recapPdf'])
    ->name('quiz.recap.pdf');

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
    Route::get('export/submissions', [DashboardController::class, 'export'])->name('admin.export.submissions');

    // Évaluations en ligne, gérées séparément des dépôts de travaux.
    Route::get('quizzes', [QuizController::class, 'index'])->name('admin.quizzes.index');
    Route::get('quizzes/create', [QuizController::class, 'create'])->name('admin.quizzes.create');
    Route::post('quizzes', [QuizController::class, 'store'])->name('admin.quizzes.store');
    Route::get('quizzes/{quiz}', [QuizController::class, 'show'])->whereNumber('quiz')->name('admin.quizzes.show');
    Route::put('quizzes/{quiz}', [QuizController::class, 'updateSettings'])->whereNumber('quiz')->name('admin.quizzes.update');
    Route::delete('quizzes/{quiz}', [QuizController::class, 'destroy'])->whereNumber('quiz')->name('admin.quizzes.destroy');
    Route::patch('quizzes/{quiz}/toggle-status', [QuizController::class, 'toggle'])->whereNumber('quiz')->name('admin.quizzes.toggle');
    Route::post('quizzes/{quiz}/questions', [QuizController::class, 'storeQuestion'])->whereNumber('quiz')->name('admin.quizzes.questions.store');
    Route::put('quizzes/{quiz}/questions/{field}', [QuizController::class, 'updateQuestion'])
        ->whereNumber('quiz')->whereNumber('field')->name('admin.quizzes.questions.update');
    Route::delete('quizzes/{quiz}/questions/{field}', [QuizController::class, 'destroyQuestion'])
        ->whereNumber('quiz')->whereNumber('field')->name('admin.quizzes.questions.destroy');

    // Import par fichier : le modèle téléchargeable évite à l'enseignant de
    // deviner le format, et l'import se fait au moment où il choisit le type.
    Route::get('quizzes/questions/template', [QuizController::class, 'questionTemplate'])->name('admin.quizzes.questions.template');
    Route::post('quizzes/{quiz}/questions/import', [QuizController::class, 'importQuestions'])->whereNumber('quiz')->name('admin.quizzes.questions.import');
    Route::get('quizzes/students/template', [QuizController::class, 'studentTemplate'])->name('admin.quizzes.students.template');
    Route::post('quizzes/{quiz}/students/import', [QuizController::class, 'importStudents'])->whereNumber('quiz')->name('admin.quizzes.students.import');

    Route::post('quizzes/{quiz}/references', [QuizController::class, 'generateReferences'])->whereNumber('quiz')->name('admin.quizzes.references.store');
    Route::get('quizzes/{quiz}/references/export', [QuizController::class, 'exportReferences'])->whereNumber('quiz')->name('admin.quizzes.references.export');
    Route::get('quizzes/{quiz}/results', [QuizController::class, 'results'])->whereNumber('quiz')->name('admin.quizzes.results');
    Route::get('quizzes/{quiz}/results/export', [QuizController::class, 'exportResults'])->whereNumber('quiz')->name('admin.quizzes.results.export');
    Route::get('quizzes/{quiz}/results/open-answers', [QuizController::class, 'exportOpenAnswers'])
        ->whereNumber('quiz')->name('admin.quizzes.results.open-answers');
    Route::post('quizzes/{quiz}/attempts/{attempt}/reset', [QuizController::class, 'resetAttempt'])
        ->whereNumber('quiz')->whereNumber('attempt')->name('admin.quizzes.attempts.reset');

    // Correction manuelle des réponses rédigées : une copie rendue, un guide,
    // une note par réponse. Séparée de QuizController pour rester lisible.
    //
    // `grade` (sans copie) enchaîne les copies à corriger ; `grade` avec une copie
    // affiche celle-ci, et `?serie=1` fait suivre automatiquement la suivante.
    Route::get('quizzes/{quiz}/grade', [QuizGradingController::class, 'series'])
        ->whereNumber('quiz')->name('admin.quizzes.grade');
    Route::get('quizzes/{quiz}/attempts/{attempt}/grade', [QuizGradingController::class, 'show'])
        ->whereNumber('quiz')->whereNumber('attempt')->name('admin.quizzes.attempts.grade');
    Route::post('quizzes/{quiz}/attempts/{attempt}/grade', [QuizGradingController::class, 'store'])
        ->whereNumber('quiz')->whereNumber('attempt')->name('admin.quizzes.attempts.grade.store');

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
