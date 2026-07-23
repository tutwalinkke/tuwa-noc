<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SubnetController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\MaintenanceWindowController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\TopologyController;
use App\Http\Controllers\DeviceProvisioningCodeController;

Route::prefix('v1')->group(function () {

    // Deliberately outside identity.auth — a fresh, unconfigured
    // router has no bearer token yet. The provisioning code itself is
    // the credential; rate limiting here is the real safeguard against
    // brute-forcing a 40-character random code (astronomically
    // unlikely to matter, but cheap to add and genuinely worth having).
    Route::post('/provisioning-codes/redeem', [DeviceProvisioningCodeController::class, 'redeem'])
        ->middleware('throttle:10,1');

    Route::middleware('identity.auth')->group(function () {

        Route::get('/ping', function (Request $request) {
            return response()->json([
                'message' => 'pong from Tuwa NOC',
                'identity_user' => $request->attributes->get('identity_user'),
                'identity_roles' => $request->attributes->get('identity_roles'),
            ]);
        });

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/bandwidth-history', [DashboardController::class, 'bandwidthHistory']);

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

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{id}', [CustomerController::class, 'show']);
        Route::patch('/customers/{id}', [CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        Route::post('/customers/{customerId}/devices', [CustomerController::class, 'linkDevice']);

        Route::get('/activity', [ActivityController::class, 'index']);

        Route::get('/maintenance-windows', [MaintenanceWindowController::class, 'index']);
        Route::post('/maintenance-windows', [MaintenanceWindowController::class, 'store']);
        Route::post('/maintenance-windows/{id}/end-early', [MaintenanceWindowController::class, 'endEarly']);
        Route::delete('/maintenance-windows/{id}', [MaintenanceWindowController::class, 'destroy']);

        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::post('/incidents/{id}/acknowledge', [IncidentController::class, 'acknowledge']);
        Route::post('/incidents/{id}/resolve', [IncidentController::class, 'resolve']);

        Route::get('/topology', [TopologyController::class, 'index']);
        Route::post('/topology/links', [TopologyController::class, 'storeLink']);
        Route::delete('/topology/links/{id}', [TopologyController::class, 'destroyLink']);

        Route::post('/provisioning-codes', [DeviceProvisioningCodeController::class, 'store']);

    });

});
