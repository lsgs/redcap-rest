<?php
/**
 * REDCap External Module: ERDCap REST
 * Send API calls when saving particular instruments when a trigger condition is met.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\REDCapREST;

abstract class OAuth2ClientCredentials extends OAuth2 {

    public function oauth2Call(string $method, string $url, string $contentType, array $headers, array $curlOptions, string $payload, $allowRetry=false): void {
        // update access token if needed (first connection or expired)
        $this->updateAccessToken();

        $headers[] = "Authorization: Bearer ".$this->access_token;

        list($this->response, $this->info) = $this->module->curlCall($method, $url, $contentType, $headers, $curlOptions, $payload, true);
        
        if ($this->info['http_code'] === 401 && $allowRetry) {
            // retry once with new token
            $this->access_token = null;
            list($this->response, $this->info) = $this->module->curlCall($method, $url, $contentType, $headers, $curlOptions, $payload, false);
        }
    }

    /**
     * updateAccessToken()
     * If there is a cached and unexpired access token then do nothing, otherwise obtain a new token
     */
    protected function updateAccessToken(): void {
        // if have unexpired access token then do nothing
        if (!empty($this->access_token) && !empty($this->access_token_expiry)) {
            $tokenRefreshAt = $this->access_token_expiry->sub(new \DateInterval('PT5M'));
            $now = new \DateTime("now");
            if ($now < $tokenRefreshAt) return;
        }

        // obtain an access token
        $payload = http_build_query(array('grant_type' => 'client_credentials'));
        $curlOptions = array(CURLOPT_USERPWD, $this->client_id.":".$this->client_secret);

        list($response, $info) = $this->module->curlCall('POST', $this->token_endpoint, 'application/x-www-form-urlencoded', array(), $curlOptions, $payload);

        if (!isset($info['http_code']) && $info['http_code'] !== 200) {
            throw new \Exception('Unable to obtain access token');
        }

        $tokenDetails = json_decode($response, true); 
        if (!isset($tokenDetails['access_token']) || !isset($tokenDetails['expires_in'])) {
            throw new \Exception('Unexpected access token response');
        }

        $this->access_token = $tokenDetails['access_token'];
        
        $now = new \DateTime("now");
        $this->access_token_expiry = clone $now;
        $this->access_token_expiry = $this->access_token_expiry->add(new \DateInterval('PT'.intval($tokenDetails['expires_in']).'S'));

        $this->saveAccessToken();
    }
}