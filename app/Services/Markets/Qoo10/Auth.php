<?php

namespace App\Services\Markets\Qoo10;

use App\Services\Markets\Api;

class Auth
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
     * 获取店铺 token
     * @return array
     */
    public function getToken()
    {
        $url = Api::QOO10_API_PREFIX;

        $query = [
            'v'=>'1.0',
            'returnType'=>'json',
            'method'=>'CertificationAPI.CreateCertificationKey',
            'user_id'=>$this->shop->shop_id,
            'pwd'=>$this->shop->shop_pwd,
            'key'=>$this->shop->app_key,
        ];

        $response = Requester::send($this->shop, $url, $this->method, $query, [], false);
        $result = json_decode($response, true);
        $return = Responser::format($result);

        if ($return['code']) {
            return $return;
        }

        $data['code'] = '';
        $data['message'] = 'SUCCESS';
        $data['result']['token'] = $return['result'];
        $data['result']['refresh_token'] = '';

        return $data;
    }

    /**
     * 刷新店铺 token，Qoo10 不刷新 token，只能重新请求
     * @return array
     */
    public function refreshToken():array
    {
        return $this->getToken();
    }

    /**
     * 验证 token 是否有效
     * @return array
     */
    public function checkConnection()
    {
        $url = Api::QOO10_API_PREFIX . '/CommonInfoLookup.SearchBrand';

        $this->setMethod('POST');

        $response = Requester::send($this->shop, $url, $this->method);
        $result = json_decode($response, true);
        $return = Responser::format($result);

        /** code 值不为 E0000 表示token验证成功 */
        $return['code'] = $return['code'] != 'E0000' ? '' : $return['code'];

        return $return;
    }
}
