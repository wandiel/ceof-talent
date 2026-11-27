<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Candidates\CandidateController;
use App\Http\Controllers\Api\V1\Candidates\CandidateDocumentController;
use App\Http\Controllers\Api\V1\Candidates\CandidateNoteController;
use App\Http\Controllers\Api\V1\Candidates\SkillController;
use App\Http\Controllers\Api\V1\Candidates\TagController;
use App\Http\Controllers\Api\V1\Candidates\TalentPoolController;
use App\Http\Controllers\Api\V1\Identity\RoleController;
use App\Http\Controllers\Api\V1\Identity\UserController;
use App\Http\Controllers\Api\V1\Intelligence\SuggestionController;
use App\Http\Controllers\Api\V1\Interviews\EvaluationCriterionController;
use App\Http\Controllers\Api\V1\Interviews\InterviewController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\UnitController;
use App\Http\Controllers\Api\V1\Recruitment\ApplicationController;
use App\Http\Controllers\Api\V1\Recruitment\JobOpeningController;
use App\Http\Controllers\Api\V1\Reports\DashboardController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST do CEOF TALENT
|--------------------------------------------------------------------------
| Prefixo /api. Rotas de negócio versionadas em /api/v1.
| Autenticação: sessão via Laravel Sanctum (SPA na mesma origem), com
| proteção CSRF (obter o cookie em GET /sanctum/csrf-cookie antes do login).
*/

Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('api.health');

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Rotas públicas de autenticação
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->name('password.reset');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Sessão do próprio usuário (liberadas mesmo com troca de senha pendente)
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::put('password', [PasswordController::class, 'update'])->middleware('throttle:auth')->name('password.update');
        });

        Route::middleware('password.changed')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');

            Route::apiResource('users', UserController::class)->except('destroy');
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
            Route::put('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');

            Route::apiResource('units', UnitController::class);
            Route::apiResource('departments', DepartmentController::class);
            Route::apiResource('positions', PositionController::class);

            // Candidatos (Fase 4)
            Route::get('candidates/options', [CandidateController::class, 'options'])->name('candidates.options');
            Route::post('candidates/duplicates/check', [TalentPoolController::class, 'check'])->name('candidates.duplicates.check');
            Route::get('candidates/duplicates', [TalentPoolController::class, 'duplicates'])->name('candidates.duplicates.index');
            Route::apiResource('candidates', CandidateController::class);
            Route::patch('candidates/{candidate}/status', [CandidateController::class, 'updateStatus'])->name('candidates.status');
            Route::get('candidates/{candidate}/history', [CandidateController::class, 'history'])->name('candidates.history');
            Route::post('candidates/{candidate}/notes', [CandidateNoteController::class, 'store'])->name('candidates.notes.store');
            Route::post('candidates/{candidate}/documents', [CandidateDocumentController::class, 'store'])->middleware('throttle:uploads')->name('candidates.documents.store');
            Route::get('candidates/{candidate}/documents/{document}', [CandidateDocumentController::class, 'download'])->name('candidates.documents.download');
            Route::delete('candidates/{candidate}/documents/{document}', [CandidateDocumentController::class, 'destroy'])->name('candidates.documents.destroy');
            Route::get('skills', [SkillController::class, 'index'])->name('skills.index');

            // Banco de currículos (Fase 5)
            Route::put('candidates/{candidate}/favorite', [TalentPoolController::class, 'favorite'])->name('candidates.favorite');
            Route::delete('candidates/{candidate}/favorite', [TalentPoolController::class, 'unfavorite'])->name('candidates.unfavorite');
            Route::put('candidates/{candidate}/tags', [TalentPoolController::class, 'syncTags'])->name('candidates.tags');
            Route::get('candidates/{candidate}/duplicates', [TalentPoolController::class, 'candidateDuplicates'])->name('candidates.duplicates.show');
            Route::post('candidates/{candidate}/duplicates/{other}/dismiss', [TalentPoolController::class, 'dismiss'])->name('candidates.duplicates.dismiss');
            Route::apiResource('tags', TagController::class)->except('show');

            // Vagas e processos seletivos (Fase 6)
            Route::get('jobs/options', [JobOpeningController::class, 'options'])->name('jobs.options');
            Route::apiResource('jobs', JobOpeningController::class)->except('destroy');
            Route::post('jobs/{job}/transition', [JobOpeningController::class, 'transition'])->name('jobs.transition');
            Route::get('jobs/{job}/history', [JobOpeningController::class, 'history'])->name('jobs.history');
            Route::get('jobs/{job}/applications', [ApplicationController::class, 'index'])->name('jobs.applications.index');
            Route::post('jobs/{job}/applications', [ApplicationController::class, 'store'])->name('jobs.applications.store');
            Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
            Route::post('applications/{application}/move', [ApplicationController::class, 'move'])->name('applications.move');
            Route::get('candidates/{candidate}/applications', [ApplicationController::class, 'forCandidate'])->name('candidates.applications');

            // Entrevistas e avaliações (Fase 7)
            Route::get('interviews/options', [InterviewController::class, 'options'])->name('interviews.options');
            Route::get('interviews', [InterviewController::class, 'index'])->name('interviews.index');
            Route::get('interviews/{interview}', [InterviewController::class, 'show'])->name('interviews.show');
            Route::put('interviews/{interview}', [InterviewController::class, 'update'])->name('interviews.update');
            Route::post('interviews/{interview}/cancel', [InterviewController::class, 'cancel'])->name('interviews.cancel');
            Route::post('interviews/{interview}/no-show', [InterviewController::class, 'noShow'])->name('interviews.no_show');
            Route::put('interviews/{interview}/evaluation', [InterviewController::class, 'evaluate'])->name('interviews.evaluate');
            Route::get('interviews/{interview}/history', [InterviewController::class, 'history'])->name('interviews.history');
            Route::get('applications/{application}/interviews', [InterviewController::class, 'forApplication'])->name('applications.interviews');
            Route::post('applications/{application}/interviews', [InterviewController::class, 'store'])->name('applications.interviews.store');

            // Base de IA (Fase 7.9): sugestões sempre revisadas por uma pessoa
            Route::get('candidates/{candidate}/suggestions', [SuggestionController::class, 'index'])->name('candidates.suggestions.index');
            Route::post('candidates/{candidate}/suggestions/{suggestion}/accept', [SuggestionController::class, 'accept'])->name('candidates.suggestions.accept');
            Route::post('candidates/{candidate}/suggestions/{suggestion}/dismiss', [SuggestionController::class, 'dismiss'])->name('candidates.suggestions.dismiss');
            Route::post('candidates/{candidate}/documents/{document}/read', [SuggestionController::class, 'reread'])->name('candidates.documents.read');
            Route::get('applications/{application}/match', [SuggestionController::class, 'match'])->name('applications.match');
            Route::apiResource('evaluation-criteria', EvaluationCriterionController::class)->except('show')->parameters(['evaluation-criteria' => 'criterion']);

            // Dashboard e relatórios (Fase 8)
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show')->where('report', '[a-z_]+');
            Route::get('reports/{report}/export/{format}', [ReportController::class, 'export'])->name('reports.export')
                ->where(['report' => '[a-z_]+', 'format' => 'csv|xlsx|pdf'])->middleware('throttle:20,1');
        });
    });
});
