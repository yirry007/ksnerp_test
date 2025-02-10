<?php

namespace App\Services\Markets\Yahoo;

class Tool
{
    /**
     * 时间格式化，转为 YYYY-mm-dd HH:ii:ss 格式
     * @param $datetime
     * @return string|null
     */
    public static function formatDatetime($datetime)
    {
        return $datetime ? str_replace('T', ' ', substr($datetime, 0 ,19)) : null;
    }

    /**
     * 格式化商品选项
     * 例：
     * [{"Index"=>"1","Name"=>"カラー","Value"=>"ブルー","Price"=>"20"}, {"Index"=>"2","Name"=>"size","Value"=>"L","Price"=>"10"}]
     * 转为
     * ["id"=>"1-2","manage_id"=>"","data"=>["カラー"=>"ブルー","size"=>"L"], "price"=>30]
     * @param $options
     * @return array
     */
    public static function formatOption($options)
    {
        if (!$options) return [];

        $data = array();
        $ids = array();
        $optionData = array();
        $price = 0;

        $_options = array();
        if (array_key_exists('Index', $options)) {
            $_options[] = $options;
        } else {
            $_options = $options;
        }

        foreach ($_options as $v) {
            $ids[] = $v['Index'];
            $optionData[$v['Name']] = $v['Value'];
            $price += $v['Price'];
        }

        $data['id'] = implode('-', $ids);
        $data['manage_id'] = '';
        $data['data'] = $optionData;
        $data['price'] = $price;

        return $data;
    }

    /**
     * 雅虎订单状态转换成标准订单状态
     * @param $orderStatus
     * @param $isSeen
     * @param $payStatus
     * @param $shipStatus
     * @return int
     */
    public static function orderStatusMap($orderStatus, $isSeen, $payStatus, $shipStatus)
    {
        /**
         * Yahoo orderStatus
         * Order Status
         * 0: 未入力
         * 1: 予約中
         * 2: 処理中
         * 3: 保留
         * 4: キャンセル
         * 5: 完了
         * 8: 繰上げ同意待ち
         * Ship Status
         * 0: 出荷不可
         * 1: 出荷可
         * 2: 出荷処理中
         * 3: 出荷完了
         * 4: 着荷完了
         * Pay Status
         * 0: 未入金
         * 1: 入金済
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
        OrderStatus: 2
        IsSeen: false 	=> 0.新规订单
        ShipStatus: 0 	=> 1.入金确认(配送前)
        ShipStatus: 2 	=> 2.等待采购
        ShipStatus: 1
        [request api to change ShipStatus to 2 with deliveryCompany]
        PayStatus: 0 	=> 0.新规订单
        PayStatus: 1 	=> 2.等待采购
        ShipStatus: 3
        PayStatus: 0 	=> 6.入金确认(配送后)
        PayStatus: 1 	=> 5.配送完了 [request api to change ShipStatus to 5]
        OrderStatus: 3 		=> 99.保留
        OrderStatus: 4 		=> 98.取消
        OrderStatus: 5 		=> 7.订单完了
        ...					=> -1
         */

        /** 支付状态映射表，支付状态根据配送状态决定 erp 订单状态 */
        $payStatusMap = [
            '1'=>[// ShipStatus=1
                '0'=>'0',// KsnErp.order_status=0
                '1'=>'2',// KsnErp.order_status=1
            ],
            '3'=>[// ShipStatus=3
                '0'=>'6',// KsnErp.order_status=6
                '1'=>'5',// KsnErp.order_status=5
            ]
        ];

        /** 配送状态映射表，根据配送状态决定 erp 订单状态 */
        $shipStatusMap = [
            '0'=>'1',// KsnErp.order_status=1
            '1'=>$payStatusMap['1'][$payStatus],// 根据支付状态映射表决定
            '2'=>'2',// KsnErp.order_status=1
            '3'=>$payStatusMap['3'][$payStatus],// 根据支付状态映射表决定
        ];

        /** 订单状态映射表 */
        $OrderStatusMap = [
            '2'=>$isSeen == 'false' ? '0' : $shipStatusMap[$shipStatus],
            '3'=>'99',
            '4'=>'98',
            '5'=>'7',
        ];

        return array_key_exists($orderStatus, $OrderStatusMap) ? $OrderStatusMap[$orderStatus] : '-1';
    }

    /**
     * 以标准 api 订单状态，组合待更新的 yahoo 订单更新项目（基本订单状态，卖家确认状态，支付状态，配送状态）
     * @param $order_status int 标准 api 订单状态
     * @return array
     */
    public static function orderStatusUpdatingInfo($order_status)
    {
        $data = array();

        $data['currentStatus'] = $order_status;
        $data['nextStatus'] = $order_status + 1;

        if ($order_status == 0) {
            $data['IsSeen'] = 'true';
        }

        if ($order_status == 1 || $order_status == 6) {
            $data['PayStatus'] = 1;
        }

        if ($order_status == 3) {
            $data['verifyShippingData'] = 1;
        }

        if ($order_status == 4) {
            $data['ShipStatus'] = 3;
//            $data['OrderStatus'] = 5;
        }

        return $data;
    }
}
