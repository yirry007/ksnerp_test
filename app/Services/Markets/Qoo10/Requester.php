<?php

namespace App\Services\Markets\Qoo10;

use App\Models\RequestLog;
use App\Tools\Curls;

class Requester
{
    /**
     * qoo10服务器发送请求
     * @param Object $shop 店铺数据对象
     * @param $url
     * @param string $method
     * @param array $data
     * @param array $query
     * @param bool $withAuth
     * @return bool|string
     */
    public static function send($shop, $url, $method='GET', $data=[], $query=[], $withAuth=true)
    {
        $method = strtoupper($method);

        /** get 请求时 $data 和 $query 合并作为参数 */
        $query = $method == 'GET' ? array_merge($data, $query) : $query;

        /** 组合 url 参数 */
        $url = count($query) ? setQuery($url, $query) : $url;

        /** 组合请求数据 */
        $data = http_build_query($data);

        /** 组合请求头，获取 token 时不设置请求头 */
        $header = $withAuth ? self::setHeader($shop) : [];

        /** 设置代理 */
        $proxy = self::setProxy($shop);

        /** 发送请求 */
        $response = Curls::send($url, $method, $data, $header, $proxy);

        /** 记录请求日志，并随机删除7天前的日志 */
        RequestLog::create([
            'market'=>$shop->market,
            'sh_shop_id'=>$shop->shop_id,
            'url'=>$url,
            'method'=>$method,
            'data'=>$data,
            'header'=>json_encode($header),
            'proxy'=>json_encode($proxy),
            'create_time'=>date('Y-m-d H:i:s'),
            'response'=>$response
        ]);
        RequestLog::randomDelNDayAgo();

        return $response;
    }

    /**
     * 设置请求头
     * @param Object $shop
     * @return array
     */
    private static function setHeader($shop)
    {
        return [
            'Content-Type: application/x-www-form-urlencoded',
            'QAPIVersion: 1.0',
            'GiosisCertificationKey: ' . $shop->token,
        ];
    }

    /**
     * 设置请求代理
     * @param Object $shop
     * @return array
     */
    private static function setProxy($shop)
    {
        $data = array();

        if ($shop->proxy_on) {
            $proxy = getProxyInfo($shop->proxy_ip);

            $data = [
                'ip' => $proxy['ip'],
                'port' => $proxy['port'] ?? 3128,
            ];
        }

        return $data;
    }
}
