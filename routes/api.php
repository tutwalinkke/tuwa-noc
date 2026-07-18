<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::prefix('v1')->group(function () {

    Route::middleware('identity.auth')->get('/ping', function (Request $request) {
        return response()->json([
            'message' => 'pong from Tuwa NOC',
            'identity_user' => $request->attributes->get('identity_user'),
            'identity_roles' => $request->attributes->get('identity_roles'),
        ]);
    });

});
