<?php

namespace IJagjeet\LaravelSSO;

use IJagjeet\LaravelSSO\Interfaces\SSOServerInterface;
use IJagjeet\LaravelSSO\Models\Broker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use IJagjeet\LaravelSSO\Resources\UserResource;
use IJagjeet\LaravelSSO\Exceptions\SSOServerException;

class LaravelSSOServer implements SSOServerInterface
{
    /**
     * @var mixed
     */
    protected $brokerId;

    protected $ssoServerUrl;

    protected $serverSecret;

    public function __construct()
    {
        $this->ssoServerUrl = config('laravel-sso.serverUrl', null);
        $this->serverSecret = config('laravel-sso.serverSecret', null);
    }

    /**
     * Attach user's session to broker's session.
     *
     * @param string|null $broker Broker's name/id.
     * @param string|null $token Token sent from broker.
     * @param string|null $checksum Calculated broker+token checksum.
     *
     * @return string or redirect
     */
    public function attach(?string $broker, ?string $token, ?string $checksum)
    {
        try {
            if (!$broker) {
                $this->fail('No broker id specified.', true);
            }

            if (!$token) {
                $this->fail('No token specified.', true);
            }

            if (!$checksum || $checksum != $this->generateAttachChecksum($broker, $token)) {
                $this->fail('Invalid checksum.', true);
            }

            $this->startUserSession();
            $sessionId = $this->generateSessionId($broker, $token);

            $this->saveBrokerSessionData($sessionId, $this->getSessionData('id'));
        } catch (SSOServerException $e) {
            return $this->redirect(null, ['sso_error' => $e->getMessage()]);
        }

        $this->attachSuccess();
    }

    /**
     * @param null|string $username
     * @param null|string $password
     *
     * @return string
     */
    public function login(?string $username, ?string $password)
    {
        try {
            $this->startBrokerSession();

            if (!$username || !$password) {
                $this->fail('No username and/or password provided.');
            }

            if (!$this->authenticate($username, $password)) {
                $this->fail('User authentication failed.');
            }
        } catch (SSOServerException $e) {
            return $this->returnJson(['error' => $e->getMessage()]);
        }

        $this->setSessionData('sso_user', $username);

        return $this->userInfo();
    }

    /**
     * @param null|string $email
     *
     * @return string
     */
    public function forceLoginByUserEmail(?string $email)
    {
        try {
            $this->startBrokerSession();

            if (!$email) {
                $this->fail('No email provided.');
            }

            // \Log::debug("Authenticating:forceLoginByUserEmail:$email");

            $userModel = config('laravel-sso.usersModel');
            $user = $userModel::where('email', $email)->first();

            if (!$user) {
                $this->fail('No user exists.');
            }

            Auth::login($user);

            // After authentication Laravel will change session id, but we need to keep
            // this the same because this session id can be already attached to other brokers.
            $sessionId = $this->getBrokerSessionId();
            $savedSessionId = $this->getBrokerSessionData($sessionId);
            $this->startSession($savedSessionId);
        } catch (SSOServerException $e) {
            return $this->returnJson(['error' => $e->getMessage()]);
        }

        $this->setSessionData('sso_user', $email);

        return $this->userInfo();
    }

    /**
     * Logging user out.
     *
     * @return string
     */
    public function logout()
    {
        try {
            $this->startBrokerSession();
            $this->setSessionData('sso_user', null);
        } catch (SSOServerException $e) {
            return $this->returnJson(['error' => $e->getMessage()]);
        }

        return $this->returnJson(['success' => 'User has been successfully logged out.']);
    }

    /**
     * Returning user info for the broker.
     *
     * @return string
     */
    public function userInfo()
    {
        try {
            $this->startBrokerSession();

            $username = $this->getSessionData('sso_user');

            if (!$username) {
                $this->fail('User not authenticated. Session ID: ' . $this->getSessionData('id'));
            }

            if (!$user = $this->getUserInfo($username)) {
                $this->fail('User not found.');
            }
            \Log::info('User info');
            \Log::info($username);
        } catch (SSOServerException $e) {
            \Log::info("User info failed: " . $e->getMessage());
            return $this->returnJson(['error' => $e->getMessage()]);
        }

        \Log::info('User info user');
        \Log::info($user);

        return $this->returnUserInfo($user);
    }

    /**
     * Resume broker session if saved session id exist.
     *
     * @throws SSOServerException
     *
     * @return void
     */
    protected function startBrokerSession()
    {
        if (isset($this->brokerId)) {
            return;
        }

        $sessionId = $this->getBrokerSessionId();

        if (!$sessionId) {
            $this->fail('Missing session key from broker.');
        }

        $savedSessionId = $this->getBrokerSessionData($sessionId);

        if (!$savedSessionId) {
            $this->fail('There is no saved session data associated with the broker session id.');
        }

        $this->startSession($savedSessionId);

        $this->brokerId = $this->validateBrokerSessionId($sessionId);
    }

