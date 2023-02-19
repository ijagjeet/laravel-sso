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
        $user_exists = $userModel::where('email', $data['email'])->first();
        if($user_exists){
            return true;
        }

        return $userModel::query()->create($data);
    }
}
