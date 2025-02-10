<?php

namespace App\Services\Markets\Yahoo;

class Responser
{
    /**
     * @param $data
     * @return array
     */
    public static function format($data)
    {
        /** 处理 token 验证失败 */
        if (array_key_exists('Message', $data) && strpos($data['Message'], 'invalid_token') !== false) {
            return self::authFailed($data);
        }

        /** xml转array失败，code 为 E6011 */
        if (array_key_exists('code', $data) && $data['code'] == 'E6011') {
            return $data;
        }

        if (
            array_key_exists('Message', $data)
            || (array_key_exists('Search', $data) && $data['Status'] != 'OK')
            || (array_key_exists('Result', $data) && $data['Result']['Status'] != 'OK')
        ) {
            /** 错误返回值 | 空结果集 */
            return self::commonErr($data);

        } elseif (array_key_exists('Search', $data) && $data['Status'] == 'OK') {
            /** 订单列表数据 */
            return self::orderList($data);

        } elseif (array_key_exists('Result', $data) && array_key_exists('OrderInfo', $data['Result'])) {
            /** 订单详细数据 */
            return self::orderView($data);

        } elseif (
            (array_key_exists('Result', $data) && $data['Result']['Status'] == 'OK')
            || (array_key_exists('Status', $data) && $data['Status'] == 'OK')
        ) {
            /** 无结果集的成功 */
            return self::commonRes($data);

        } elseif (array_key_exists('summary', $data)) {
            /** 用户提问列表（用于店铺联动测试） */
            return self::talkList($data);

        } else {
            /** 格式化的方法未匹配 */
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
     * 订单列表
     * @param $data
     * @return array
     */
    private static function orderList($data)
    {
        $result = array();
        $orderList = array();

        /** 构建订单列表数据 */
        if ($data['Search']['TotalCount'] > 0) {
            $orderList = array_key_exists('OrderId', $data['Search']['OrderInfo']) ? [$data['Search']['OrderInfo']] : $data['Search']['OrderInfo'];//1个订单就一维数组，多个则二维

            //列表的原始数据已保存到 $data['Search']['OrderInfo']，而且很长，所以删除
            unset($data['Search']['OrderInfo']);
        }

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $orderList;//1个订单就一维数组，多个则二维
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 订单详细
     * @param $data
     * @return array
     */
    private static function orderView($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data['Result']['OrderInfo'];

        //列表的原始数据已保存到 $data['Result']['OrderInfo']，而且有很长，所以删除
        unset($data['Result']['OrderInfo']);

        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 提问列表，用于店铺联动测试
     * @param $data
     * @return array
     */
    private static function talkList($data)
    {
        $result = array();

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data['summary'];
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 普通返回值，需要写在最后，否则可能发生冲突
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

        $result['code'] = $data['Code'] ?? 'E5001';
        $result['message'] = $data['Message'] ?? 'Request failed';
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