    /**
     * Check if broker session is valid.
     *
     * @param string $sessionId Session id from the broker.
     *
     * @throws SSOServerException
     *
     * @return string
     */
    protected function validateBrokerSessionId(string $sessionId)
    {
        $matches = null;

        if (!preg_match('/^SSO-(\w*+)-(\w*+)-([a-z0-9]*+)$/', $this->getBrokerSessionId(), $matches)) {
            $this->fail('Invalid session id');
        }

        if ($this->generateSessionId($matches[1], $matches[2]) != $sessionId) {
            $this->fail('Checksum failed: Client IP address may have changed');
        }

        return $matches[1];
    }

    /**
     * Generate session id from session token.
     *
     * @param string $brokerId
     * @param string $token
     *
     * @throws SSOServerException
     *
     * @return string
     */
    protected function generateSessionId(string $brokerId, string $token)
    {
        $broker = $this->getBrokerInfo($brokerId);

        if (!$broker) {
            $this->fail('Provided broker does not exist.');
        }

        return 'SSO-' . $brokerId . '-' . $token . '-' . hash('sha256', 'session' . $token . $broker['secret']);
    }

    /**
     * Generate session id from session token.
     *
     * @param string $brokerId
     * @param string $token
     *
     * @throws SSOServerException
     *
     * @return string
     */
    protected function generateAttachChecksum($brokerId, $token)
    {
        $broker = $this->getBrokerInfo($brokerId);

        if (!$broker) {
            $this->fail('Provided broker does not exist.');
        }

        return hash('sha256', 'attach' . $token . $broker['secret']);
    }

    /**
     * Do things if attaching was successful.
     *
     * @return void
     */
    protected function attachSuccess()
    {
        $this->redirect();
    }

    /**
     * If something failed, throw an Exception or redirect.
     *
     * @param null|string $message
     * @param bool $isRedirect
     * @param null|string $url
     *
     * @throws SSOServerException
     *
     * @return void
     */
    protected function fail(?string $message, bool $isRedirect = false, ?string $url = null)
    {
        if (!$isRedirect) {
            throw new SSOServerException($message);
        }

        $this->redirect($url, ['sso_error' => $message]);
    }


    /**
     * Redirect to provided URL with query string.
     *
     * If $url is null, redirect to url which given in 'return_url'.
     *
     * @param string|null $url URL to be redirected.
     * @param array $parameters HTTP query string.
     * @param int $httpResponseCode HTTP response code for redirection.
     *
     * @return void
     */
    protected function redirect(?string $url = null, array $parameters = [], int $httpResponseCode = 307)
    {
        if (!$url) {
            $url = urldecode(request()->get('return_url', null));
        }

        $query = '';
        // Making URL query string if parameters given.
        if (!empty($parameters)) {
            $query = '?';

            if (parse_url($url, PHP_URL_QUERY)) {
                $query = '&';
            }

            $query .= http_build_query($parameters);
        }

        app()->abort($httpResponseCode, '', ['Location' => $url . $query]);
    }

    /**
     * Returning json response for the broker.
     *
     * @param null|array $response Response array which will be encoded to json.
     * @param int $httpResponseCode HTTP response code.
     *
     * @return string
     */
    protected function returnJson(?array $response = null, int $httpResponseCode = 200)
    {
        return response()->json($response, $httpResponseCode);
    }

    /**
     * Authenticate using user credentials
     *
     * @param string $username
     * @param string $password
     *
     * @return bool
     */
    protected function authenticate(string $username, string $password)
    {
        \Log::debug('Authenticating:');
        \Log::debug([config('laravel-sso.usernameField') => $username, 'password' => $password]);
        if (!Auth::attempt([config('laravel-sso.usernameField') => $username, 'password' => $password])) {
            return false;
        }

        // After authentication Laravel will change session id, but we need to keep
        // this the same because this session id can be already attached to other brokers.
        $sessionId = $this->getBrokerSessionId();
        $savedSessionId = $this->getBrokerSessionData($sessionId);
        $this->startSession($savedSessionId);

        return true;
    }

    /**
     * @param null|array $data
     *
     * @return string
     */
    public function register(array $data)
    {
        // \Log::debug("Registering user data from broker to server");

        $brokers = Broker::all();

        $brokers_registration = [];

        // create user on server
        $brokers_registration[] = $this->makeRequest('POST', 'createUserOnServer', $data);

        // create user on brokers
        foreach ($brokers as $broker) {
            $brokers_registration[] = $this->makeRequest('POST', 'createUserOnBroker', $data, $broker->api_url);
        }

        return $brokers_registration;
    }

