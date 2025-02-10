<?php


namespace App\Services\Markets;


interface OrderInterface
{
    /**
     * 获取订单列表
     * @param array $params
     * @return array
     */
    public function getOrders($params=[]);

    /**
     * 获取订单详细
     * @param $orderId
     * @return array
     */
    public function getOrder($orderId);

    /**
     * 更新订单状态
     * @param $orderId
     * @param $orderStatus
     * @param array $infos
     * $info format:
     * [
     *     order_id => [
     *          shipping_company => some number,
     *          invoice_number_1 => some number,
     *          invoice_number_2 => some number,
     *     ],
     *     order_id => [
     *          shipping_company => some number,
     *          invoice_number_1 => some number,
     *          invoice_number_2 => some number,
     *     ]
     * ]
     * @return array
     */
    public function updateOrder($orderId, $orderStatus, $infos);

    /**
     * 取消订单
     * @param $orderId
     * @return array
     */
    public function cancelOrder($orderId);
}
