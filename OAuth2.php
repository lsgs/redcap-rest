<?php
/**
 * REDCap External Module: ERDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

abstract class OAuth2 {
    protected $response;
    protected $info;
    protected $module;
    protected $instruction;
    protected $instruction_index;
    protected $token_endpoint;
    protected $client_id;
    protected $client_secret;

    protected $access_token;
    protected $access_token_expiry;
    protected $refresh_token;

    public function __construct(REDCapREST $module, array $instruction, int $index) {
        $this->response = '';
        $this->info = array('http_code' => 500);
        $this->module = $module;
        $this->instruction = $instruction;
        $this->instruction_index = $index;

        $configString = $this->module->pipeApiToken($instruction['oauth2-config']);
        $config = json_decode($configString, true);

        $this->token_endpoint = $config['auth-url'];
        $this->client_id = $config['client-id'];
        $this->client_secret = $config['client-secret'];

        $cache = json_decode($instruction['oauth2-cache'], true);
        $this->access_token = (isset($cache['access_token'])) ? $cache['access_token'] : null;
        $this->access_token_expiry = (isset($cache['access_token_expiry'])) ? new \DateTime($cache['access_token_expiry']) : null;
        $this->refresh_token = (isset($cache['refresh_token'])) ? $cache['refresh_token'] : null;
    }

    abstract public function oauth2Call(string $method, string $url, string $contentType, array $headers, array $curlOptions, string $payload, $allowRetry=false): void;

    /**
     * updateAccessToken()
     * If there is a cached and unexpired access token then do nothing, otherwise obtain a new token
     */
    abstract protected function updateAccessToken(): void;

    /**
     * saveAccessToken()
     * Save access token and expiry time to current index of project settings (hidden oauth-cache setting)
     */
    protected function saveAccessToken() {
        $cache = json_decode($this->instruction['oauth2-cache'], true);
        $cache['access_token'] = $this->access_token;
        $cache['access_token_expiry'] = $this->access_token_expiry->format('Y-m-i H:i:s');

        $projectSetting = $this->module->getProjectSetting("oauth2-cache");
        $projectSetting[$this->instruction_index] = json_encode($cache, JSON_FORCE_OBJECT);
        $this->module->setProjectSetting("oauth2-cache", $projectSetting);
    }

    public function getResponse(): string {
        return $this->response;
    }

    public function getInfo(): array {
        return $this->info;
    }
}