    /**
     * Make request to SSO server.
     *
     * @param string $method Request method 'post' or 'get'.
     * @param string $command Request command name.
     * @param array $parameters Parameters for URL query string if GET request and form parameters if it's POST request.
     *
     * @return array
     */
    public function makeRequest(string $method, string $command, array $parameters = [], $url = null)
    {
        $url = $this->generateCommandUrl($command, [], $url);

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '. $this->serverSecret,
        ];

        if(strtoupper($method) == 'POST'){
            $response = Http::withHeaders($headers)->post($url, $parameters);
        }else{
            $response = Http::withHeaders($headers)->get($url, $parameters);
        }

        $result = json_decode($response->body());

        // \Log::info("makeRequestFromServer:");
        // \Log::info(compact('method', 'result', 'headers', 'url' ));

        return $result;
    }

    /**
     * Generate request url.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @param  null  $url
     * @return string
     */
    protected function generateCommandUrl(string $command, array $parameters = [], $url = null)
    {
        $query = '';
        if (!empty($parameters)) {
            $query = '?' . http_build_query($parameters);
        }

        return ($url ?? $this->ssoServerUrl) . '/api/sso/' . $command . $query;
    }

    /**
     * Get the secret key and other info of a broker
     *
     * @param  string|null  $brokerId
     *
     * @return null|array
     */
    protected function getBrokerInfo(?string $brokerId)
    {
        try {
            $broker = config('laravel-sso.brokersModel')::where('name', $brokerId)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return null;
        }

        return $broker;
    }

    /**
     * Check for User Auth with Broker Application.
     *
     * @return boolean
     */
    protected function checkBrokerUserAuthentication()
    {
        $userInfo = $this->userInfo();
        $broker = $this->getBrokerDetail();
        if(!empty($userInfo->id) && !empty($broker)) {
            $brokerUser = config('laravel-sso.brokersUserModel')::where('user_id', $userInfo->id)->where('broker_id', $broker->id)->first();
            if(empty($brokerUser)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check for the User authorization with application and return error or userinfo
     *
     * @return string
     */
    public function checkUserApplicationAuth()
    {
        try {
            if(empty($this->checkBrokerUserAuthentication())) {
                $this->fail('User authorization failed with application.');
            }
        } catch (SSOServerException $e) {
            return $this->returnJson(['error' => $e->getMessage()]);
        }
        return $this->userInfo();
    }

    /**
     * Returning the broker details
     *
     * @return array
     */
    public function getBrokerDetail()
    {
        return $this->getBrokerInfo($this->brokerId);
    }

    /**
     * Get the information about a user
     *
     * @param string $username
     *
     * @return array|object|null
     */
    protected function getUserInfo(string $username)
    {
        try {
            $user = config('laravel-sso.usersModel')::where(config('laravel-sso.usernameField'), $username)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return null;
        }

        return $user;
    }

    /**
     * Returning user info for broker. Should return json or something like that.
     *
     * @param array|object $user Can be user object or array.
     *
     * @return array|object|UserResource
     */
    protected function returnUserInfo($user)
    {
        return json_encode(["data" => $user]);

        return new UserResource($user);
    }

    /**
     * Return session id sent from broker.
     *
     * @return null|string
     */
    protected function getBrokerSessionId()
    {
        $authorization = request()->header('Authorization', null);
        if ($authorization &&  strpos($authorization, 'Bearer') === 0) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * Start new session when user visits server.
     *
     * @return void
     */
    protected function startUserSession()
    {
        // Session must be started by middleware.
    }

    /**
     * Set session data
     *
     * @param string $key
     * @param null|string $value
     *
     * @return void
     */
    protected function setSessionData(string $key, ?string $value = null)
    {
        if (!$value) {
            Session::forget($key);
            return;
        }

        Session::put($key, $value);
    }

    /**
     * Get data saved in session.
     *
     * @param string $key
     *
     * @return string
     */
    protected function getSessionData(string $key)
    {
        if ($key === 'id') {
            return Session::getId();
        }

        return Session::get($key, null);
    }

    /**
     * Start new session with specific session id.
     *
     * @param $sessionId
     *
     * @return void
     */
    protected function startSession(string $sessionId)
    {
        Session::setId($sessionId);
        Session::start();
    }

    /**
     * Save broker session data to cache.
     *
     * @param string $brokerSessionId
     * @param string $sessionData
     *
     * @return void
     */
    protected function saveBrokerSessionData(string $brokerSessionId, string $sessionData)
    {
        Cache::put('broker_session:' . $brokerSessionId, $sessionData, now()->addHour());
    }

    /**
     * Get broker session data from cache.
     *
     * @param string $brokerSessionId
     *
     * @return null|string
     */
    protected function getBrokerSessionData(string $brokerSessionId)
    {
        return Cache::get('broker_session:' . $brokerSessionId);
    }
}
