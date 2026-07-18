<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DashboardController;

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

});
