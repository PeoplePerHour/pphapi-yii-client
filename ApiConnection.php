<?php

Yii::import('ext.curl.*');

/**
 * ApiConnection is used to do requests to PPH API
 * */
class ApiConnection extends CApplicationComponent {

    /**
     * Logging path
     */
    const LOGPATH = 'app.components.apiConnection';

    /**
     * Http methods
     */
    CONST HTTP_METHOD_GET = 'GET';
    CONST HTTP_METHOD_POST = 'POST';
    CONST HTTP_METHOD_PUT = 'PUT';
    CONST HTTP_METHOD_DELETE = 'DELETE';

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

    /**
     * On local machines SSL certificate check should be bypassed
     * @var Bool
     */
    public $sslCurlCheck = true;

    /**
     * Set to the number of seconds needed to cache each request, false to deactivate it
     * @var mixed $cacheDuration Integer means seconds, false to deactivate cache
     */
    public $cacheDuration = false;
    CONST CACHE_KEY = 'api-connection-';

    /**
     * @var array of implemented/valid routes of api
     */
    private $_implementedRoutes = array(
        'hourlie',          // Hourlie
        'hourlieCategory',  // Hourlie Category
        'invoice',          // Invoice
        'job',              // Job
        'jobCategory',      // Job Category
        'locale',           // Locale - It returns locale data of the current client
        'notification',     // Notification
        'proposal',         // Proposal
        'payment',          // Payment
        'stream',           // Stream
        'user',             // User
        'meta',             // Meta API - gather information about the API itself
    );

    /**
     * @var ACurl object request
     */
    public $_curl;

    public function getCurl() {
        if ($this->_curl === null) {
            $this->_curl = new ACurl();
        }
        return $this->_curl;
    }

    /**
     * Make an API call.
     *
     * @param String $route API route
     * @param String $method Request type GET/POST/PUT/DELETE
     * @param Array of strings $options GET/POST/PUT parameters
     * @return mixed The decoded response (cached or not)
     */
    public function api($route, $method = self::HTTP_METHOD_GET, $options = array()) {
        $this->clearErrors();

        if (!$this->validateRoute($route))
            return false;

        return $this->makeRequest($route, $method, $options);
    }

    /**
     * Validate first part of route is in implemented routes.
     * notice: Validation can be extended to request valid routes and actions from API
     *
     * @param string $route
     * @return boolean
     */
    public function validateRoute($route) {
        $parts = explode('/', $route);
        if (in_array($parts[0], $this->_implementedRoutes))
            return true;

        $this->addError("Route '$route' not implemented");
        return false;
    }

    /**
     * Build an array out of the url and the options needed for a request to API
     *
     * @param type $route
     * @param type $method
     * @param type $options
     * @return array
     */
    private function buildUrl($route, $method, $options) {
        $query = '?' . $this->buildAuth();
        if ($method == self::HTTP_METHOD_GET) {
            $query .= $this->buildQuery($options);
            $options = array();
        }

        return array(
            'url' => $this->apiUrl . $route . $query,
            'params' => $options
        );
    }

