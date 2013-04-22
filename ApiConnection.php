<?php
Yii::import('ext.curl.*');
/**
 * ApiConnection
 **/
class ApiConnection extends CApplicationComponent
{
    /**
     * @var ACurl object request
     */
    public static $curl;

    /**
     * URL for the api to call
     */
    const LIVE    = 'https://api.peopleperhour.com/v1/';
    const STAGING = 'https://staging-api.peopleperhour.com/v1/';

    /**
     * @var string api id from 3scale
     */
    public $apiId;

    /**
     * @var string api key from 3scale
     */
    public $apiKey;

    public function save($class, $attributes)
    {
    }

    public function getCurl()
    {
        if(self::$curl===null) {
            self::$curl = new ACurl();
        }
        return self::$curl;
    }
}
