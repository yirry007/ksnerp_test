<?php

namespace App\Services\Markets\Wowma;

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
     * 获取店铺 token，沃玛的 token 不需要请求 api，直接在客户端创建
     * @return array
     */
    public function getToken()
    {
        $data = array();

        $data['code'] = '';
        $data['message'] = 'SUCCESS';
        $data['result']['token'] = $this->shop->app_key;
        $data['result']['refresh_token'] = '';

        return $data;
    }

    /**
     * 刷新店铺 token，沃玛的 token 刷新不需要请求 api
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
        $url = Api::WOWMA_API_PREFIX . '/searchImgCapacity';

        $response = Requester::send($this->shop, $url, $this->method, ['shopId'=>$this->shop->shop_id]);
        $result = xmlToArray($response);
        return Responser::format($result);
    }
}
