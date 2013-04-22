<?php

Yii::import('ext.curl.*');

/**
 * ApiConnection
 * */
class ApiConnection extends CApplicationComponent {

    CONST METHOD_GET = 'GET';
    CONST METHOD_POST = 'POST';

    /**
     * @var ACurl object request
     */
    public $_curl;

    /**
     * @var string URL for the api to call
     */
    public $apiUrl;

    /**
     * @var string api id from 3scale
     */
    public $apiId;

    /**
     * @var string api key from 3scale
     */
    public $apiKey;

    public function getCurl() {
        if ($this->_curl === null) {
            $this->_curl = new ACurl();
        }
        return $this->_curl;
    }

    public function api($route, $method = self::METHOD_GET, $params = array(), $filters = array()) {
        return $this->makeRequest($route, $method, $params, $filters);
    }

    /**
     * TODO: Use query builder
     *
     * @param type $route
     * @param type $method
     * @param type $params
     * @return type
     */
    private function buildUrl($route, $method, $params, $filters) {
        $query = "";
        if ($method == self::METHOD_GET) {
            $query = '?' . $this->buildQuery($params) . $this->buildQuery($filters, 'f[%s]');
            $params = array();
        }

        return array(
            'url' => $this->apiUrl . $route . $query,
            'params' => $params
        );
    }

    private function buildQuery($data, $keyPattern = '%s') {
        $query = "";
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $query .= $this->buildQuery($value, sprintf($keyPattern, $key) . "[%s]");
            } else {
                $query .= sprintf($keyPattern, $key) . "=$value&";
            }
        }
        return $query;
    }

    /**
     * Helping function to make a request and get the response
     * TODO: FIX SSL_VERIFYPEER NOT WORKING
     *
     * @param String $route API route
     * @param String $method Request type GET/POST/PUT/DELETE
     * @param Array of strings $params GET/POST/PUT parameters
     * @return ACurlResponse object
     */
    private function makeRequest($route, $method, $params, $filters) {
        extract($this->buildUrl($route, $method, $params, $filters)); // $url $params

        try {
            $curl->options->ssl_verifypeer = 0;

            switch ($method) {
                case self::METHOD_GET:
                    $response = $this->curl->get($url);
                    break;
                case 'POST':
                    $response = $this->curl->post($url, $params);
                    break;
                case 'PUT':
                    $response = $this->curl->put($url, $params);
                    break;
                case 'DELETE':
                    $response = $this->curl->delete($url);
                    break;
            }
            Yii::log(sprintf('Curl %s request to %s', $method, $url), CLogger::LEVEL_INFO, 'app.components.apiConnection');
        } catch (ACurlException $e) {
            // Here we try to simulate an asynchronous request by setting the timeout to the minimum value.
            // In this case an exception (timeout) will be raised but we dont consider this an error as it was intended.
            $response = $e->response->data;
        }

        return CJSON::decode($response);
    }

}
