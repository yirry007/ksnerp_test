<?php

namespace App\Services\Supplier\Alibaba;

use App\Models\RequestLog;
use App\Tools\Curls;

class Requester
{
    /**
     * 1688 服务器发送请求（用 path 需要生成签名，最好 host 和 path 分别传递）
     * @param Object $supplierInfo 采购平台APP信息
     * @param String $url
     * @param string $method
     * @param array $data
     * @param boolean $refreshToken
     * @return bool|string
     */
    public static function send($supplierInfo, $url, $method='POST', $data=[], $refreshToken=false)
    {
        $method = strtoupper($method);

        $commonParam = [
            'app_key'=>$supplierInfo->app_key,
            'app_secret'=>$supplierInfo->app_secret,
            'token'=>$supplierInfo->token,
        ];

        if ($refreshToken) {
            $commonParam['refresh_token'] = $supplierInfo->refresh_token;
        }

        $param = array_merge($commonParam, $data);

        /** 设置请求参数 */
        if ($method == 'GET') {
            $url = count($param) ? setQuery($url, $param) : $url;
            $param = '';
        } else {
            $param = http_build_query($param);
        }

        $header = [
            'Authorization:5F46B16541867A33E1F49078F570598C'
        ];

        /** 发送请求 */
        $response = Curls::send($url, $method, $param, $header);

        /** 记录请求日志，并随机删除7天前的日志 */
        RequestLog::create([
            'market'=>$supplierInfo->market,
            'sh_shop_id'=>$supplierInfo->account,
            'url'=>$url,
            'method'=>$method,
            'data'=>$param,
            'create_time'=>date('Y-m-d H:i:s'),
            'response'=>$response
        ]);
        RequestLog::randomDelNDayAgo();

        return $response;
    }
}
