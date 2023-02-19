<?php

/**
 * Routes which is neccessary for the SSO server.
 */

Route::middleware('api')->prefix('api/sso')->group(function () {
    Route::post('login', 'IJagjeet\LaravelSSO\Controllers\ServerController@login');
    Route::post('forceLoginByUserEmail', 'IJagjeet\LaravelSSO\Controllers\ServerController@forceLoginByUserEmail');
    Route::post('register', 'IJagjeet\LaravelSSO\Controllers\ServerController@register');
    Route::post('logout', 'IJagjeet\LaravelSSO\Controllers\ServerController@logout');
    Route::get('attach', 'IJagjeet\LaravelSSO\Controllers\ServerController@attach');
    Route::get('userInfo', 'IJagjeet\LaravelSSO\Controllers\ServerController@userInfo');
    Route::post('createUserOnServer', 'IJagjeet\LaravelSSO\Controllers\ServerController@createUser');
});
