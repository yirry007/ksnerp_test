<?php

namespace App\Services\Supplier\Ksnshop;

use App\Models\RequestLog;
use App\Tools\Curls;

class Requester
{
    /**
     * ksnshop服务器发送请求
     * @param Object $supplierInfo 供应商对象
     * @param $url
     * @param string $method
     * @param array $data
     * @param array $query
     * @param bool $withAuth
     * @return bool|string
     */
    public static function send($supplierInfo, $url, $method='GET', $data=[], $query=[], $withAuth=true)
    {
        $method = strtoupper($method);

        /** get 请求时 $data 和 $query 合并作为参数 */
        $query = $method == 'GET' ? array_merge($data, $query) : $query;

        /** 组合 url 参数 */
        $url = count($query) ? setQuery($url, $query) : $url;

        /** 组合请求数据 */
        $data = http_build_query($data);

        /** 组合请求头，获取 token 时不设置请求头 */
        $header = $withAuth ? self::setHeader($supplierInfo) : [];

        /** 发送请求 */
        $response = Curls::send($url, $method, $data, $header);

        /** 记录请求日志，并随机删除7天前的日志 */
        RequestLog::create([
            'market'=>$supplierInfo->market,
            'sh_shop_id'=>$supplierInfo->shop_id ?? '',
            'url'=>$url,
            'method'=>$method,
            'data'=>$data,
            'header'=>json_encode($header),
            'create_time'=>date('Y-m-d H:i:s'),
            'response'=>$response
        ]);
        RequestLog::randomDelNDayAgo();

        return $response;
    }

    /**
     * 设置请求头
     * @param Object $supplierInfo
     * @return array
     */
    private static function setHeader($supplierInfo)
    {
        return [
            'Content-Type: application/x-www-form-urlencoded',
            'token: ' . $supplierInfo->token,
        ];
    }
}
