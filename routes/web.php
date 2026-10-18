<?php

use App\Enums\Role;
use App\Http\Controllers\Administration\DirectoryController;
use App\Http\Controllers\Administration\ReviewController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\Assignments\AllocationController;
use App\Http\Controllers\Assignments\CollectorAccessController;
use App\Http\Controllers\Assignments\NoticeController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\Collection\RequestController;
use App\Http\Controllers\Devices\DeviceController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\MediaInventoryController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RefurbishmentController;
use App\Http\Controllers\RoleHomeController;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\SanitizationController;
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
    Route::get('/facility/batches', [BatchController::class, 'index'])->middleware('role:processor')->name('batches.index');
    Route::get('/facility/batches/create', [BatchController::class, 'create'])->middleware('role:processor')->name('batches.create');
    Route::get('/facility/batches/lookup', [BatchController::class, 'lookup'])->middleware('role:processor')->name('batches.lookup');
    Route::get('/batch-labels/{subject}', [BatchController::class, 'label'])->whereUuid('subject')->middleware('role:processor')->name('batches.label');
    Route::get('/batch-evidence/{record}', [BatchController::class, 'evidence'])->whereUuid('record')->middleware('role:processor')->name('batches.evidence');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->whereUuid('batch')->middleware('role:consumer,processor')->name('batches.show');
    Route::post('/batches/{batch}/{kind}', [BatchController::class, 'store'])->whereUuid('batch')->where('kind', 'draft|commit|output|measurement|accounted|discrepancy')->middleware(['role:processor', 'throttle:20,1'])->name('batches.store');
    Route::post('/batches/{batch}/media/{medium}/authorize', [BatchController::class, 'authorize'])->whereUuid('batch')->whereUuid('medium')->middleware(['role:processor', 'throttle:20,1'])->name('batches.authorize');
    Route::get('/facility/refurbishment', [RefurbishmentController::class, 'index'])->middleware('role:processor')->name('refurbishment.index');
    Route::get('/items/{subject}/refurbishment', [RefurbishmentController::class, 'show'])->whereUuid('subject')->middleware('role:consumer,processor')->name('refurbishment.show');
    Route::post('/items/{subject}/refurbishment/{kind}', [RefurbishmentController::class, 'store'])->whereUuid('subject')->where('kind', 'work|test|fan_na|authorize|withdraw|exception|acknowledge')->middleware(['role:consumer,processor', 'throttle:20,1'])->name('refurbishment.store');
    Route::get('/work-evidence/{record}', [RefurbishmentController::class, 'evidence'])->whereUuid('record')->middleware('role:processor')->name('refurbishment.evidence');
    Route::get('/media/{subject}/sanitization', [SanitizationController::class, 'show'])->whereUuid('subject')->middleware('role:consumer,processor')->name('sanitization.show');
    Route::middleware('role:processor')->group(function (): void {
        Route::get('/facility/sanitization', [SanitizationController::class, 'index'])->name('sanitization.index');
        Route::post('/media/{subject}/sanitization/{kind}', [SanitizationController::class, 'store'])->whereUuid('subject')->where('kind', 'attempt|verification|validation|revalidation')->middleware('throttle:20,1')->name('sanitization.store');
        Route::get('/media-evidence/{record}', [SanitizationController::class, 'evidence'])->whereUuid('record')->name('sanitization.evidence');
    });
    Route::get('/assessment-notices', [AssessmentController::class, 'notices'])->name('assessments.notices');
    Route::post('/assessment-notices/{notice}/read', [AssessmentController::class, 'read'])->whereUuid('notice')->name('assessments.notices.read');
    Route::get('/inspections/{subject}', [AssessmentController::class, 'show'])->whereUuid('subject')->middleware('role:consumer,processor')->name('assessments.show');
    Route::middleware('role:processor')->group(function (): void {
        Route::get('/facility/inspections', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::post('/inspections/{subject}', [AssessmentController::class, 'store'])->whereUuid('subject')->middleware('throttle:20,1')->name('assessments.store');
        Route::post('/inspections/{subject}/decisions', [AssessmentController::class, 'decide'])->whereUuid('subject')->middleware('throttle:20,1')->name('assessments.decide');
        Route::get('/inspection-evidence/{assessment}', [AssessmentController::class, 'evidence'])->whereUuid('assessment')->name('assessments.evidence');
    });
    Route::get('/handover-notices', [HandoverController::class, 'notices'])->name('handovers.notices');
    Route::post('/handover-notices/{notice}/read', [HandoverController::class, 'read'])->whereUuid('notice')->name('handovers.notices.read');
    Route::get('/handovers/{handover}', [HandoverController::class, 'show'])->whereUuid('handover')->name('handovers.show');
    Route::middleware('role:collector,processor,administrator')->group(function (): void {
        Route::get('/handovers', [HandoverController::class, 'index'])->name('handovers.index');
        Route::get('/handovers/create/{recipient}', [HandoverController::class, 'create'])->whereUuid('recipient')->name('handovers.create');
        Route::post('/handovers', [HandoverController::class, 'store'])->middleware('throttle:20,1')->name('handovers.store');
        Route::post('/handovers/{handover}/{kind}', [HandoverController::class, 'change'])->whereUuid('handover')->where('kind', 'acknowledged|withdrawn|resolved')->middleware('throttle:20,1')->name('handovers.change');
        Route::post('/handovers/{handover}/lookup', [HandoverController::class, 'lookup'])->whereUuid('handover')->middleware('throttle:30,1')->name('handovers.lookup');
        Route::get('/handover-evidence/{operation}', [HandoverController::class, 'evidence'])->whereUuid('operation')->name('handovers.evidence');
    });
    Route::get('/receipt-notices', [ReceiptController::class, 'notices'])->name('receipts.notices');
    Route::post('/receipt-notices/{notice}/read', [ReceiptController::class, 'read'])->whereUuid('notice')->name('receipts.notices.read');
    Route::get('/receipts/{visit}', [ReceiptController::class, 'result'])->whereUuid('visit')->name('receipts.result');
    Route::get('/lookup/{public_code}', [DeviceController::class, 'lookup'])->where('public_code', 'EWC-[A-F0-9]{32}')->name('items.lookup');
    Route::middleware('role:collector,processor')->group(function (): void {
        Route::get('/requests/{collection}/receipt', [ReceiptController::class, 'form'])->whereUuid('collection')->name('receipts.form');
        Route::post('/requests/{collection}/receipt-lookup', [ReceiptController::class, 'lookup'])->whereUuid('collection')->middleware('throttle:30,1')->name('receipts.lookup');
        Route::post('/requests/{collection}/receipts', [ReceiptController::class, 'store'])->whereUuid('collection')->middleware('throttle:20,1')->name('receipts.store');
        Route::get('/receipts/{visit}/evidence', [ReceiptController::class, 'evidence'])->whereUuid('visit')->name('receipts.evidence');
    });
    Route::get('/processor/intake', [ReceiptController::class, 'inbox'])->middleware('role:processor')->name('processor.intake');
    Route::post('/collector/routes/{route}/finish', [ReceiptController::class, 'finish'])->whereUuid('route')->middleware('role:collector')->name('collector.routes.finish');
    Route::prefix('admin')->name('admin.')->middleware('role:administrator')->group(function (): void {
        Route::get('/point-staff', [ReceiptController::class, 'access'])->name('point-staff');
        Route::post('/point-staff/{staff}', [ReceiptController::class, 'saveAccess'])->whereUuid('staff')->middleware('throttle:20,1')->name('point-staff.save');
        Route::get('/route-planning', [RoutingController::class, 'index'])->name('routing.index');
        Route::get('/route-planning/vehicles/create', [RoutingController::class, 'vehicleForm'])->name('routing.vehicles.create');
        Route::post('/route-planning/vehicles', [RoutingController::class, 'saveVehicle'])->middleware('throttle:20,1')->name('routing.vehicles.store');
        Route::get('/route-planning/vehicles/{vehicle}', [RoutingController::class, 'vehicleForm'])->whereUuid('vehicle')->name('routing.vehicles.edit');
        Route::post('/route-planning/vehicles/{vehicle}', [RoutingController::class, 'saveVehicle'])->whereUuid('vehicle')->middleware('throttle:20,1')->name('routing.vehicles.update');
        Route::post('/route-planning', [RoutingController::class, 'create'])->middleware('throttle:10,1')->name('routing.create');
        Route::get('/route-planning/runs/{run}', [RoutingController::class, 'show'])->whereUuid('run')->name('routing.show');
        Route::post('/route-planning/runs/{run}/approve', [RoutingController::class, 'approve'])->whereUuid('run')->middleware('throttle:10,1')->name('routing.approve');
        Route::post('/route-planning/approvals/{approval}/withdraw', [RoutingController::class, 'withdraw'])->whereUuid('approval')->middleware('throttle:10,1')->name('routing.withdraw');
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
        Route::get('/collector/routes', [RoutingController::class, 'work'])->name('collector.routes.index');
        Route::get('/collector/routes/{route}', [RoutingController::class, 'stops'])->whereUuid('route')->name('collector.routes.show');
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
        Route::get('/items/{item}', [DeviceController::class, 'show'])->whereUuid('item')->name('items.show');
        Route::get('/items/{item}/edit', [DeviceController::class, 'edit'])->whereUuid('item')->name('items.edit');
        Route::patch('/items/{item}/observations', [DeviceController::class, 'update'])->whereUuid('item')->middleware('throttle:20,1')->name('items.update');
        Route::get('/subjects/{item}/label', [DeviceController::class, 'label'])->whereUuid('item')->name('items.label');
        Route::get('/subjects/{item}/qr', [DeviceController::class, 'qr'])->whereUuid('item')->name('items.qr');
        Route::get('/items/{item}/photos/{attachment}', [DeviceController::class, 'photo'])->whereUuid(['item', 'attachment'])->name('items.photo');
    });
    Route::get('/media-inventory/media/{medium}/label', [MediaInventoryController::class, 'label'])->middleware('role:processor')->whereUuid('medium')->name('media-inventory.label');
    Route::get('/media-inventory/media/{medium}/qr', [MediaInventoryController::class, 'qr'])->middleware('role:processor')->whereUuid('medium')->name('media-inventory.qr');
    Route::get('/items/{subject}/media-inventory', [MediaInventoryController::class, 'show'])->middleware('role:consumer,processor')->whereUuid('subject')->name('media-inventory.show');
    Route::post('/items/{subject}/media-inventory/{kind}', [MediaInventoryController::class, 'store'])->middleware('role:processor')->whereUuid('subject')->middleware('throttle:20,1')->name('media-inventory.store');
    Route::get('/media-inventory/evidence/{kind}/{record}', [MediaInventoryController::class, 'evidence'])->middleware('role:processor')->whereUuid('record')->name('media-inventory.evidence');
    Route::get('/dashboard', [RoleHomeController::class, 'index'])->name('dashboard');
    foreach (Role::cases() as $role) {
        Route::get('/'.$role->value, [RoleHomeController::class, 'show'])
            ->middleware('role:'.$role->value)->name($role->value.'.home');
    }
});
