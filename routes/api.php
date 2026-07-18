<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SubnetController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;

Route::prefix('v1')->middleware('identity.auth')->group(function () {

    Route::get('/ping', function (Request $request) {
        return response()->json([
            'message' => 'pong from Tuwa NOC',
            'identity_user' => $request->attributes->get('identity_user'),
            'identity_roles' => $request->attributes->get('identity_roles'),
        ]);
    });

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::get('/devices/{id}', [DeviceController::class, 'show']);
    Route::patch('/devices/{id}', [DeviceController::class, 'update']);
    Route::delete('/devices/{id}', [DeviceController::class, 'destroy']);

    Route::get('/subnets', [SubnetController::class, 'index']);
    Route::post('/subnets', [SubnetController::class, 'store']);
    Route::get('/subnets/{id}', [SubnetController::class, 'show']);
    Route::delete('/subnets/{id}', [SubnetController::class, 'destroy']);
    Route::post('/subnets/{id}/allocate', [SubnetController::class, 'allocate']);
    Route::post('/subnets/{subnetId}/release/{ipId}', [SubnetController::class, 'release']);

    Route::get('/billing/status', [InvoiceController::class, 'billingStatus']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoiceId}/payments', [PaymentController::class, 'store']);

});
