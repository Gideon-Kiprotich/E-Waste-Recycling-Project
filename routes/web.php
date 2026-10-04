<?php

use App\Enums\Role;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Devices\DeviceController;
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
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'store'])->middleware('throttle:password-recovery')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordRecoveryController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'account.current'])->group(function (): void {
    Route::middleware('role:consumer')->group(function (): void {
        Route::get('/items', [DeviceController::class, 'index'])->name('items.index');
        Route::get('/items/create', [DeviceController::class, 'create'])->name('items.create');
        Route::post('/items', [DeviceController::class, 'store'])->middleware('throttle:20,1')->name('items.store');
        Route::get('/items/lookup', [DeviceController::class, 'manualLookup'])->name('items.lookup.manual');
        Route::get('/lookup/{public_code}', [DeviceController::class, 'lookup'])->where('public_code', 'EWC-[A-F0-9]{32}')->name('items.lookup');
        Route::get('/items/{item}', [DeviceController::class, 'show'])->whereUuid('item')->name('items.show');
        Route::get('/items/{item}/edit', [DeviceController::class, 'edit'])->whereUuid('item')->name('items.edit');
        Route::patch('/items/{item}/observations', [DeviceController::class, 'update'])->whereUuid('item')->middleware('throttle:20,1')->name('items.update');
        Route::get('/subjects/{item}/label', [DeviceController::class, 'label'])->whereUuid('item')->name('items.label');
        Route::get('/subjects/{item}/qr', [DeviceController::class, 'qr'])->whereUuid('item')->name('items.qr');
        Route::get('/items/{item}/photos/{attachment}', [DeviceController::class, 'photo'])->whereUuid(['item', 'attachment'])->name('items.photo');
    });
    Route::get('/dashboard', [RoleHomeController::class, 'index'])->name('dashboard');
    foreach (Role::cases() as $role) {
        Route::get('/'.$role->value, [RoleHomeController::class, 'show'])
            ->middleware('role:'.$role->value)->name($role->value.'.home');
    }
});
