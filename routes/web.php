<?php

use App\Enums\Role;
use App\Http\Controllers\Administration\DirectoryController;
use App\Http\Controllers\Administration\ReviewController;
use App\Http\Controllers\Assignments\AllocationController;
use App\Http\Controllers\Assignments\CollectorAccessController;
use App\Http\Controllers\Assignments\NoticeController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Collection\RequestController;
use App\Http\Controllers\Devices\DeviceController;
use App\Http\Controllers\RoleHomeController;
use App\Support\PrivacyNotice;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/collection-points', [RequestController::class, 'points'])->name('collection.points');
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
    Route::prefix('admin')->name('admin.')->middleware('role:administrator')->group(function (): void {
        Route::get('/collectors', [CollectorAccessController::class, 'index'])->name('collectors.index');
        Route::get('/collectors/create', [CollectorAccessController::class, 'form'])->name('collectors.create');
        Route::post('/collectors', [CollectorAccessController::class, 'save'])->middleware('throttle:20,1')->name('collectors.store');
        Route::get('/collectors/{collector}', [CollectorAccessController::class, 'form'])->whereUuid('collector')->name('collectors.edit');
        Route::post('/collectors/{collector}', [CollectorAccessController::class, 'save'])->whereUuid('collector')->middleware('throttle:20,1')->name('collectors.update');
        Route::get('/assignments', [AllocationController::class, 'index'])->name('assignments.index');
        Route::get('/assignments/{collection}', [AllocationController::class, 'show'])->whereUuid('collection')->name('assignments.show');
        Route::post('/assignments/{collection}', [AllocationController::class, 'save'])->whereUuid('collection')->middleware('throttle:20,1')->name('assignments.save');
        Route::get('/directory', [DirectoryController::class, 'index'])->name('directory.index');
        Route::get('/directory/{kind}/create', [DirectoryController::class, 'form'])->where('kind', 'organization|place')->name('directory.create');
        Route::post('/directory/{kind}', [DirectoryController::class, 'save'])->where('kind', 'organization|place')->middleware('throttle:20,1')->name('directory.store');
        Route::get('/directory/{kind}/{record}', [DirectoryController::class, 'form'])->where('kind', 'organization|place')->whereUuid('record')->name('directory.edit');
        Route::post('/directory/{kind}/{record}', [DirectoryController::class, 'save'])->where('kind', 'organization|place')->whereUuid('record')->middleware('throttle:20,1')->name('directory.update');
        Route::get('/evidence/{attachment}', [DirectoryController::class, 'evidence'])->whereUuid('attachment')->name('evidence');
        Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{collection}', [ReviewController::class, 'show'])->whereUuid('collection')->name('reviews.show');
        Route::post('/reviews/{collection}', [ReviewController::class, 'decide'])->whereUuid('collection')->middleware('throttle:20,1')->name('reviews.decide');
    });
    Route::middleware('role:collector')->group(function (): void {
        Route::get('/collector/assignments', [AllocationController::class, 'work'])->name('collector.assignments.index');
        Route::get('/collector/assignments/{assignment}', [AllocationController::class, 'job'])->whereUuid('assignment')->name('collector.assignments.show');
        Route::post('/collector/assignments/{assignment}', [AllocationController::class, 'respond'])->whereUuid('assignment')->middleware('throttle:20,1')->name('collector.assignments.respond');
    });
    Route::get('/assignment-notices', [NoticeController::class, 'index'])->name('assignment.notices');
    Route::post('/assignment-notices/{notice}/read', [NoticeController::class, 'read'])->whereUuid('notice')->name('assignment.notices.read');
    Route::middleware('role:consumer')->group(function (): void {
        Route::get('/requests', [RequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
        Route::post('/requests/restart', [RequestController::class, 'restart'])->name('requests.restart');
        Route::get('/requests/create/{step}', [RequestController::class, 'create'])->where('step', '[1-4]')->name('requests.step');
        Route::post('/requests/create/{step}', [RequestController::class, 'advance'])->where('step', '[1-4]')->middleware('throttle:20,1')->name('requests.advance');
        Route::get('/requests/{collection}', [RequestController::class, 'show'])->whereUuid('collection')->name('requests.show');
        Route::post('/requests/{collection}/change', [RequestController::class, 'change'])->whereUuid('collection')->middleware('throttle:20,1')->name('requests.change');
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
