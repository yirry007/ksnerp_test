<?php

namespace App\Services\Supplier\Alibaba;

use App\Services\Supplier\Api;
use App\Services\Supplier\ItemInterface;

class Item implements ItemInterface
{
    public $supplierInfo;
    private $method;

    public function __construct($supplierInfo)
    {
        $this->supplierInfo = $supplierInfo;
        $this->method = 'POST';
    }

    /**
     * 上传 base64 图片，获取 imageId
     * @param $base64str
     * @return bool|string
     */
    public function getImageId($base64str)
    {
        $url = Api::ALIBABA_API_PREFIX . 'get_image_id';
        $param = [
            'imageBase64'=>$base64str,
        ];

        $res = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($res);
    }

    /**
     * 根据 imageId 搜索商品列表
     * @param $imageId
     * @param array $params
     * @return bool|string
     */
    public function searchItemsByImageId($imageId, $params=[])
    {
        $url = Api::ALIBABA_API_PREFIX . 'search_items_by_image_id';
        $param = [
            'imageId'=>$imageId,
            'beginPage' => $params['beginPage'] ?? 1,
            'pageSize'  => $params['pageSize'] ?? 20,
        ];

        $res = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($res, true);
    }

    /**
     * 获取商品的详细信息
     * @param $itemId
     * @return array|bool|string
     */
    public function getItem($itemId)
    {
        $url = Api::ALIBABA_API_PREFIX . 'get_item';
        $param = [
            'offerId'=>$itemId
        ];

        $result = Requester::send($this->supplierInfo, $url, $this->method, $param);
        $res = json_decode($result, true);

        if (!$res) {
            return [
                'code'=>'E9099',
                'message'=>'null'
            ];
        }

        if (!$res['code'] && count($res['result'])) {
            $res['result'] = $this->dataMap($res['result']);
        }

        return $res;
    }

    /**
     * 筛选字段
     * @param $data
     * @return array
     */
    private function dataMap($data)
    {
        $_data = array();

        $_data['item_id'] = $data['offerId'];//offerId -> productID
        $_data['title'] = $data['subject'];//subject
        $_data['images'] = $data['productImage']['images'];//productImage.images -> image.images
        $_data['sku'] = array_key_exists('productSkuInfos', $data) ? $this->setSku($data['productSkuInfos']) : [];//productSkuInfos -> skuInfos
        $_data['min_buy'] = $data['minOrderQuantity'] ?? 1;//minOrderQuantity

        return $_data;
    }

    /**
     * 设置商品选项信息
     * @param $skuInfo
     * @return array
     */
    private function setSku($skuInfo)
    {
        $_data = array();

        foreach ($skuInfo as $k=>$v) {
            $_data[$k]['spec_id'] = $v['specId'];
            $_data[$k]['price'] = $v['price'] ?? $v['jxhyPrice'] ?? $v['consignPrice'] ?? '0';

            $sku = array();
            foreach ($v['skuAttributes'] as $k1=>$v1) {
                $sku[$k1][$v1['attributeName']] = $v1['value'];

                if (array_key_exists('skuImageUrl', $v1) && $v1['skuImageUrl'])
                    $_data[$k]['sku_image'] = $v1['skuImageUrl'];
            }

            $_data[$k]['data'] = $sku;
        }

        return $_data;
    }
}
