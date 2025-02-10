<?php

namespace App\Services\Markets\Rakuten;

use App\Services\Markets\Api;
use App\Services\Markets\OrderInterface;

class Order implements OrderInterface
{
    private $shop;
    private $method;
    private $conf;

    public function __construct($shop)
    {
        $this->shop = $shop;
        $this->method = 'POST';
        $this->conf = include(__DIR__ . '/config.php');
    }

    /**
     * 获取订单列表
     * @param array $params
     * @return array
     */
    public function getOrders($params=[])
    {
        $orders = $this->orderList($params);

        //请求的数据有误，直接返回
        if ($orders['code'] || !array_key_exists('result', $orders)) {
            return $orders;
        }

        //数据转换为标准数据格式
        $result = array();
        foreach ($orders['result'] as $v) {
            $result[] = $this->dataMap($v);
        }

        $orders['result'] = $result;

        return $orders;
    }

    /**
     * 获取订单详细
     * @param $orderId
     * @return array
     */
    public function getOrder($orderId)
    {
        $return = array();

        $order = $this->orderView($orderId);

        if (!$order['code'] || count($order['result'])) {//订单数据转为标准数据格式
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $this->dataMap($order['result'][0]);
        } else {
            $return['code'] = 'E9404';
            $return['message'] = 'Get order data failed.';
        }

        return $return;
    }

    /**
     * 更新订单状态
     * @param $orderId
     * @param $orderStatus
     * @param array $infos
     * @return array
     */
    public function updateOrder($orderId, $orderStatus, $infos=[])
    {
        $data = array();

        //待更新的状态
        $updatingInfo = Tool::orderStatusUpdatingInfo($orderStatus);

        $data['result']['current_status'] = $updatingInfo['currentStatus'];
        $data['result']['next_status'] = $updatingInfo['nextStatus'];

        /** 没有可更新项目，直接返回错误 */
        if (
            !array_key_exists('confirmOrder', $updatingInfo)
            && !array_key_exists('updateOrderShipping', $updatingInfo)
            && !array_key_exists('verifyShippingData', $updatingInfo)
        ) {
            $data['code'] = '';
            $data['message'] = 'no need updating in market';
            return $data;
        }

        /** 卖家确认订单 */
        if (array_key_exists('confirmOrder', $updatingInfo)) {
            $res = $this->confirmOrder($orderId);
            $data['code'] = $res['code'];
            $data['message'] = $res['message'];
        }

        /** 订单状态为等待出库时，需要验证是否输入国际物流信息 */
        if (array_key_exists('verifyShippingData', $updatingInfo)) {
            $shippingCompany = $infos['shipping_company'] ?? '';
            $invoiceNumber1 = $infos['invoice_number_1'] ?? '';
            $invoiceNumber2 = $infos['invoice_number_2'] ?? '';

            if (!$shippingCompany) {
                $data['code'] = 'E9011';
                $data['message'] = __('lang.Please select logistic.');
                return $data;
            }

            if (!$invoiceNumber1 && !$invoiceNumber2) {
                $data['code'] = 'E9012';
                $data['message'] = __('lang.Please input shipping number.');
                return $data;
            }
        }

        /** 更新发货信息 */
        if (array_key_exists('updateOrderShipping', $updatingInfo)) {
            $res = $this->updateOrderShipping($orderId, $infos);
            $data['code'] = $res['code'];
            $data['message'] = $res['message'];
        }

        $data['code'] = $data['code'] ?? '';
        $data['message'] = $data['message'] ?? 'SUCCESS';

        return $data;
    }

