<?php

use App\Http\Controllers\Attendance\AttendanceCommandController;
use App\Http\Controllers\Deployments\DeploymentCommandController;
use App\Http\Controllers\Equipment\EquipmentCommandController;
use App\Http\Controllers\FieldReports\FieldReportCommandController;
use App\Http\Controllers\FieldReports\FieldReportPhotoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Incidents\IncidentCommandController;
use App\Http\Controllers\Incidents\IncidentReadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show'])->name('api.health');

Route::middleware('local.field')->group(function (): void {
    Route::post('/commands/check-in-staff', [AttendanceCommandController::class, 'checkIn'])
        ->name('api.commands.check-in-staff');

    Route::post('/commands/check-out-staff', [AttendanceCommandController::class, 'checkOut'])
        ->name('api.commands.check-out-staff');

    Route::post('/commands/mark-no-show', [AttendanceCommandController::class, 'markNoShow'])
        ->name('api.commands.mark-no-show');

    Route::post('/commands/set-current-deployment', [DeploymentCommandController::class, 'setCurrent'])
        ->name('api.commands.set-current-deployment');

    Route::post('/commands/checkout-equipment', [EquipmentCommandController::class, 'checkout'])
        ->name('api.commands.checkout-equipment');

    Route::post('/commands/return-equipment', [EquipmentCommandController::class, 'returnEquipment'])
        ->name('api.commands.return-equipment');

    Route::post('/commands/submit-field-report', [FieldReportCommandController::class, 'submit'])
        ->name('api.commands.submit-field-report');

    Route::post('/commands/upload-field-report-photo', [FieldReportPhotoController::class, 'upload'])
        ->name('api.commands.upload-field-report-photo');

    Route::post('/commands/create-incident', [IncidentCommandController::class, 'create'])
        ->name('api.commands.create-incident');

    Route::post('/commands/append-incident-note', [IncidentCommandController::class, 'appendNote'])
        ->name('api.commands.append-incident-note');

    Route::get('/events/{event}/incidents', [IncidentReadController::class, 'index'])
        ->name('api.events.incidents.index');

    Route::get('/events/{event}/incidents/{incident}', [IncidentReadController::class, 'show'])
        ->name('api.events.incidents.show');

    Route::post('/field-report-photos/{attachment}/preview-url', [FieldReportPhotoController::class, 'issuePreviewUrl'])
        ->name('api.field-report-photos.preview-url');

    Route::post('/field-report-photos/{attachment}/download-url', [FieldReportPhotoController::class, 'issueDownloadUrl'])
        ->name('api.field-report-photos.download-url');
});
