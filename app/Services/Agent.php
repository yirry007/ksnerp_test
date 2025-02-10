<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\SupplierInfo;

class Agent
{
    /** @var string[] the method that do not call refresh function */
    const SKIP_TOKEN_VERIFY = ['getToken', 'refreshToken', 'deliveryInfo'];

    private static $space = '\App\Services';
    private static $module;
    private static $serviceInstance;
    private static $class;

    /**
     * Set module and service instance, then return Agent instance
     * @param $name
     * @param $arguments
     * @return Agent
     */
    public static function __callStatic($name, $arguments)
    {
        /** make class name with namespace */
        self::$module = '\\'.$name;
        self::$serviceInstance = $arguments[0];

        /** return instance of the Agent class */
        return new Agent();
    }

    /**
     * Dynamic instance a class, then call the function
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $platform = self::getPlatformName();

        $fullClassName = self::$space . self::$module . $platform . self::$class;
        $obj = new $fullClassName(self::$serviceInstance);

        $result = $obj->$name(...$arguments);

        if (
            in_array($name, self::SKIP_TOKEN_VERIFY)
            || !array_key_exists('code', $result)
            || $result['code'] != 'E0000'
        )
        {
            /** exactly return the result */
            return $result;
        }

        /** refresh token */
        if (!$this->refreshToken()) {
            return $result;
        }

        /** recall the method after refresh token */
        return $obj->$name(...$arguments);
    }

    /**
     * Set the class name will be instance in service module
     * @param $class
     * @return $this
     */
    public function setClass($class)
    {
        self::$class = '\\'.$class;
        return $this;
    }

    /**
     * Get platform name, e.x: Qoo10, Rakuten, Wowma, Yahoo ...
     * @return string
     */
    private static function getPlatformName()
    {
        $platform = '';

        if (gettype(self::$serviceInstance) == 'string')
            $platform = self::$serviceInstance;

        if (self::$serviceInstance instanceof Shop)
            $platform = (self::$serviceInstance)->market;

        if (self::$serviceInstance instanceof SupplierInfo)
            $platform = (self::$serviceInstance)->market;

        return '\\'.$platform;
    }

    /**
     * refresh token | update database
     * @return bool
     */
    private function refreshToken()
    {
        $instance = self::$serviceInstance;

        $authClassName = self::$space . self::$module . '\\' .$instance->market . '\Auth';
        $auth = new $authClassName($instance);
        $token = $auth->refreshToken();

        if ($token['code']) {
            return false;
        }

        $updateTime = null;
        if (array_key_exists('token', $token['result']) && $token['result']['token']) {
            $instance->token = $token['result']['token'];
            $updateTime = date('Y-m-d H:i:s');
        }

        if (array_key_exists('refresh_token', $token['result']) && $token['result']['refresh_token'])
            $instance->refresh_token = $token['result']['refresh_token'];

        /** refresh token failed */
        if (!$updateTime) {
            return false;
        }

        /** update token in database */
        $instance->update_time = $updateTime;
        $result = $instance->save();

        /** update database failed */
        if (!$result) {
            return false;
        }

        return true;
    }
}
