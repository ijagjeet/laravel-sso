<?php

namespace IJagjeet\LaravelSSO\Controllers;

use IJagjeet\LaravelSSO\LaravelSSOBroker;
use IJagjeet\LaravelSSO\Models\Broker;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use IJagjeet\LaravelSSO\LaravelSSOServer;

class BrokerController extends BaseController
{
    public function createUser(Request $request)
    {
        $data = $request->all();
        $userModel = config('laravel-sso.usersModel');
        $user = $userModel::where('email', $data['email'])->first();

        if($user){
            return true;
        }

        return $userModel::query()->create($data);
    }

    public function updateUser(Request $request)
    {
        $data = $request->all();
        $userModel = config('laravel-sso.usersModel');
        $user = $userModel::where('email', $data['email'])->first();

        if($user){
            $user->update($data);
            return $user;
        }

        return false;
    }
}
