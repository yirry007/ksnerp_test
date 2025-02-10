<?php


namespace App\Services\Markets\Rakuten;


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
     * 例： サイズ:60L\nカラー:レッド 转成 ["サイズ"=>"60L","カラー"=>"レッド"]
     * @param $option
     * @return array
     */
    public static function formatOption($option)
    {
        if (!$option) return [];

        $data = array();
        $options = explode("\n", $option);

        foreach ($options as $v) {
            $opts = explode(':', $v);
            $data[$opts[0]] = $opts[1];
        }

        return $data;
    }

    /**
     * 乐天订单状态转换成标准订单状态
     * @param $orderStatus
     * @return int
     */
    public static function orderStatusMap($orderStatus)
    {
        /**
         * Rakuten orderStatus
         * 100: 注文確認待ち
         * 200: 楽天処理中
         * 300: 発送待ち
         * 400: 変更確定待ち
         * 500: 発送済
         * 600: 支払手続き中
         * 700: 支払手続き済
         * 800: キャンセル確定待ち
         * 900: キャンセル確定
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
        orderProgress:
        100 	=> 0.新规订单
        200     => 1.入金确认(配送前)
        300		=> 2.等待采购
        500|600 => 7.订单完了
        900		=> 98.取消
        ...		=> -1
         */

        $orderStatusMap = [
            '100'=>'0',
            '200'=>'1',
            '300'=>'2',
            '500'=>'7',
            '600'=>'7',
            '900'=>'98',
        ];

        return array_key_exists($orderStatus, $orderStatusMap) ? $orderStatusMap[$orderStatus] : '-1';
    }

    /**
     * 以标准 api 订单状态，组合待更新的 rakuten 订单更新项目（卖家确认状态，配送状态）
     * @param $order_status int 标准 api 订单状态
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
            $data['updateOrderShipping'] = 1;
        }

        return $data;
    }
}
