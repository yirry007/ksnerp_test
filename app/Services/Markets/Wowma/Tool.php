<?php

namespace App\Services\Markets\Wowma;

class Tool
{
    /**
     * 时间格式化，转为 YYYY-mm-dd HH:ii:ss 格式（原格式：2023/09/20 14:07）
     * @param $datetime
     * @return string|null
     */
    public static function formatDatetime($datetime)
    {
        return str_replace('/', '-', $datetime) . ':00';
    }

    /**
     * 格式化商品选项
     * 例： カラー=C&size=M。 转成 ["カラー"=>"C","size"=>"M"]
     * @param $option
     * @return array
     */
    public static function formatOption($option)
    {
        if (!$option) return [];

        parse_str($option, $data);

        return $data;
    }

    /**
     * 沃玛订单状态转换成标准订单状态
     * @param $orderStatus
     * @param $shippingNumber
     * @return int
     */
    public static function orderStatusMap($orderStatus, $shippingNumber)
    {
        /**
         * Wowma orderStatus
         * 新規受付
         * 発送前入金待ち
         * 与信待ち
         * 発送待ち
         * 発送後入金待ち
         * 完了
         * 保留
         * キャンセル
         * 各種カスタムステータス（受注管理で貴店舗が登録したステータス名）
         * 新規予約
         * 予約中
         * 不正取引審査中
         * 審査保留
         * 審査NG
         * キャンセル受付中
         *
         * KsnErp order_status
         * 0: 新规订单
         * 1: 入金确认(配送前)
         * 2: 等待采购
         * 3: 等待出库
         * 4: 出库处理
         * 5: 配送完了
         * 6: 入金确认(配送后)
         * 7: 订单完了
         * 98: 取消
         * 99: 保留
         */

        /**
        orderStatus:
        新規受付 		=> 0.新规订单
        発送前入金待ち 	=> 1.入金确认(配送前)
        発送後入金待ち 	=> 6.入金确认(配送后)
        発送待ち
        shippingNumber
        isset && len > 0 	=> 5.配送完了 [order status updated to "完了"]
        null 				=> 2.等待采购
        完了 			=> 7.订单完了
        保留 			=> 99.保留
        キャンセル 		=> 98.取消
        ...				=> -1
         */

        $orderStatusMap = [
            '新規受付'=>'0',
            '発送前入金待ち'=>'1',
            '発送後入金待ち'=>'6',
            '発送待ち'=>$shippingNumber ? '5' : '2',
            '完了'=>'7',
            '保留'=>'99',
            'キャンセル'=>'98',
        ];

        return array_key_exists($orderStatus, $orderStatusMap) ? $orderStatusMap[$orderStatus] : '-1';
    }

    /**
     * 以标准 api 订单状态，组合待更新的 wowma 订单更新项目（订单状态，订单信息）
     * @param $order_status int 标准 api 订单状态
     * @param int $beforeShipPayment 是否配送前入金
     * @return array
     */
    public static function orderStatusUpdatingInfo($order_status, $beforeShipPayment = 1)
    {
        $data = array();

        $data['currentStatus'] = $order_status;
        $data['nextStatus'] = $order_status + 1;

        if ($order_status == 0) {
            if ($beforeShipPayment == 1) {
                $data['orderStatus'] = '発送前入金待ち';
            } else {
                $data['orderStatus'] = '発送待ち';
                $data['nextStatus'] = 2;
            }
        }

        if ($order_status == 1) {
            $data['orderStatus'] = '発送待ち';
        }

        if ($order_status == 3) {
            $data['verifyShippingData'] = 1;
        }

        if ($order_status == 4) {
            $data['updateOrderShipping'] = 1;
            $data['orderStatus'] = '完了';
        }

        if ($order_status == 6) {
            $data['orderStatus'] = '完了';
        }

        return $data;
    }
}
