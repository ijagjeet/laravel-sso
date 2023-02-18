<?php

namespace IJagjeet\LaravelSSO;

use GuzzleHttp;
use IJagjeet\LaravelSSO\Interfaces\SSOBrokerInterface;
use IJagjeet\LaravelSSO\Models\Broker;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use IJagjeet\LaravelSSO\Exceptions\MissingConfigurationException;

/**
 * Class SSOBroker. This class is only a skeleton.
 * First of all, you need to implement abstract functions in your own class.
 * Secondly, you should create a page which will be your SSO server.
 *
 * @package IJagjeet\LaravelSSO
 */
class LaravelSSOBroker implements SSOBrokerInterface
{
    /**
     * SSO server url.
     *
     * @var string
     */
    protected $ssoServerUrl;

    /**
     * Broker name.
     *
     * @var string
     */
    protected $brokerName;

    /**
     * Broker secret token.
     *
     * @var string
     */
    protected $brokerSecret;

    /**
     * User info retrieved from the SSO server.
     *
     * @var array
     */
    protected $userInfo;

    /**
     * Random token generated for the client and broker.
     *
     * @var string|null
     */
    protected $token;


    public function __construct()
    {
        $this->setOptions();
        $this->saveToken();
    }

    /**
     * Attach client session to broker session in SSO server.
     *
     * @return void
     */
    public function attach()
    {
        $parameters = [
            'return_url' => $this->getCurrentUrl(),
            'broker' => $this->brokerName,
            'token' => $this->token,
            'checksum' => hash('sha256', 'attach' . $this->token . $this->brokerSecret)
        ];

        $attachUrl = $this->generateCommandUrl('attach', $parameters);

        $this->redirect($attachUrl);
    }

    /**
     * Getting user info from SSO based on client session.
     *
     * @return array
     */
    public function getUserInfo()
    {
        if (!isset($this->userInfo) || empty($this->userInfo)) {
            $this->userInfo = $this->makeRequest('GET', 'userInfo');
        }

        return $this->userInfo;
    }

    /**
     * Login client to SSO server with user credentials.
     *
     * @param string $username
     * @param string $password
     *
     * @return bool
     */
    public function login(string $username, string $password)
    {
        $this->userInfo = $this->makeRequest('POST', 'login', compact('username', 'password'));

        if (!isset($this->userInfo['error']) && isset($this->userInfo['data']['id'])) {
            return true;
        }

        return false;
    }

    /**
     * Logout client from SSO server.
     *
     * @return void
     */
    public function logout()
    {
        $this->makeRequest('POST', 'logout');
    }

    /**
     * Generate session key with broker name, broker secret and unique client token.
     *
     * @return string
     */
    protected function getSessionId()
    {
        $checksum = hash('sha256', 'session' . $this->token . $this->brokerSecret);
        return "SSO-{$this->brokerName}-{$this->token}-$checksum";
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
     * Set base class options (sso server url, broker name and secret, etc).
     *
     * @return void
     *
     * @throws MissingConfigurationException
     */
    protected function setOptions()
    {
        $this->ssoServerUrl = config('laravel-sso.serverUrl', null);
        $this->brokerName = config('laravel-sso.brokerName', null);
        $this->brokerSecret = config('laravel-sso.brokerSecret', null);

        if (!$this->ssoServerUrl || !$this->brokerName || !$this->brokerSecret) {
            throw new MissingConfigurationException('Missing configuration values.');
        }
    }

    /**
     * Save unique client token to cookie.
     *
     * @return void
     */
    protected function saveToken()
    {
        if (isset($this->token) && $this->token) {
            return;
        }

        if ($this->token = Cookie::get($this->getCookieName(), null)) {
            return;
        }

        // If cookie token doesn't exist, we need to create it with unique token...
        $this->token = Str::random(40);
        Cookie::queue(Cookie::make($this->getCookieName(), $this->token, 60));

        // ... and attach it to broker session in SSO server.
        $this->attach();
    }

    /**
     * Delete saved unique client token.
     *
     * @return void
     */
    protected function deleteToken()
    {
        $this->token = null;
        Cookie::forget($this->getCookieName());
    }


    /**
     * Register client to SSO server with user credentials.
     *
     * @param  LaravelSSOBroker  $broker
     * @param  array  $data
     * @param  string  $broker_api_url
     *
     * @return bool
     */
    public function register(LaravelSSOBroker $broker, array $data, string $broker_api_url)
    {
        // create user on brokers
        $brokers = Broker::all();

        $brokers_registration = [];

        foreach ($brokers as $broker) {
            if($broker) {
                $brokers_registration[] = $broker->makeRequest('POST', 'createUserOnBroker', $data, $broker_api_url);
            }
        }

        $server_registration[] = $broker->makeRequest('POST', 'createUser', $data);
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
        $commandUrl = $this->generateCommandUrl($command, $url);

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '. $this->getSessionId(),
        ];

        switch ($method) {
            case 'POST':
                $body = ['form_params' => $parameters];
                break;
            case 'GET':
                $body = ['query' => $parameters];
                break;
            default:
                $body = [];
                break;
        }

        $client = new GuzzleHttp\Client;
        \Log::info([$method, $commandUrl, $body + ['headers' => $headers]]);
        $response = $client->request($method, $commandUrl, $body + ['headers' => $headers]);


        return json_decode($response->getBody(), true);
    }

    /**
     * Redirect client to specified url.
     *
     * @param string $url URL to be redirected.
     * @param array $parameters HTTP query string.
     * @param int $httpResponseCode HTTP response code for redirection.
     *
     * @return void
     */
    protected function redirect(string $url, array $parameters = [], int $httpResponseCode = 307)
    {
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
     * Getting current url which can be used as return to url.
     *
     * @return string
     */
    protected function getCurrentUrl()
    {
        return url()->full();
    }

    /**
     * Cookie name in which we save unique client token.
     *
     * @return string
     */
    protected function getCookieName()
    {
        // Cookie name based on broker's name because there can be some brokers on same domain
        // and we need to prevent duplications.
        return 'sso_token_' . preg_replace('/[_\W]+/', '_', strtolower($this->brokerName));
    }
}
