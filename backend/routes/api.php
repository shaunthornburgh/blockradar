<?php

use App\Enums\PipelineStage;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CandidateController;
use App\Http\Controllers\Api\CandidateNoteController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TitleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('login');

Route::get('/meta', fn () => response()->json([
    'data' => [
        'stages' => collect(PipelineStage::cases())->map(fn (PipelineStage $stage) => [
            'value' => $stage->value,
            'label' => $stage->label(),
            'order' => $stage->order(),
        ])->all(),
    ],
]))->name('meta');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me'])->name('user');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/candidates', [CandidateController::class, 'index'])->name('candidates.index');
    Route::get('/candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show');
    Route::patch('/candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update');

    Route::get('/candidates/{candidate}/notes', [CandidateNoteController::class, 'index'])->name('candidates.notes.index');
    Route::post('/candidates/{candidate}/notes', [CandidateNoteController::class, 'store'])->name('candidates.notes.store');

    Route::get('/titles', [TitleController::class, 'index'])->name('titles.index');
    Route::get('/titles/{title}', [TitleController::class, 'show'])->name('titles.show');

    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
});
