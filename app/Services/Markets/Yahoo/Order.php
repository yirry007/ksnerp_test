<?php

namespace App\Services\Markets\Yahoo;

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
        $order = $this->orderView($orderId);

        if (!$order['code']) {//订单数据转为标准数据格式
            $order['result'] = $this->dataMap($order['result'][0]);
        }

        return $order;
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
            !array_key_exists('IsSeen', $updatingInfo)
            && !array_key_exists('PayStatus', $updatingInfo)
            && !array_key_exists('ShipStatus', $updatingInfo)
            && !array_key_exists('verifyShippingData', $updatingInfo)
        ) {
            $data['code'] = '';
            $data['message'] = 'no need updating in market';
            return $data;
        }

        /** 确认新规订单 */
        if (array_key_exists('IsSeen', $updatingInfo)) {
            $res = $this->isSeen($orderId);
            $data['code'] = $res['code'];
            $data['message'] = $res['message'];
        }

        /** 入金确认 */
        if (array_key_exists('PayStatus', $updatingInfo)) {
            $res = $this->updatePayStatus($orderId);
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

        /** 已发货 */
        if (array_key_exists('ShipStatus', $updatingInfo)) {
            $res = $this->updateShipStatus($orderId, $infos);
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

        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderStatusChange';

        $data = [
            'Target'=>[
                'OrderId'=>$orderId,
                'IsPointFix'=>'true'
            ],
            'Order'=>[
                'OrderStatus'=>4
            ],
            'SellerId'=>$this->shop->shop_id
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
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
        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderList';

        $startdate = $params['startdate'] ?? date('Y-m-d');
        $enddate = $params['enddate'] ?? date('Y-m-d');

        $condition = array();
        $condition['OrderTimeFrom'] = str_replace('-', '', $startdate) . '000000';
        $condition['OrderTimeTo'] = str_replace('-', '', $enddate) . '235959';

        $data = [
            'Search'=>[
                'Result'=>'2000',
                'Sort'=>'-order_time',
                'Condition'=>$condition,
                'Field'=>'OrderId',
            ],
            'SellerId'=>$this->shop->shop_id
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
        $orders = Responser::format($result);

        if ($orders['code'] || !count($orders['result'])) {
            return $orders;
        }

        $orderIdArr = array();
        foreach ($orders['result'] as $v) {
            $orderIdArr[] = $v['OrderId'];
        }
        $orderIds = implode(',', $orderIdArr);

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
        $orders = array();

        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderInfo';

        $orderIdArr = explode(',', $orderIds);
        foreach ($orderIdArr as $v) {
            $data = [
                'Target'=>[
                    'OrderId'=>$v,
                    'Field'=>implode(',', $this->conf['fields']),
                ],
                'SellerId'=>$this->shop->shop_id
            ];

            $response = Requester::send($this->shop, $url, $this->method, $data);
            $result = xmlToArray($response);
            $order = Responser::format($result);

            /** 授权失败直接返回 */
            if ($order['code'] == 'E0000') {
                return $order;
            }

            if ($order['code']) continue;

            $orders[] = $order['result'];
        }

        if (count($orders)) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $orders;
        } else {
            $return['code'] = 'E5009';
            $return['message'] = 'Request failed';
        }

        return $return;
    }

    /**
     * 订单更新为已读状态（管理员确认）
     * @param $orderId
     * @return array
     */
    private function isSeen($orderId)
    {
        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderChange';

        $data = [
            'Target'=>[
                'OrderId'=>$orderId,
            ],
            'Order'=>[
                'IsSeen'=>'true'
            ],
            'SellerId'=>$this->shop->shop_id
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
        return Responser::format($result);
    }

    /**
     * 更新支付状态
     * @param $orderId
     * @return array
     */
    private function updatePayStatus($orderId)
    {
        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderPayStatusChange';

        $data = [
            'Target'=>[
                'OrderId'=>$orderId,
                'IsPointFix'=>'true'
            ],
            'Order'=>[
                'Pay'=>[
                    'PayStatus'=>1
                ]
            ],
            'SellerId'=>$this->shop->shop_id
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
        return Responser::format($result);
    }

    /**
     * 更新状态改为已发货
     * @param $orderId
     * @param array $infos
     * @return array
     */
    private function updateShipStatus($orderId, $infos=[])
    {
        $url = Api::YAHOO_API_PREFIX . '/ShoppingWebService/V1/orderShipStatusChange';

        $updateData = array();
        $updateData['ShipStatus'] = 3;

        $shippingCompanies = array_flip($this->conf['shipping_companies']);
        $updateData['ShipCompanyCode'] = $shippingCompanies[$infos['shipping_company']];
        $updateData['ShipInvoiceNumber1'] = $infos['invoice_number_1'];
        $updateData['ShipInvoiceNumber2'] = $infos['invoice_number_2'];

        if (array_key_exists('shipping_date', $infos)) {
            $updateData['ShipDate'] = $infos['shipping_date'] ? str_replace('-', '', $infos['shipping_date']) : date('Ymd');
        }

        $data = [
            'Target'=>[
                'OrderId'=>$orderId,
                'IsPointFix'=>'true',
            ],
            'Order'=>[
                'Ship'=>$updateData
            ],
            'SellerId'=>$this->shop->shop_id
        ];

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
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

        $_data['market'] = 'Yahoo';
        $_data['device'] = $this->conf['devices'][$data['DeviceType']];
        $_data['shop_id'] = $this->shop->shop_id;
        $_data['order_id'] = $data['OrderId'];
        $_data['order_time'] = Tool::formatDatetime($data['OrderTime']);
        $_data['order_status'] = Tool::orderStatusMap($data['OrderStatus'], $data['IsSeen'], $data['Pay']['PayStatus'], $data['Ship']['ShipStatus']);
        $_data['pay_method'] = $this->conf['pay_methods'][$data['Pay']['PayMethod']];
        $_data['pay_status'] = $data['Pay']['PayStatus'] ? 1 : 0;
        $_data['pay_time'] = $data['Pay']['PayDate'] ? $data['Pay']['PayDate'] . ' 00:00:00' : null;
        $_data['currency'] = 'JPY';
        $_data['shipping_time'] = $data['Ship']['ShipDate'] ? $data['Ship']['ShipDate'] . ' 00:00:00' : null;
        $_data['shipping_company'] = $this->conf['shipping_companies'][$data['Ship']['ShipCompanyCode']];
        $_data['invoice_number_1'] = $data['Ship']['ShipInvoiceNumber1'] ?: '';
        $_data['invoice_number_2'] = $data['Ship']['ShipInvoiceNumber2'] ?: '';
        $_data['remark'] = $data['BuyerComments'] ?: '';
        $_data['total_price'] = $data['Detail']['TotalPrice'];
        $_data['zipcode'] = $data['Ship']['ShipZipCode'];
        $_data['country'] = 'Japan';
        $_data['prefecture'] = $data['Ship']['ShipPrefecture'];
        $_data['city'] = $data['Ship']['ShipCity'];
        $_data['address_1'] = $data['Ship']['ShipAddress1'];
        $_data['address_2'] = $data['Ship']['ShipAddress2'] ?: '';
        $_data['full_address'] = $_data['prefecture'] . $_data['city'] . $_data['address_1'] .$_data['address_2'];
        $_data['name_1'] = $data['Ship']['ShipLastName'] . ' ' . $data['Ship']['ShipFirstName'];
        $_data['name_2'] = $data['Ship']['ShipLastNameKana'] . ' ' . $data['Ship']['ShipFirstNameKana'];
        $_data['phone_1'] = $data['Ship']['ShipPhoneNumber'];
        $_data['phone_2'] = '';
        $_data['email'] = $data['Pay']['BillMailAddress'];
        $_data['items'] = $this->setItemData($data['Item']);
        $_data['infos'] = $this->setInfos($data);

        return $_data;
    }

    /**
     * 格式化商品数据
     * @param $items
     * @return array
     */
    private function setItemData($items)
    {
        $data = array();

        $_items = array();
        if (array_key_exists('ItemId', $items)) {
            /** 单个商品以一维数组表现，为了统一格式转换为二维数组 */
            $_items[] = $items;
        } else {
            $_items = $items;
        }

        foreach ($_items as $k=>$v) {
            $item = array();
            $item['item_id'] = $v['ItemId'];
            $item['item_sub_id'] = $v['SubCode'] ?: $v['LineId'];
            $item['item_management_id'] = $v['ProductId'] ?: $v['ItemId'];
            $item['item_name'] = $v['Title'];
            $item['item_number'] = $v['ProductId'] ?: '';
            $item['item_price'] = $v['UnitPrice'];
            $item['quantity'] = $v['Quantity'];
            $item['item_url'] = 'https://store.shopping.yahoo.co.jp/' . $this->shop->shop_id . '/' . $v['ItemId'];

            $itemOption = $v['ItemOption'] ?: null;

            $option = array();
            if ($itemOption) {
                $option = Tool::formatOption($itemOption);
            }
            $item['options'] = $option;
            $item['item_options'] = $item['options']['data'] ?? [];

            /** 添加商品的其他信息 */
            $infos = array();
            $infos['SubCodeOption'] = $v['SubCodeOption'];
            $item['infos'] = $infos;

            $data[$k] = $item;
        }

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
            'name'=>'Version',
            'key'=>'Version',
            'value'=>$order['Version'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ParentOrderId.'),
            'key'=>'ParentOrderId',
            'value'=>$order['ParentOrderId'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.IsSeen.'),
            'key'=>'IsSeen',
            'value'=>$order['IsSeen'] == 'true' ? 1 : 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.LastUpdateTime.'),
            'key'=>'LastUpdateTime',
            'value'=>$order['LastUpdateTime'] ? Tool::formatDatetime($order['LastUpdateTime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.Suspect.'),
            'key'=>'Suspect',
            'value'=>$order['Suspect'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.PayType.'),
            'key'=>'Pay.PayType',
            'value'=>$order['Pay']['PayType'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.PayKind.'),
            'key'=>'Pay.PayKind',
            'value'=>$this->conf['pay_kinds'][$order['Pay']['PayKind']] ?? 'その他',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.PayMethodName.'),
            'key'=>'Pay.PayMethodName',
            'value'=>$order['Pay']['PayMethodName'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.PayNotes.'),
            'key'=>'Pay.PayNotes',
            'value'=>$order['Pay']['PayNotes'] ?: '',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryMethodId.'),
            'key'=>'Ship.ShipMethod',
            'value'=>$order['Ship']['ShipMethod'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryName.'),
            'key'=>'Ship.ShipMethodName',
            'value'=>$order['Ship']['ShipMethodName'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.hopeDate.'),
            'key'=>'Ship.ShipRequestDate',
            'value'=>$order['Ship']['ShipRequestDate'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.hopeDate.'),
            'key'=>'Ship.ShipRequestTime',
            'value'=>$order['Ship']['ShipRequestTime'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ShipNotes.'),
            'key'=>'Ship.ShipNotes',
            'value'=>$order['Ship']['ShipNotes'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ShipInvoiceNumberEmptyReason.'),
            'key'=>'Ship.ShipInvoiceNumberEmptyReason',
            'value'=>$order['Ship']['ShipInvoiceNumberEmptyReason'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ShipUrl.'),
            'key'=>'Ship.ShipUrl',
            'value'=>$order['Ship']['ShipUrl'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ArrivalDate.'),
            'key'=>'Ship.ArrivalDate',
            'value'=>$order['Ship']['ArrivalDate'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ShipRequestTimeZoneCode.'),
            'key'=>'Ship.ShipRequestTimeZoneCode',
            'value'=>$order['Ship']['ShipRequestTimeZoneCode'] ? $this->conf['SRTZC'][$order['Ship']['ShipRequestTimeZoneCode']] : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.paymentCharge.'),
            'key'=>'Detail.PayCharge',
            'value'=>$order['Detail']['PayCharge'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ShipCharge.'),
            'key'=>'Detail.ShipCharge',
            'value'=>$order['Detail']['ShipCharge'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.GiftWrapCharge.'),
            'key'=>'Detail.GiftWrapCharge',
            'value'=>$order['Detail']['GiftWrapCharge'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.discount.'),
            'key'=>'Detail.Discount',
            'value'=>$order['Detail']['Discount'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.usedPoint.'),
            'key'=>'Detail.UsePoint',
            'value'=>$order['Detail']['UsePoint'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.ReceiveSatelliteType.'),
            'key'=>'Ship.ReceiveSatelliteType',
            'value'=>$order['Ship']['ReceiveSatelliteType'] ? '1：日本郵便自宅外配送' : '通常配送',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.IsSubscription.'),
            'key'=>'Ship.IsSubscription',
            'value'=>$order['Ship']['IsSubscription'] == 'true' ? 1 : 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SubscriptionId.'),
            'key'=>'Ship.SubscriptionId',
            'value'=>$order['Ship']['SubscriptionId'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SubscriptionContinueCount.'),
            'key'=>'Ship.SubscriptionContinueCount',
            'value'=>$order['Ship']['SubscriptionContinueCount'] ?: null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SendConfirmTime.'),
            'key'=>'SendConfirmTime',
            'value'=>$order['SendConfirmTime'] ? Tool::formatDatetime($order['SendConfirmTime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SendPayTime.'),
            'key'=>'SendPayTime',
            'value'=>$order['SendPayTime'] ? Tool::formatDatetime($order['SendPayTime']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SellerComments.'),
            'key'=>'SellerComments',
            'value'=>$order['SellerComments'] ?: '',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.Comments.'),
            'key'=>'Notes',
            'value'=>$order['Notes'] ?: '',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.couponAllTotalPrice.'),
            'key'=>'TotalCouponDiscount',
            'value'=>$order['TotalCouponDiscount'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.TotalMallCouponDiscount.'),
            'key'=>'Detail.TotalMallCouponDiscount',
            'value'=>$order['Detail']['TotalMallCouponDiscount'] ?: 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.Adjustments.'),
            'key'=>'Detail.Adjustments',
            'value'=>$order['Detail']['Adjustments'] ?: 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.pgRequestPrice.'),
            'key'=>'Detail.SettleAmount',
            'value'=>$order['Detail']['SettleAmount'],
            'require'=>'0',
        ];

        return $data;
    }
}
