<?php

namespace IJagjeet\LaravelSSO\Controllers;

use IJagjeet\LaravelSSO\LaravelSSOBroker;
use IJagjeet\LaravelSSO\Models\Broker;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use IJagjeet\LaravelSSO\LaravelSSOServer;

class ServerController extends BaseController
{
    /**
     * @param Request $request
     * @param LaravelSSOServer $server
     *
     * @return void
     */
    public function attach(Request $request, LaravelSSOServer $server)
    {
        $server->attach(
            $request->get('broker', null),
            $request->get('token', null),
            $request->get('checksum', null)
        );
    }

    /**
     * @param Request $request
     * @param LaravelSSOServer $server
     *
     * @return mixed
     */
    public function login(Request $request, LaravelSSOServer $server)
    {
        return $server->login(
            $request->get('username', null),
            $request->get('password', null)
        );
    }

    /**
     * @param Request $request
     * @param LaravelSSOServer $server
     * UNUSED
     *
     * @return mixed
     */
    public function register(Request $request, LaravelSSOServer $server)
    {
        // Register on brokers their own regster method are called

        // create user on server
        $server_registeration = $server->register($request->all());

        return true;
    }

    public function createUser($data)
    {
        $userModel = config('laravel-sso.usersModel');
        $user_exists = $user::where('email', $data['email'])->first();
        if($user_exists){
            return true;
        }

        $status = $userModel::query()->create($data);

        return !!$status;
    }

    /**
     * @param LaravelSSOServer $server
     *
     * @return string
     */
    public function logout(LaravelSSOServer $server)
    {
        return $server->logout();
    }

    /**
     * @param LaravelSSOServer $server
     *
     * @return string
     */
    public function userInfo(LaravelSSOServer $server)
    {
        return $server->checkUserApplicationAuth();
    }
}
