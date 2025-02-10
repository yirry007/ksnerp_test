<?php


namespace App\Services\Delivery;


interface CrawlInterface
{
    /**
     * 请求url，抓取页面数据
     * @param $trackingNumber
     * @return array
     */
    public function deliveryInfo($trackingNumber);
}
