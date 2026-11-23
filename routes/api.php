<?php

use App\Http\Controllers\AnexoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\PetPhotoController;
use App\Http\Controllers\PushDeviceController;
use App\Http\Controllers\RegistroController;
use Illuminate\Support\Facades\Route;

Route::post('v1/notificacoes/dispositivos/{installation}/revogar', [PushDeviceController::class, 'revoke'])
    ->whereUuid('installation')->middleware('throttle:30,1');

Route::prefix('v1/tutor')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:tutor-auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('google', [AuthController::class, 'google']);
        Route::post('forgot-password', [AuthController::class, 'forgot']);
        Route::post('reset-password', [AuthController::class, 'reset']);
        Route::get('verificar-email/{id}/{hash}', [AuthController::class, 'verify'])->whereUuid('id')->name('verification.verify');
    });
    Route::middleware(['auth:sanctum', 'tutor:unverified'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/reenviar-verificacao', [AuthController::class, 'resend'])->middleware('throttle:2,1');
    });
    Route::middleware(['auth:sanctum', 'tutor'])->group(function (): void {
        Route::put('notificacoes/dispositivos/{installation}', [PushDeviceController::class, 'register'])
            ->whereUuid('installation')->middleware('throttle:30,1');
        Route::post('auth/vincular-google', [AuthController::class, 'linkGoogle'])->middleware('throttle:tutor-auth');
        Route::get('perfil', [PetController::class, 'profile']);
        Route::patch('perfil', [PetController::class, 'updateProfile']);
        Route::get('especies', [CatalogController::class, 'species']);
        Route::get('racas', [CatalogController::class, 'breeds']);
        Route::get('tipos-procedimento', [CatalogController::class, 'procedures']);
        Route::get('pets', [PetController::class, 'index']);
        Route::post('pets', [PetController::class, 'store']);
        Route::get('pets/{pet}', [PetController::class, 'show'])->whereUuid('pet');
        Route::patch('pets/{pet}', [PetController::class, 'update'])->whereUuid('pet');
        Route::post('pets/{pet}/foto', [PetPhotoController::class, 'store'])->whereUuid('pet');
        Route::get('pets/{pet}/foto', [PetPhotoController::class, 'show'])->whereUuid('pet');
        Route::delete('pets/{pet}/foto', [PetPhotoController::class, 'destroy'])->whereUuid('pet');
        Route::get('pets/{pet}/prontuario', [RegistroController::class, 'index'])->whereUuid('pet');
        Route::post('pets/{pet}/prontuario', [RegistroController::class, 'store'])->whereUuid('pet');
        Route::post('pets/{pet}/transferencia', [PetController::class, 'transferInvite'])->whereUuid('pet')->middleware('throttle:4,1');
        Route::post('pets/{pet}/transferencia/confirmar', [PetController::class, 'transferConfirm'])->whereUuid('pet')->name('pets.transferir');
        Route::get('prontuario/{registro}', [RegistroController::class, 'show'])->whereUuid('registro');
        Route::patch('prontuario/{registro}', [RegistroController::class, 'update'])->whereUuid('registro');
        Route::post('prontuario/{registro}/anexos', [AnexoController::class, 'store'])->whereUuid('registro');
        Route::get('anexos/{anexo}/download', [AnexoController::class, 'download'])->whereUuid('anexo')->name('tutor.anexo.download');
        Route::delete('anexos/{anexo}', [AnexoController::class, 'destroy'])->whereUuid('anexo');
    });
});

Route::prefix('v1/integracoes')->group(function (): void {
    Route::post('auth/token', [IntegrationController::class, 'token'])->middleware('throttle:integration-token');
    Route::middleware(['throttle:integration-ip', 'empresa'])->group(function (): void {
        Route::get('pets/{pet}', [RegistroController::class, 'pet']);
        Route::get('pets/{pet}/prontuario', [RegistroController::class, 'index']);
        Route::post('pets/{pet}/prontuario', [RegistroController::class, 'store']);
        Route::get('prontuario/{registro}', [RegistroController::class, 'show'])->whereUuid('registro');
        Route::patch('prontuario/{registro}', [RegistroController::class, 'update'])->whereUuid('registro');
        Route::post('prontuario/{registro}/anexos', [AnexoController::class, 'store'])->whereUuid('registro');
        Route::get('anexos/{anexo}/download', [AnexoController::class, 'download'])->whereUuid('anexo')->name('empresa.anexo.download');
        Route::get('tipos-procedimento', [CatalogController::class, 'procedures']);
    });
});
