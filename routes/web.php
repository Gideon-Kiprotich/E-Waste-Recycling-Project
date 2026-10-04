<?php

use App\Enums\Role;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\RoleHomeController;
use App\Support\PrivacyNotice;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::view('/privacy-notice', 'auth.privacy-notice', ['noticeVersion' => PrivacyNotice::VERSION])->name('privacy.notice');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordRecoveryController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordRecoveryController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'account.current'])->group(function (): void {
    Route::get('/dashboard', [RoleHomeController::class, 'index'])->name('dashboard');
    foreach (Role::cases() as $role) {
        Route::get('/'.$role->value, [RoleHomeController::class, 'show'])
            ->middleware('role:'.$role->value)->name($role->value.'.home');
    }
});
