<?php


namespace App\Services\Supplier;


interface ItemInterface
{
    /**
     * 获取商品详细
     * @param $itemId
     * @return array
     */
    function getItem($itemId);
}
