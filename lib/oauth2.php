<?php

/**
 * Oauth2 manager
 * @package framework
 * @subpackage oauth2
 */

/**
 * Class for dealing with Oauth2
 */
class Hm_Oauth2 {

    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $api;

    /**
     * Load default settings
     * @param string $id Oath2 client id
     * @param string $secret Oath2 client secret
     * @param string $uri URI to redirect to from the remote site
     */
    public function __construct($id, $secret, $uri) {
        $this->client_id = $id;
        $this->client_secret = $secret;
        $this->redirect_uri = $uri;
        $this->api = new Hm_API_Curl();
    }

    /**
     * Build a URL to request an authorization
     * @param string $url host to request authorization from
     * @param string $scope oauth2 scope
     * @param string $state current state of the oauth2 flow
     * @param string $login_hint optional username
     * @return string
     */
    public function request_authorization_url($url, $scope, $state, $login_hint = false) {
        $params = [
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'prompt' => 'consent',
            'access_type' => 'offline',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
        ];
        if ($login_hint !== false) {
            $params['login_hint'] = $login_hint;
        }
        return sprintf('%s?%s', $url, str_replace(
            '&',
            '&amp;',
            http_build_query($params, '', '&', PHP_QUERY_RFC3986)
        ));
    }

    /**
     * Use curl to exchange an authorization code for a token
     * @param string $url url to post to
     * @param string $authorization_code oauth2 auth code
     * @param array $headers HTTP headers to add to the request
     * @return array
     */
    public function request_token($url, $authorization_code, $headers = []) {
        return $this->api->command($url, $headers, array('code' => $authorization_code, 'client_id' => $this->client_id,
            'client_secret' => $this->client_secret, 'redirect_uri' => $this->redirect_uri, 'grant_type' => 'authorization_code'));
    }

    /**
     * Use curl to refresh an access token
     * @param string $url url to to post to
     * @param string $refresh_token oauth2 refresh token
     * @return array
     */
    public function refresh_token($url, $refresh_token) {
        return $this->api->command($url, [], array('client_id' => $this->client_id, 'client_secret' => $this->client_secret,
            'refresh_token' => $refresh_token, 'grant_type' => 'refresh_token'));
    }
}
