<?php


namespace App\Services\Supplier\Ksnshop;


use App\Services\Supplier\Api;
use App\Services\Supplier\ItemInterface;

class Item implements ItemInterface
{
    private $supplierInfo;
    private $method;

    public function __construct($supplierInfo)
    {
        $this->supplierInfo = $supplierInfo;
        $this->method = 'POST';
    }

    /**
     * 获取 ksnshop 商品详细
     * @param $itemId
     * @return array|mixed
     */
    public function getItem($itemId)
    {
        $url = Api::KSNSHOP_API_PREFIX . 'product/' . $itemId;

        $result = Requester::send($this->supplierInfo, $url);
        return json_decode($result, true);
    }
}