    /**
     * Build query string from array
     *
     * @param array of strings $data
     * @param string $format spintf first parameter
     * @return string
     */
    private function buildQuery($data, $format = '%s') {
        $query = "";
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $query .= $this->buildQuery($value, sprintf($format, $key) . "[%s]");
            } else {
                $query .= sprintf($format, $key) . "=$value&";
            }
        }
        return $query;
    }

    /**
     * Build auth credentials to use in query string
     * @return string
     */
    private function buildAuth() {
        if ($this->apiId && $this->apiKey)
            return $this->buildQuery(array(
                        'app_id' => $this->apiId,
                        'app_key' => $this->apiKey,
            ));

        Yii::log('Api credentials are missing.', CLogger::LEVEL_WARNING, self::LOGPATH);
        return '';
    }

    /**
     * Helping function to make a request and get the response
     *
     * @param String $route API route
     * @param String $method Request type GET/POST/PUT/DELETE
     * @param Array of strings $options GET/POST/PUT parameters
     * @return mixed array on success, false otherwise (use getErrors() to get the reason of failure)
     */
    protected function makeRequest($route, $method, $options) {
        // Create $url and $params used in request
        extract($this->buildUrl($route, $method, $options));

        // Get cached response if exist
        $cacheKey = $this->cacheDuration ? self::CACHE_KEY . md5(serialize(array($route, $method, $options))) : '';
        if ($this->cacheDuration && isset(Yii::app()->cache) && $response = Yii::app()->cache->get($cacheKey)) {
            YII_DEBUG && Yii::log("API response to '$route' loaded from cache", CLogger::LEVEL_INFO, self::LOGPATH);
            return $response;
        }

        if ($this->sslCurlCheck === false) {
            $this->curl->options->ssl_verifypeer = 0; // verifying the peer's SSL certificate
            $this->curl->options->ssl_verifyhost = 0; // check the existence of a common name in the SSL peer certificate
        }

        // TOMNOTE: I found that $curl->put() wasn't working. It needs curl_setopt($ch, CURLOPT_POST, true);
        // TODO: If Charles merges pull-request https://github.com/phpnode/YiiCurl/pull/5 we can replace the 4 lines with: $response = $curl->put($url, $data);
        if ($method == self::HTTP_METHOD_PUT) {
            $this->curl->options->post = 1;
        }

        switch ($method) {
            case self::HTTP_METHOD_GET:
                $request = $this->curl->get($url, false);
                break;
            case self::HTTP_METHOD_POST:
                $request = $this->curl->post($url, $params, false);
                break;
            case self::HTTP_METHOD_PUT:
                $request = $this->curl->put($url, $params, false);
                break;
            case self::HTTP_METHOD_DELETE:
                $request = $this->curl->delete($url, false);
                break;
        }
        try {
            Yii::log(sprintf('Curl %s request to %s', $method, $url), CLogger::LEVEL_INFO, 'app.components.apiConnection');
            $response = $this->processResponse($request->exec(), $cacheKey);
        } catch (ACurlException $e) {
            switch ($e->statusCode) {
                case 400:
                    $response = CJSON::decode($e->response->data, false);
                    $this->addError(ucfirst(str_replace(array('-', '_'), ' ', $response->error)));
                    break;
                case 401: // Unauthorized
                    $this->addError('The action requires a logged in user');
                    break;
                case 403: // Forbidden
                    $this->addError('The current user is not allowed to perform this action');
                    break;
                case 404: // Not Found (e.g. record with given id does not exist)
                    $this->addError('The requested ressource was not found');
                    break;

                default:
                    break;
            }
            $response = false;
        }

        return $response;
    }

    /**
     * Decode and check response
     *
     * @param json $response
     * @param string $cacheKey
     * @return mixed array if response is valid, false otherwise
     */
    private function processResponse($response, $cacheKey) {
        // Decode and check response
        $response = json_decode($response, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            $this->addError('Response should be in json format');
            return false;
        }

        // Save response to cache if needed
        if ($this->cacheDuration && isset(Yii::app()->cache) && !empty($response)) {
            Yii::app()->cache->set($cacheKey, $response, $this->cacheDuration);
            YII_DEBUG && Yii::log("API response to saved in cache", CLogger::LEVEL_INFO, self::LOGPATH);
        }

        return $response;
    }

    /**
     * ERROR HANDLING
     */

    /**
     * Array of errors
     * @var Array of strings
     */
    private $_errors = array();

    /**
     * Returns a value indicating whether there is any validation error.
     * @return boolean whether there is any error.
     */
    public function hasErrors() {
        return $this->_errors !== array();
    }

    /**
     * Returns the errors for all attribute or a single attribute.
     * @return array errors for all attributes or the specified attribute. Empty array is returned if no error.
     */
    public function getErrors() {
        return $this->_errors;
    }

    /**
     * Adds a new error to the specified attribute.
     * @param string $error new error message
     */
    public function addError($error) {
        Yii::log($error, CLogger::LEVEL_WARNING, self::LOGPATH);
        $this->_errors[] = $error;
    }

    /**
     * Removes errors for all attributes or a single attribute.
     */
    public function clearErrors() {
        $this->_errors = array();
    }

}
