<?php


namespace App\Services\Supplier\Ksnshop;


use App\Services\Supplier\Api;
use App\Services\Supplier\AuthInterface;

class Auth implements AuthInterface
{
    private $supplierInfo;
    private $method;

    public function __construct($supplierInfo)
    {
        $this->supplierInfo = $supplierInfo;
        $this->method = 'POST';
    }

    /**
     * ksnshop 用户授权
     * @return array|mixed
     */
    public function getToken()
    {
        $url = Api::KSNSHOP_API_PREFIX . 'authorize';

        $data = array();
        $data['account'] = $this->supplierInfo->app_key;
        $data['pwd'] = $this->supplierInfo->app_secret;

        $result = Requester::send($this->supplierInfo, $url, $this->method, $data, [], false);
        return json_decode($result, true);
    }

    /**
     * ksnshop 平台不需要 refresh token
     * @return array|string[]
     */
    function refreshToken()
    {
        return [
            'code'=>'E0000',
            'message'=>'no data.',
        ];
    }
}
