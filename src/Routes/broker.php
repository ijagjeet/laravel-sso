<?php

/**
 * Routes which is neccessary for the SSO server.
 */

Route::middleware('api')->prefix('api/sso')->group(function () {
    Route::post('updateUserOnBroker', 'IJagjeet\LaravelSSO\Controllers\BrokerController@updateUser');
    Route::post('createUserOnBroker', 'IJagjeet\LaravelSSO\Controllers\BrokerController@createUser');
});
