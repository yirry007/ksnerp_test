<?php

namespace App\Services\Supplier\Alibaba;

class Responser
{
    /**
     * @param $data
     * @return array
     */
    public static function format($data)
    {
        if (!$data) {
            return [
                'code'=>'E5099',
                'message'=>'null'
            ];
        }

        if (array_key_exists('error', $data) || array_key_exists('error_code', $data)) {
            /** 处理 token 验证失败 */
            if (array_key_exists('error_code', $data) && $data['error_code'] == '401') {
                return self::authFailed($data);
            }

            /** 系统性请求失败(地址，token，签名等) */
            return self::commonErr($data);

        } elseif (array_key_exists('access_token', $data)) {
            /** 获取或更新access_token */
            return self::tokenRes($data);

        } elseif (array_key_exists('result', $data) && array_key_exists('code', $data['result'])) {
            if ($data['result']['code'] != '200') {
                /** 平台一般性 api 请求错误 */
                return self::commonErr($data['result']);
            } else {
                /** api 请求成功 */
                return self::commonRes($data['result']);
            }
        } elseif (array_key_exists('success', $data)) {
            if (!$data['success']) {
                return self::commonErr($data);
            }

            if (array_key_exists('result', $data)) {
                if (array_key_exists('receiveAddressItems', $data['result'])) {
                    /** 获取地址列表 */
                    return self::onlyData($data['result']['receiveAddressItems']);
                }

                /** 生成订单返回值等 */
                return self::onlyData($data['result']);
            }

        } else {
            /** 未匹配数据格式化方法 */
            return self::originData($data);
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
     * 获取或更新token成功
     * @param $data
     * @return array
     */
    private static function tokenRes($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data;

        return $result;
    }

    /**
     * 成功请求api统一处理
     * @param $data
     * @return array
     */
    private static function commonRes($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';

        if (array_key_exists('result', $data)) {
            $result['result'] = $data['result'];

            //列表的原始数据已保存到 $data['Search']['OrderInfo']，而且很长，所以删除
            unset($data['result']);
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

        $result['code'] = $data['error'] ?? $data['error_code'] ?? $data['code'] ?? 'E5401';
        $result['message'] = $data['error_description'] ?? $data['error_message'] ?? $data['message'] ?? $data['code'] ?? 'request failed';
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 只处理数据部分
     * @param $data
     * @return array
     */
    private static function onlyData($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data;
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
