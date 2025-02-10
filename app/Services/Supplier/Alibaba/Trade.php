<?php

namespace App\Services\Supplier\Alibaba;

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

    /**
     * 获取 1688 收货地址列表
     * @return array|bool|string
     */
    public function getAddress()
    {
        $url = Api::ALIBABA_API_PREFIX . 'get_address';

        $result = Requester::send($this->supplierInfo, $url, $this->method);
        $res = json_decode($result, true);

        if ($res['code']) {
            return $res;
        }

        foreach ($res['result'] as $k=>$v) {
            $res['result'][$k] = self::formatAddress($v);
        }

        return $res;
    }

    /**
     * 创建1688订单
     * @param array $params
     * @return array|string[]
     */
    public function createOrder($params = [])
    {
        $previewRes = $this->createOrderPreview($params);

        if ($previewRes['code']) {
            return $previewRes;
        }

        $url = Api::ALIBABA_API_PREFIX . 'create_order';

        $param = [
            'flow'      => $previewRes['result']['flowFlag'],
            'addressParam'  => json_encode([
                'addressId'     => $params['address']['addressId'],
                'fullName'      => $params['address']['receiver'],
                'mobile'        => $params['address']['mobile'],
                'phone'         => $params['address']['mobile'],
                'postCode'      => $params['address']['postCode'],
                'cityText'      => $params['address']['city'],
                'provinceText'  => $params['address']['province'],
                'areaText'      => $params['address']['area'],
                'townText'      => $params['address']['town'],
                'address'       => $params['address']['address'],
                'districtCode'  => $params['address']['addressCode'],
            ]),
            'cargoParamList' => json_encode([
                'offerId'       => $params['item']['supply_code'],
                'specId'        => $params['item']['supply_opt'],
                'quantity'      => $params['item']['total_quantity'] * $params['item']['supply_unit'],
            ]),
        ];

        $res = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($res, true);
    }

    public function getOrders($params = [])
    {
        // TODO: Implement getOrders() method.
    }

    /**
     * 获取1688订单详细
     * @param $orderId
     * @return mixed
     */
    public function getOrder($orderId)
    {
        $url = Api::ALIBABA_API_PREFIX . 'get_order';
        $param = [
            'orderId' => $orderId
        ];

        $result = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($result, true);
    }

    /**
     * 订单预览，用于获取flow
     * @param array $params
     * @return array
     */
    public function createOrderPreview($params = [])
    {
        $url = Api::ALIBABA_API_PREFIX . 'create_order_preview';

        $param = [
            'addressParam'  => json_encode([
                'addressId'     => $params['address']['addressId'],
                'fullName'      => $params['address']['receiver'],
                'mobile'        => $params['address']['mobile'],
                'phone'         => $params['address']['mobile'],
                'postCode'      => $params['address']['postCode'],
                'cityText'      => $params['address']['city'],
                'provinceText'  => $params['address']['province'],
                'areaText'      => $params['address']['area'],
                'townText'      => $params['address']['town'],
                'address'       => $params['address']['address'],
                'districtCode'  => $params['address']['addressCode'],
            ]),
            'cargoParamList' => json_encode([
                'offerId'       => $params['item']['supply_code'],
                'specId'        => $params['item']['supply_opt'],
                'quantity'      => $params['item']['total_quantity'] * $params['item']['supply_unit'],
            ]),
        ];

        $res = Requester::send($this->supplierInfo, $url, $this->method, $param);
        return json_decode($res, true);
    }

    /**
     * 格式化下单地址
     * @param $address
     * @return array
     */
    private static function formatAddress($address)
    {
        $addressCodeText = explode(' ', $address['addressCodeText']);
        $province = $addressCodeText[0] ?? '';
        $city = $addressCodeText[1] ?? '';
        $area = $addressCodeText[2] ?? '';

        return [
            'addressId'     => $address['id'],
            'receiver'      => $address['fullName'],
            'address'       => $address['address'],
            'postCode'      => $address['post'],
            'mobile'        => $address['mobilePhone'],
            'addressCode'   => $address['addressCode'],
            'province'      => $province,
            'city'          => $city,
            'area'          => $area,
            'isDefault'     => $address['isDefault'],
            'townCode'      => $address['townCode'] ?? '',
            'town'          => $address['townName'] ?? '',
        ];
    }
}
