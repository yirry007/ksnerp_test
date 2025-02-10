<?php

namespace App\Services\Markets\Qoo10;

class Tool
{
    /**
     * 格式化商品选项
     * 例： 1枚目タイプ:ホワイト / 2枚目タイプ:ベージュ(+699円) 转成 ["サイズ"=>"60L","カラー"=>"レッド"]
     * @param $option
     * @return array
     */
    public static function formatOption($option)
    {
        if (!$option) return [];

        $data = array();
        $options = explode("/", $option);

        foreach ($options as $v) {
            $opts = explode(':', trim($v));
            $data[$opts[0]] = $opts[1] ?? '';
        }

        return $data;
    }

    /**
     * Qoo10订单状态转换成标准订单状态
     * Qoo10 shippingStatus： 1: 已下单/未付款 | 2: 已付款/卖家待确认 | 3: 卖家已确认/待发货 | 4: 已发货/待收货 | 5: 已收货
     * @param $status
     * @param $type String shipping(normal) or claim(cancel)
     * @return int
     */
    public static function orderStatusMap($status, $type='shipping')
    {
        /**
         * Qoo10 orderStatus normal
         * Awaiting shipping(1): 配送待ち
         * On request(2): 配送要請
         * Seller confirm(3): 配送準備
         * On delivery(4): 配送中
         * Delivered(5): 配送完了
         * Qoo10 orderStatus cancel
         * 1: キャンセル要請
         * 2: キャンセル中
         * 3: キャンセル完了
         * 4: 返品要請
         * 5: 返品中
         * 6: 返品完了
         * 11: 交換申請
         * 12: 交換承認
         * 13: 再配送中
         * 14: 未受取返金完了
         * 15: 未受取部分返金完了
         * 16: 未納の注文のキャンセル
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
        shippingStatus:
        1 	=> 1.入金确认(配送前)
        2 	=> 0.新规订单
        3 	=> 2.等待采购
        4 	=> 5.配送完了
        5 	=> 7.订单完了
        claimStatus:
        [request cancel order in Service/Markets]
        1|2|3 	=> 98.取消
         */

        $shippingStatusMap = [
            'Awaiting shipping(1)'=>'1',
            'On request(2)'=>'0',
            'Seller confirm(3)'=>'2',
            'On delivery(4)'=>'5',
            'Delivered(5)'=>'7',
        ];
        $claimStatus = '98';

        return $type == 'claim' ? $claimStatus : (array_key_exists($status, $shippingStatusMap) ? $shippingStatusMap[$status] : '-1');
    }

    /**
     * 以标准 api 订单状态，组合待更新的 Qoo10 订单更新项目（卖家确认状态，配送状态）
     * @param $order_status
     * @return array
     */
    public static function orderStatusUpdatingInfo($order_status)
    {
        $data = array();

        $data['currentStatus'] = $order_status;
        $data['nextStatus'] = $order_status + 1;

        if ($order_status == 0) {
            $data['confirmOrder'] = 1;
        }

        if ($order_status == 3) {
            $data['verifyShippingData'] = 1;
        }

        if ($order_status == 4) {
            $data['confirmShipping'] = 1;
        }

        return $data;
    }
}
