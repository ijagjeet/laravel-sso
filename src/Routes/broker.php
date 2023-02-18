<?php

/**
 * Routes which is neccessary for the SSO server.
 */

Route::middleware('api')->prefix('api/sso')->group(function () {
    Route::get('createUserOnBroker', 'IJagjeet\LaravelSSO\Controllers\BrokerController@createUser');
});
