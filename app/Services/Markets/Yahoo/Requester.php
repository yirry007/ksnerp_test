<?php

namespace App\Services\Markets\Yahoo;

use App\Models\RequestLog;
use App\Services\Markets\Api;
use App\Tools\Curls;

class Requester
{
    /**
     * yahoo服务器发送请求
     * @param Object $shop 店铺数据对象
     * @param $url
     * @param string $method
     * @param array $data
     * @param array $query
     * @return bool|string
     */
    public static function send($shop, $url, $method='GET', $data=[], $query=[])
    {
        $method = strtoupper($method);

        /** get 请求时 $data 和 $query 合并作为参数 */
        $query = $method == 'GET' ? array_merge($data, $query) : $query;

        /** 组合 url 参数 */
        $url = count($query) ? setQuery($url, $query) : $url;

        /** 组合请求数据 */
        $data = count($data) ? self::setData($data, $url) : '';

        /** 设置请求头 */
        $header = self::setHeader($shop, $url, $method);

        /** 设置代理（Yahoo不设置代理服务器） */
//        $proxy = self::setProxy($shop);
        $proxy = [];

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
            'response'=>json_encode(xmlToArray($response), JSON_UNESCAPED_UNICODE)
        ]);
        RequestLog::randomDelNDayAgo();

        return $response;
    }

    /**
     * 设置请求头
     * @param Object $shop 店铺对象
     * @param $url
     * @param $method
     * @return array
     */
    private static function setHeader($shop, $url, $method)
    {
        $apiPath = ltrim($url, Api::YAHOO_API_PREFIX);

        return [
            $method . ' ' . $apiPath . ' HTTP/1.1',
            'Host: circus.shopping.yahooapis.jp',
            'Authorization: Bearer ' . $shop->token,
            'X-sws-signature: ' . getYahooEncAuthVal($shop),
            'X-sws-signature-version: ' . $shop->key_version,
        ];
    }

    /**
     * 设置请求代理
     * @param Object $shop 店铺对象
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

    /**
     * 设置请求参数，一部分是xml，一部分是query_string
     * @param $data array
     * @param $url
     * @return string
     */
    private static function setData($data, $url)
    {
        $return = '';

        $dateTypeMap = [
            'orderStatusChange'=>'xml',
            'orderList'=>'xml',
            'orderInfo'=>'xml',
            'orderChange'=>'xml',
            'orderPayStatusChange'=>'xml',
            'orderShipStatusChange'=>'xml',
//            'externalTalkList'=>'query',
        ];

        $type = 'xml';
        foreach ($dateTypeMap as $k=>$v) {
            if (strpos($url, $k) !== false) {
                $type = $v;
                break;
            }
        }

        if ($type == 'xml') {
            $xmlStr = arrayToXml($data, 'Req');
            $return = str_replace('<?xml version="1.0"?>', '', $xmlStr);
        }
        if ($type == 'query') {
            $return = http_build_query($data);
        }

        return $return;
    }
}