    /**
     * 取消订单
     * @param $orderId
     * @return array
     */
    public function cancelOrder($orderId)
    {
        $return = array();

        $url = Api::RAKUTEN_API_PREFIX . '/es/2.0/order/cancelOrder/';
        $data = [
            'orderNumberList'=>$orderId,
            'inventoryRestoreType'=>1,
            'changeReasonDetailApply'=>10,
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = json_decode($response, true);
        $res = Responser::format($result);

        $return['code'] = $res['code'];
        $return['message'] = $res['message'];

        return $return;
    }

    /**
     * 设置api请求方法
     * @param $method
     */
    private function setMethod($method)
    {
        $this->method = $method;
    }

    /**
     * Api获取订单列表
     * @param array $params
     * @return array
     */
    private function orderList($params=[])
    {
        $url = Api::RAKUTEN_API_PREFIX . '/es/2.0/order/searchOrder/';

        $timezone = '+0900';
        $startdate = $params['startdate'] ?? date('Y-m-d');
        $enddate = $params['enddate'] ?? date('Y-m-d');

        $starttime = $startdate . 'T00:00:00' . $timezone;
        $endtime = $enddate . 'T23:59:59' . $timezone;

        $data = [
            'dateType'=>1,
            'startDatetime'=>$starttime,
            'endDatetime'=>$endtime,
            'PaginationRequestModel'=>[
                'requestRecordsAmount'=>1000,
                'requestPage'=>1,
                'SortModel'=>[
                    'sortColumn'=>1,
                    'sortDirection'=>2
                ],
            ],
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = json_decode($response, true);
        $orders = Responser::format($result);
        if ($orders['code']) {
            return $orders;
        }

        $orderIds = implode(',', $orders['result']);

        return $this->orderView($orderIds);
    }

    /**
     * Api获取订单详细
     * @param string $orderIds 逗号（隔开可以传多个订单id）
     * @return array
     */
    private function orderView($orderIds)
    {
        $return = array();
        $resultGroup = array();

        $url = Api::RAKUTEN_API_PREFIX . '/es/2.0/order/getOrder/';

        $orderIdArr = array_chunk(explode(',', $orderIds), 100);

        foreach ($orderIdArr as $ids) {
            if (!count($ids)) continue;

            $data = [
                'orderNumberList'=>$ids,
                'version'=>7
            ];

            $response = Requester::send($this->shop, $url, $this->method, $data);
            $result = json_decode($response, true);
            $res = Responser::format($result);

            if ($res['code']) continue;

            $resultGroup = array_merge($resultGroup, $res['result']);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $resultGroup;

        return $return;
    }

    /**
     * 卖家确认订单
     * @param $orderId
     * @return array
     */
    private function confirmOrder($orderId)
    {
        $url = Api::RAKUTEN_API_PREFIX . '/es/2.0/order/confirmOrder/';
        $data = [
            'orderNumberList'=>[$orderId]
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = json_decode($response, true);
        return Responser::format($result);
    }

    /**
     * 更新订单配送状态
     * 只处理第一个包裹<PackageModelList[0]>
     * 只处理第一个配送信息<ShippingModelList[0]>
     * 只处理第一个乐天订单更新返回值
     * @param $orderId
     * @param $infos
     * @return array
     */
    private function updateOrderShipping($orderId, $infos)
    {
        /** api中获取新的订单数据 */
        $order = $this->orderView($orderId);

        if ($order['code'] || !count($order['result'])) {
            return [
                'code'=>'E9404',
                'message'=>'Rakuten' . __('lang.Can not find order.'),
            ];
        }

        $url = Api::RAKUTEN_API_PREFIX . '/es/2.0/order/updateOrderShipping/';

        $package = $order['result'][0]['PackageModelList'][0];
        $shippingDetailId = $package['ShippingModelList'][0]['shippingDetailId'] ?? null;
        $shippingCompanies = array_flip($this->conf['shipping_companies']);
        $shippingCompany = $shippingCompanies[$infos['shipping_company']];

        $basketData = array();
        $shippingData = array();

        $basketData['basketId'] = $package['basketId'];

        /** 处理第一个物流单号 */
        if ($infos['invoice_number_1']) {
            $_shipping = array();

            if ($shippingDetailId) $_shipping['shippingDetailId'] = $shippingDetailId;
            $_shipping['deliveryCompany'] = $shippingCompany;
            $_shipping['shippingNumber'] = $infos['invoice_number_1'];
            $_shipping['shippingDate'] = $infos['shipping_date'] ?? date('Y-m-d');

            $shippingData[] = $_shipping;
        }
        /** 处理第二个物流单号 */
        if ($infos['invoice_number_2']) {
            $_shipping = array();

            if ($shippingDetailId) $_shipping['shippingDetailId'] = $shippingDetailId;
            $_shipping['deliveryCompany'] = $shippingCompany;
            $_shipping['shippingNumber'] = $infos['invoice_number_2'];
            $_shipping['shippingDate'] = $infos['shipping_date'] ?? date('Y-m-d');

            $shippingData[] = $_shipping;
        }

        $basketData['ShippingModelList'] = $shippingData;

        $data = [
            'orderNumber'=>$orderId,
            'BasketidModelList'=>[
                $basketData
            ],
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = json_decode($response, true);
        return Responser::format($result);
    }

    /**
     * 数据格式化，转换为标准数据格式
     * @param array $data
     * @return array
     */
    private function dataMap($data)
    {
        $_data = array();

        $sender = $data['PackageModelList'][0]['SenderModel'] ?? $data['OrdererModel'];

        $_data['market'] = 'Rakuten';
        $_data['device'] = $this->conf['devices'][$data['carrierCode']];
        $_data['shop_id'] = $this->shop->shop_id;
        $_data['order_id'] = $data['orderNumber'];
        $_data['order_time'] = Tool::formatDatetime($data['orderDatetime']);
        $_data['order_status'] = Tool::orderStatusMap($data['orderProgress']);
        $_data['pay_method'] = $data['SettlementModel']['settlementMethod'];
        $_data['pay_status'] = $data['orderProgress'] == 700 ? 1 : 0;
        $_data['pay_time'] = null;
        $_data['currency'] = 'JPY';
        $_data['remark'] = $data['remarks'] ?: '';
        $_data['total_price'] = $data['totalPrice'];
        $_data['zipcode'] = $sender['zipCode1'] . '-' . $sender['zipCode2'];
        $_data['country'] = 'Japan';
        $_data['prefecture'] = $sender['prefecture'];
        $_data['city'] = $sender['city'];
        $_data['address_1'] = $sender['subAddress'];
        $_data['address_2'] = '';
        $_data['full_address'] = $_data['prefecture'] . $_data['city'] . $_data['address_1'];
        $_data['name_1'] = $sender['familyName'] . ' ' . $sender['firstName'];
        $_data['name_2'] = $sender['familyNameKana'] . ' ' . $sender['firstNameKana'];
        $_data['phone_1'] = $sender['phoneNumber1'] . '-' . $sender['phoneNumber2'] . '-' . $sender['phoneNumber3'];
        $_data['phone_2'] = '';
        $_data['email'] = $data['OrdererModel']['emailAddress'];

        $_data = array_merge($_data, $this->setPackageData($data['PackageModelList']));
        $_data['infos'] = $this->setInfos($data);

        /** PackageModelList 中 SenderModel 和 ItemModelList 已处理完毕，可以删除 */
        for ($i=0;$i<count($data['PackageModelList']);$i++) {
            unset($data['PackageModelList'][$i]['SenderModel']);
            unset($data['PackageModelList'][$i]['ItemModelList']);
        }
        $_data['packages'] = $data['PackageModelList'];

        return $_data;
    }

    /**
     * 格式化package数据，包含商品和物流信息
     * @param array $packages
     * @return array
     */
    private function setPackageData($packages)
    {
        if (!count($packages)) {
            return [
                [
                    'shipping_company'=>'',
                    'invoice_number_1'=>'',
                    'invoice_number_2'=>'',
                    'shipping_time'=>null,
                    'items'=>[],
                ]
            ];
        }

        $data = array();

        /** 第一个包裹作为基本配送信息 */
        $package = $packages[0];
        $shipping = count($package['ShippingModelList']) ? $package['ShippingModelList'][0] : null;
        $companyCode = $shipping ? $shipping['deliveryCompany'] : '';

        $data['shipping_company'] = $this->conf['shipping_companies'][$companyCode] ?? '';
        $data['invoice_number_1'] = $shipping ? $shipping['shippingNumber'] : '';
        $data['invoice_number_2'] = '';
        $data['shipping_time'] = $shipping ? $shipping['shippingDate'] : null;

        /** 包裹中取出所有商品作订单商品数据 */
        $data['items'] = array();
        foreach ($packages as $v) {
            /** 处理包裹中的商品和它的配送信息 */
            $itemModel = $v['ItemModelList'];
            foreach ($itemModel as $v1) {
                $data['items'][] = $this->setItemData($v1, $v);
            }
        }

        return $data;
    }

    /**
     * 格式化商品数据
     * @param $item
     * @param array $package
     * @return array
     */
    private function setItemData($item, $package=[])
    {
        $data = array();

        $data['item_id'] = $item['itemId'];
        $data['item_sub_id'] = $item['itemDetailId'];
        $data['item_management_id'] = $item['manageNumber'];
        $data['item_name'] = $item['itemName'];
        $data['item_number'] = $item['itemNumber'] ?: '';
        $data['item_price'] = $item['price'];
        $data['quantity'] = $item['units'];
        $data['item_url'] = 'https://item.rakuten.co.jp/' . $this->shop->shop_id . '/' . $item['manageNumber'];

        $skuModel = count($item['SkuModelList']) ? $item['SkuModelList'][0] : null;

        $option = array();
        if ($skuModel) {
            $option['id'] = $skuModel['variantId'];
            $option['manage_id'] = $skuModel['merchantDefinedSkuId'];
            $option['data'] = Tool::formatOption($skuModel['skuInfo']);
            $option['price'] = 0;
        }
        $data['options'] = $option;
        $data['item_options'] = $data['options']['data'] ?? [];

        /** 添加商品的其他信息 */
        $infos = array();
        if (count($package)) {
            if (array_key_exists('SenderModel', $package) && $package['SenderModel']) {
                /** 包裹配送信息（有些订单有多个包裹配送多个地址） */
                $sender = $package['SenderModel'];

                $infos[0]['name'] = __('lang.zipcode.');
                $infos[0]['value'] = $sender['zipCode1'] . '-' . $sender['zipCode2'];
                $infos[1]['name'] = __('lang.address.');
                $infos[1]['value'] = $sender['prefecture'] . ' ' . $sender['city'] . ' ' . $sender['subAddress'];
                $infos[2]['name'] = __('lang.name.') . ' 1';
                $infos[2]['value'] = $sender['familyName'] . ' ' . $sender['firstName'];
                $infos[3]['name'] = __('lang.name.') . ' 2';
                $infos[3]['value'] = $sender['familyNameKana'] . ' ' . $sender['firstNameKana'];
                $infos[4]['name'] = __('lang.tel.');
                $infos[4]['value'] = $sender['phoneNumber1'] . '-' . $sender['phoneNumber2'] . '-' . $sender['phoneNumber3'];
            }
        }
        $data['infos'] = $infos;

        return $data;
    }

    /**
     * 设置订单额外或许可用信息
     * @param $order
     * @return array
     */
    private function setInfos($order)
    {
        $data = array();

        $data[] = [
            'name'=>__('lang.PackingNo.') . '(1)',
            'key'=>'basketId',
            'value'=>$order['PackageModelList'][0]['basketId'],
            'require'=>'1',
        ];

        $data[] = [
            'name'=>__('lang.shippingDetailId.'),
            'key'=>'shippingDetailId',
            'value'=>count($order['PackageModelList'][0]['ShippingModelList']) ? $order['PackageModelList'][0]['ShippingModelList'][0]['shippingDetailId'] : '',
            'require'=>'1',
        ];

        $data[] = [
            'name'=>__('lang.shopOrderCfmDatetime.'),
            'key'=>'shopOrderCfmDatetime',
            'value'=>$order['shopOrderCfmDatetime'] ? Tool::formatDatetime($order['shopOrderCfmDatetime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.orderFixDatetime.'),
            'key'=>'orderFixDatetime',
            'value'=>$order['orderFixDatetime'] ? Tool::formatDatetime($order['orderFixDatetime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.hopeDate.'),
            'key'=>'shippingInstDatetime',
            'value'=>$order['shippingInstDatetime'] ? Tool::formatDatetime($order['shippingInstDatetime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.shippingCmplRptDatetime.'),
            'key'=>'shippingCmplRptDatetime',
            'value'=>$order['shippingCmplRptDatetime'] ? Tool::formatDatetime($order['shippingCmplRptDatetime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.orderType.'),
            'key'=>'orderType',
            'value'=>$this->conf['order_types'][$order['orderType']],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.reserveNumber.'),
            'key'=>'reserveNumber',
            'value'=>$order['reserveNumber'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.reserveDeliveryCount.'),
            'key'=>'reserveDeliveryCount',
            'value'=>$order['reserveDeliveryCount'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.goodsPrice.'),
            'key'=>'goodsPrice',
            'value'=>$order['goodsPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.requestPrice.'),
            'key'=>'requestPrice',
            'value'=>$order['requestPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.goodsTax.'),
            'key'=>'goodsTax',
            'value'=>$order['goodsTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.postagePrice.'),
            'key'=>'postagePrice',
            'value'=>$order['postagePrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryPrice.'),
            'key'=>'deliveryPrice',
            'value'=>$order['deliveryPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.paymentCharge.'),
            'key'=>'paymentCharge',
            'value'=>$order['paymentCharge'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.paymentChargeTaxRate.'),
            'key'=>'paymentChargeTaxRate',
            'value'=>$order['paymentChargeTaxRate'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.couponAllTotalPrice.'),
            'key'=>'couponAllTotalPrice',
            'value'=>$order['couponAllTotalPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.additionalFeeOccurAmountToUser.'),
            'key'=>'additionalFeeOccurAmountToUser',
            'value'=>$order['additionalFeeOccurAmountToUser'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.settlementMethod.'),
            'key'=>'settlementMethod',
            'value'=>$order['SettlementModel']['settlementMethod'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryName.'),
            'key'=>'deliveryName',
            'value'=>$order['DeliveryModel']['deliveryName'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryClass.'),
            'key'=>'deliveryClass',
            'value'=>$order['DeliveryModel']['deliveryClass'] ? $this->conf['delivery_class'][$order['DeliveryModel']['deliveryClass']] : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.usedPoint.'),
            'key'=>'usedPoint',
            'value'=>$order['PointModel']['usedPoint'],
            'require'=>'0',
        ];

        if ($order['WrappingModel1']) {
            $data[] = [
                'name'=>'ラッピングタイトル1',
                'key'=>'title',
                'value'=>$this->conf['wrap_title'][$order['WrappingModel1']['title']],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング名1',
                'key'=>'name',
                'value'=>$order['WrappingModel1']['name'],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング料金1',
                'key'=>'price',
                'value'=>$order['WrappingModel1']['price'],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング税込別1',
                'key'=>'includeTaxFlag',
                'value'=>$this->conf['wrap_include_tax'][$order['WrappingModel1']['includeTaxFlag']],
                'require'=>'0',
            ];
        }

        if ($order['WrappingModel2']) {
            $data[] = [
                'name'=>'ラッピングタイトル2',
                'key'=>'title',
                'value'=>$this->conf['wrap_title'][$order['WrappingModel2']['title']],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング名2',
                'key'=>'name',
                'value'=>$order['WrappingModel2']['name'],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング料金2',
                'key'=>'price',
                'value'=>$order['WrappingModel2']['price'],
                'require'=>'0',
            ];
            $data[] = [
                'name'=>'ラッピング税込別2',
                'key'=>'includeTaxFlag',
                'value'=>$this->conf['wrap_include_tax'][$order['WrappingModel2']['includeTaxFlag']],
                'require'=>'0',
            ];
        }

        return $data;
    }
}
