<?php

namespace App\Services\Markets\Yahoo;

use App\Services\Markets\Api;
use App\Services\Markets\AuthInterface;
use Illuminate\Support\Facades\Log;
use YConnect\Credential\ClientCredential;
use YConnect\YConnectClient;

class Auth implements AuthInterface
{
    private $shop;
    private $method;

    public function __construct($shop)
    {
        $this->shop = $shop;
        $this->method = 'POST';
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
        return [
            'code'=>'E0000',
            'message'=>'no data.',
        ];
    }

    /**
     * 刷新店铺 token
     * 刷新 access_token 时不带新的 refresh_token，因此不能更新 refresh_token
     * @return array
     */
    public function refreshToken()
    {
        $data = array();

        if (!$this->shop->refresh_token) {
            $data['code'] = 'E0000';
            $data['message'] = 'no refresh token.';
            return $data;
        }

        // クレデンシャルインスタンス生成
        $cred = new ClientCredential($this->shop->app_key, $this->shop->app_secret);
        // YConnectクライアントインスタンス生成
        $client = new YConnectClient($cred);

        // 追加 api 请求头信息
        $headers = [
            "X-sws-signature: " . getYahooEncAuthVal($this->shop),
            "X-sws-signature-version: 5"
        ];
        $client->setExtraHeaders($headers);

        // 添加代理服务器信息
//        if ($this->shop->proxy_on && $this->shop->proxy_ip) {
//            $proxyInfo = getProxyInfo($this->shop->proxy_ip);
//            count($proxyInfo) > 0 && $client->setCurlOption(['proxy'=>$proxyInfo]);
//        }

        try {
            // Tokenエンドポイントにリクエストしてアクセストークンを更新
            $client->refreshAccessToken($this->shop->refresh_token);

            $data['code'] = '';
            $data['message'] = 'SUCCESS';
            $data['result']['token'] = $client->getAccessToken();
        } catch (\Exception $e) {
            $data['code'] = 'E0000';
            $data['message'] = 'token refresh failed.';
        }

        return $data;
    }

    /**
     * 验证 token 是否有效
     * @return array
     */
    public function checkConnection()
    {
        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/externalTalkList';

        $this->setMethod('GET');

        $data = [
            'sellerId'=>$this->shop->shop_id,
            'result'=>1,
            'dateType'=>'sellerPostTime',
            'startDate'=>time()
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);

        if (strpos($response, '<?xml') !== false) {
            $result = xmlToArray($response);
        } else {
            $result = json_decode($response, true);
        }

        return Responser::format($result);
    }
}
