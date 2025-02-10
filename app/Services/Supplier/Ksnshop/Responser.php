<?php

namespace App\Services\Supplier\Ksnshop;

class Responser
{
    /**
     * @param $data
     * @return array
     */
    public static function format($data)
    {
//        if (is_null($data)) {
//            return [
//                'code'=>'E5099',
//                'message'=>'null'
//            ];
//        }
    }

    /**
     * token 认证失败
     * @param $data
     * @return array
     */
    private static function authFailed($data)
    {
        $result = array();

        $result['code'] = 'E0000';
        $result['message'] = 'Invalid token.';
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 成功请求api统一处理
     * @param $data
     * @return array
     */
    private static function CommonRes($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = $data['ResultMsg'];

        if (array_key_exists('ResultObject', $data)) {
            $result['result'] = $data['ResultObject'];

            //列表的原始数据已保存到 $data['Search']['OrderInfo']，而且很长，所以删除
            unset($data['ResultObject']);
        }

        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 平台一般性错误
     * @param $data
     * @return array
     */
    private static function commonErr($data)
    {
        $result = array();

        $result['code'] = array_key_exists('ErrorCode', $data) ? $data['ErrorCode'] : $data['ResultCode'];
        $result['message'] = array_key_exists('ErrorMsg', $data) ? $data['ErrorMsg'] : $data['ResultMsg'];
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 没有匹配格式化方法，原路返回
     * @param $data
     * @return array
     */
    private static function originData($data)
    {
        $result = array();

        $result['code'] = 'E5000';
        $result['message'] = 'no matching response formatter';
        $result['original'] = json_encode($data);

        return $result;
    }
}
