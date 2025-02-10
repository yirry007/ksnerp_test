<?php

namespace App\Services\Markets\Wowma;

class Responser
{
    /**
     * @param $data
     * @return array
     */
    public static function format($data)
    {
        /** 请求失败 502 Bad Gateway */
        if (array_key_exists('head', $data)) {
            return self::requestError($data);
        }

        /** xml转array失败，code 为 E6011 */
        if (array_key_exists('code', $data) && $data['code'] == 'E6011') {
            return $data;
        }

        if (!array_key_exists('result', $data)) {
            return self::requestError($data);
        }

        if ($data['result']['status']) {
            /** 处理 token 验证失败 */
            if ($data['result']['status'] == '1' && array_key_exists('error', $data['result']) && $data['result']['error']['code'] == '0002') {
                return self::authFailed($data);
            }

            return self::commonErr($data);
        } else {
            if (array_key_exists('orderInfo', $data)) {
                return self::order($data);
            } else {
                return self::commonRes($data);
            }
        }
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
     * 订单列表
     * @param $data
     * @return array
     */
    private static function order($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data['orderInfo'];

        //列表的原始数据已保存到 $data['orderInfo']，而且很长，所以删除
        unset($data['orderInfo']);

        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 平台一般成功返回值
     * @param $data
     * @return array
     */
    private static function commonRes($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
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

        $result['code'] = $data['result']['status'];
        $result['message'] = array_key_exists('error', $data['result']) ? $data['result']['error']['message'] : $data['result']['message'];
        $result['original'] = json_encode($data);

        return $result;
    }

    private static function requestError($data)
    {
        $result = array();

        $result['code'] = 'Request Error.';
        $result['message'] = $data['head']['title'] ?? json_encode($data);
        $result['original'] = json_encode($data);

        return $result;
    }
}
