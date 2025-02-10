<?php

namespace App\Services\Supplier\Alibaba;

use App\Services\Supplier\Api;
use App\Services\Supplier\AuthInterface;
use Illuminate\Support\Facades\Config;

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
     * 获取1688用户授权url
     * @return array
     */
    public function getToken()
    {
        $return = array();

        /** 获取应用基本授权配置 */
        $config = Config::get('supplier.alibaba');
        $client_id = $config['app_key'];
        $site = $config['name'];
        $redirect_uri = Api::ALIBABA_PREFIX . 'redirect';

        /** 构建自定义参数，包含 user_id 和 market */
        $custom_param = [
            'user_id'=>$this->supplierInfo->user_id,
            'market'=>$this->supplierInfo->market,
            'url'=>urlencode(_url_('/'))
        ];
        $state = enc(json_encode($custom_param), Config::get('supplier.enc_key'));

        $return['code'] = 'E301';
        $return['message'] = 'redirect';
        $return['result'] = 'https://auth.1688.com/oauth/authorize?client_id=' . $client_id . '&site=' . $site . '&redirect_uri=' . $redirect_uri . '&state=' . urlencode($state);

        return $return;
    }

    /**
     * 刷新 access_token
     * @return array|bool|string
     */
    public function refreshToken()
    {
        $url = Api::ALIBABA_API_PREFIX . 'refresh_token';
        $result = Requester::send($this->supplierInfo, $url, $this->method, [], true);
        $res = json_decode($result, true);

        if (!$res) {
            $data['code'] = 'E4444';
            $data['message'] = 'Refresh token failed';
            return $data;
        }

        if ($res['code']) {
            return $res;
        }

        $data['code'] = '';
        $data['message'] = 'SUCCESS';
        $data['result']['token'] = $res['result']['access_token'];

        return $data;
    }
}
