<?php

namespace App\Services\Markets\Rakuten;

use App\Services\Markets\Api;
use App\Services\Markets\AuthInterface;

class Auth implements AuthInterface
{
    private $shop;
    private $method;

    public function __construct($shop)
    {
        $this->shop = $shop;
        $this->method = 'GET';
    }

    /**
     * 设置api请求方法
     * @param $method
     */
    private function setMethod($method)
    {
        $this->method = $method;
    }

    /**
     * 获取店铺 token，乐天的 token 不需要请求 api，直接在客户端创建
     * @return array
     */
    public function getToken()
    {
        $data = array();

        $token = base64_encode($this->shop->app_secret . ':' . $this->shop->app_key);
        $data['code'] = '';
        $data['messate'] = 'SUCCESS';
        $data['result']['token'] = $token;
        $data['result']['refresh_token'] = '';

        return $data;
    }

    /**
     * 获取店铺 token，乐天的 token 刷新不需要请求 api
     * @return array
     */
    public function refreshToken()
    {
        return [
            'code'=>'E0000',
            'message'=>'no data.',
        ];
    }

    /**
     * 验证 token 是否有效
     * @return array
     */
    public function checkConnection()
    {
        $url = Api::RAKUTEN_API_PREFIX . '/es/1.0/shop/shopMaster';

        $response = Requester::send($this->shop, $url, $this->method);
        $result = xmlToArray($response);
        return Responser::format($result);
    }
}
