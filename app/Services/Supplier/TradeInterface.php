<?php


namespace App\Services\Supplier;


interface TradeInterface
{
    /**
     * 获取收货地址
     * @return array
     */
    function getAddress();

    /**
     * 生成平台采购订单
     * @param array $params
     * @return array
     */
    function createOrder($params=[]);

    /**
     * 获取采购订单列表
     * @param array $params
     * @return mixed
     */
    function getOrders($params=[]);

    /**
     * 获取采购订单详细
     * @param $orderId
     * @return mixed
     */
    function getOrder($orderId);
}
