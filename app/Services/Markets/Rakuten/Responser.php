<?php


namespace App\Services\Markets\Rakuten;


class Responser
{
    /**
     * @param $data
     * @return array
     */
    public static function format($data)
    {
        if (is_null($data)) {
            /** 处理 $data 为 null 的情况，一般为代理服务器故障 */
            return [
                'code'=>'E5002',
                'message'=>'Data json decode failed',
            ];
        }

        if (array_key_exists('Results', $data)) {
            /** 处理 token 验证失败 */
            if ($data['Results']['errorCode'] == 'ES04-01') {
                return self::authFailed($data);
            }

            /** API 维护中 */
            if ($data['Results']['errorCode'] == 'ES01-02') {
                return [
                    'code'=>'E5909',
                    'message'=>$data['Results']['message'],
                ];
            }

            /** 错误返回 */
            return self::commonErr($data);

        } elseif (array_key_exists('orderNumberList', $data)) {
            /** 订单列表 */
            return self::orderList($data);

        } elseif (array_key_exists('OrderModelList', $data)) {
            /** 订单详细 */
            return self::orderView($data);

        } elseif (array_key_exists('resultCode', $data) && $data['resultCode'] == 'N000') {
            /** xml成功返回值 */
            return self::commonXML($data);

        } elseif (array_key_exists('element', $data)) {
            /** xml失败返回值 */
            return self::commonXMLErr($data);

        } elseif (array_key_exists('MessageModelList', $data)) {
            /** 无结果集返回信息的处理，这个一定放在最下面，不然先匹配这个 */
            return self::commonRes($data);

        } elseif (array_key_exists('code', $data) && $data['code'] == 'E6011') {
            /** xml转array失败，code 为 E6011 */
            return $data;

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

        if (is_null($data['orderNumberList'])) {
            $result['code'] = 'E5001';
            $result['message'] = 'request parameter error.';
            $result['original'] = json_encode($data);
            return $result;
        }

        $result['code'] = '';
        $result['message'] = 'SUCCESS';
        $result['result'] = $data['orderNumberList'];
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
        $result['result'] = $data['OrderModelList'];

        //列表的原始数据已保存到 $result['result']，而且很长，所以删除
        unset($data['OrderModelList']);

        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 无结果集返回信息的处理
     * @param $data
     * @return array
     */
    private static function commonRes($data)
    {
        $result = array();

        $return = $data['MessageModelList'][0];

        $result['code'] = $return['messageType'] == 'INFO' ? '' : 'E5003';
        $result['message'] = $return['message'];
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 处理从xml转换过来的请求成功数据
     * @param $data
     * @return array
     */
    private static function commonXML($data)
    {
        $result = array();

        $result['code'] = $data['resultCode'] == 'N000' ? '' : 'E5004';
        $result['message'] = $data['resultMessageList']['resultMessage']['message'];
        $result['result'] = array_key_exists('result', $data) ? $data['result'] : null;
        $result['original'] = json_encode($data);

        return $result;
    }

    /**
     * 处理从xml转换过来的请求失败数据
     * @param $data
     * @return array
     */
    private static function commonXMLErr($data)
    {
        $result = array();

        $result['code'] = $data['element']['code'];
        $result['message'] = $data['element']['message'];

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

        $result['code'] = $data['Results']['errorCode'];
        $result['message'] = $data['Results']['message'];
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
