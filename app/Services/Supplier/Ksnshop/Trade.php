<?php


namespace App\Services\Supplier\Ksnshop;


use App\Services\Supplier\Api;
use App\Services\Supplier\TradeInterface;

class Trade implements TradeInterface
{
    public $supplierInfo;
    private $method;

    public function __construct($supplierInfo)
    {
        $this->supplierInfo = $supplierInfo;
        $this->method = 'POST';
    }

    public function getAddress()
    {
        // TODO: Implement getAddress() method.
    }

    /**
     * 创建 ksnshop 订单
     * @param array $params
     * @return array|mixed
     */
    public function createOrder($params = [])
    {
        $url = Api::KSNSHOP_API_PREFIX . 'order';

        $param = [
            'name'         => $params['item']['name'],
            'phone'        => $params['item']['phone'],
            'address'      => $params['item']['address'],
            'zipcode'      => $params['item']['zipcode'],
            'attr_unique'  => $params['item']['supply_opt'],
            'num'          => $params['item']['total_quantity'] * $params['item']['supply_unit'],
        ];

        $res = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($res, true);
    }

    public function getOrders($params = [])
    {
        // TODO: Implement getOrders() method.
    }

    public function getOrder($orderId)
    {
        // TODO: Implement getOrder() method.
    }

    /**
     * 根据 order_id 获取快递信息
     * @param $orderId
     * @return mixed
     */
    public function getDelivery($orderId)
    {
        $url = Api::KSNSHOP_API_PREFIX . 'delivery/' . $orderId;

        $res = Requester::send($this->supplierInfo, $url);
        return json_decode($res, true);
    }
}
