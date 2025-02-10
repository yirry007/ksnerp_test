<?php

namespace App\Services\Markets\Wowma;

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
        $this->method = 'GET';
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

        //请求的数据有误，或没有可处理结果直接返回
        if ($orders['code'] || !array_key_exists('result', $orders)) {
            return $orders;
        }

        $result = array();
        if (array_key_exists('orderId', $orders['result'])) {
            /** 只有一个订单时，订单列表不会以数组形式展示，需要转为数组 */
            $orders['result'] = [$orders['result']];
        }
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
            $order['result'] = $this->dataMap($order['result']);
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

        $beforeShipPayment = '1';
        foreach ($infos['infos'] as $v) {
            if ($v['key'] == 'beforeShipPayment') {
                $beforeShipPayment = $v['value'];
                break;
            }
        }

        //待更新的状态
        $updatingInfo = Tool::orderStatusUpdatingInfo($orderStatus, $beforeShipPayment);

        $data['result']['current_status'] = $updatingInfo['currentStatus'];
        $data['result']['next_status'] = $updatingInfo['nextStatus'];

        /** 没有可更新项目，直接返回错误 */
        if (
            !array_key_exists('orderStatus', $updatingInfo)
            && !array_key_exists('updateOrderShipping', $updatingInfo)
            && !array_key_exists('verifyShippingData', $updatingInfo)
        ) {
            $data['code'] = '';
            $data['message'] = 'no need updating in market';
            return $data;
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
            $res = $this->updateOrderInfo($orderId, $infos);
            $data['code'] = $res['code'];
            $data['message'] = $res['message'];
        }

        /** 订单状态变更，卖家确认订单|订单状态改为完了 */
        if (array_key_exists('orderStatus', $updatingInfo)) {
            $res = $this->updateOrderStatus($orderId, $updatingInfo['orderStatus']);
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

        $url = Api::WOWMA_API_PREFIX . '/cancelTradeProc';
        $this->setMethod('POST');

        $data = array();
        $data['shopId'] = $this->shop->shop_id;
        $data['orderId'] = $orderId;
        $data['cancelReason'] = '9';
        $data['cancelComment'] = '在庫切れ　欠品';

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
        $url = Api::WOWMA_API_PREFIX . '/searchTradeInfoListProc';

        $startdate = $params['startdate'] ?? date('Y-m-d');
        $enddate = $params['enddate'] ?? date('Y-m-d');

        $search = array();
        $search['shopId'] = $this->shop->shop_id;
        $search['totalCount'] = 1000;
        $search['startDate'] = str_replace('-', '', $startdate);
        $search['endDate'] = str_replace('-', '', $enddate);

        $response = Requester::send($this->shop, $url, $this->method, $search);
        $result = xmlToArray($response);
        return Responser::format($result);
    }

    /**
     * Api获取订单详细
     * @param string $orderId
     * @return array
     */
    private function orderView($orderId)
    {
        $url = Api::WOWMA_API_PREFIX . '/searchTradeInfoProc';

        $search = array();
        $search['shopId'] = $this->shop->shop_id;
        $search['orderId'] = $orderId;

        $response = Requester::send($this->shop, $url, $this->method, $search);
        $result = xmlToArray($response);
        return Responser::format($result);
    }

    /**
     * 更新订单状态
     * @param $orderId
     * @param $orderStatus
     * @return array
     */
    private function updateOrderStatus($orderId, $orderStatus)
    {
        $url = Api::WOWMA_API_PREFIX . '/updateTradeStsProc';
        $this->setMethod('POST');

        $data = array();
        $data['shopId'] = $this->shop->shop_id;
        $data['orderId'] = $orderId;
        $data['orderStatus'] = $orderStatus;

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = xmlToArray($response);
        return Responser::format($result);
    }

    /**
     * 更新订单信息
     * @param $orderId
     * @param array $infos
     * @return array|string[]
     */
    private function updateOrderInfo($orderId, $infos=[])
    {
        $url = Api::WOWMA_API_PREFIX . '/updateTradeInfoProc';
        $this->setMethod('POST');

        $data = array();

        $shippingCompanies = array_flip($this->conf['shipping_companies']);
        $data['shippingCarrier'] = $shippingCompanies[$infos['shipping_company']];
        $data['shippingNumber'] = $infos['invoice_number_1'] ?? $infos['invoice_number_2'];
        $data['shippingDate'] = $infos['shipping_date'] ?? date('Y-m-d');
        $data['shopId'] = $this->shop->shop_id;
        $data['orderId'] = $orderId;

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
        $shippingNumber = array_key_exists('shippingNumber', $data) && $data['shippingNumber'] ? $data['shippingNumber'] : '';

        $_data['market'] = 'Wowma';
        $_data['device'] = $data['siteAndDevice'];
        $_data['shop_id'] = $this->shop->shop_id;
        $_data['order_id'] = $data['orderId'];
        $_data['order_status'] = Tool::orderStatusMap($data['orderStatus'], $shippingNumber);
        $_data['order_time'] = Tool::formatDatetime($data['orderDate']);
        $_data['pay_method'] = $data['settlementName'];
        $_data['pay_status'] = $data['paymentStatus'] == 'Y' ? 1 : 0;
        $_data['pay_time'] = array_key_exists('paymentDate', $data) ? Tool::formatDatetime($data['paymentDate']) : null;
        $_data['currency'] = 'JPY';
        $_data['shipping_time'] = array_key_exists('shipDate', $data) ? Tool::formatDatetime($data['shipDate']) : null;
        $_data['shipping_company'] = array_key_exists('shippingCarrier', $data) && $data['shippingCarrier'] ? $this->conf['shipping_companies'][$data['shippingCarrier']] : '';
        $_data['invoice_number_1'] = $shippingNumber ?: '';
        $_data['invoice_number_2'] = '';
        $_data['remark'] = array_key_exists('userComment', $data) ? (is_array($data['userComment']) ? json_encode($data['userComment'], JSON_UNESCAPED_UNICODE) : $data['userComment']) : '';
        $_data['total_price'] = $data['requestPrice'];
        $_data['zipcode'] = $data['senderZipCode'];
        $_data['country'] = 'Japan';
        $_data['prefecture'] = '';
        $_data['city'] = '';
        $_data['address_1'] = $data['senderAddress'];
        $_data['address_2'] = $data['ordererAddress'];
        $_data['full_address'] = $_data['address_1'];
        $_data['name_1'] = $data['senderName'];
        $_data['name_2'] = $data['senderKana'];
        $_data['phone_1'] = $data['senderPhoneNumber1'];
        $_data['phone_2'] = $data['ordererPhoneNumber1'];
        $_data['email'] = $data['mailAddress'];
        $_data['items'] = $this->setItemData($data['detail']);
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

        if (array_key_exists('itemCode', $items)) {
            $_items[] = $items;
        } else {
            $_items = $items;
        }

        foreach ($_items as $k=>$v) {
            $item = array();
            $item['item_id'] = $v['itemCode'];
            $item['item_sub_id'] = $v['orderDetailId'];
            $item['item_management_id'] = array_key_exists('itemManagementId', $v) ? (is_array($v['itemManagementId']) ? implode(',', $v['itemManagementId']) : $v['itemManagementId']) : '';
            $item['item_name'] = $v['itemName'];
            $item['item_number'] = $v['lotnumber'];
            $item['item_price'] = $v['itemPrice'];
            $item['quantity'] = $v['unit'];
            $item['item_url'] = 'https://wowma.jp/item/' . $v['lotnumber'];
            $item['options'] = [
                'id'=>'0',
                'manage_id'=>'',
                'data'=>Tool::formatOption($v['itemOption'] ?? null),
                'price'=>$v['itemOptionPrice'],
            ];
            $item['item_options'] = $item['options']['data'];

            /** 添加商品的其他信息 */
            $infos = array();
            if (array_key_exists('giftWrappingType', $v) && $v['giftWrappingType']) {
                $infos[$k]['name'] = __('lang.giftWrappingType.');
                $infos[$k]['key'] = 'giftWrappingType';
                $infos[$k]['value'] = $v['giftWrappingType'];
            }
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
            'name'=>__('lang.sellMethodSegment.'),
            'key'=>'sellMethodSegment',
            'value'=>$order['sellMethodSegment'] == '1' ? '通常販売' : '予約販売',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.crossBorderEcTradeKbn.'),
            'key'=>'crossBorderEcTradeKbn',
            'value'=>$order['crossBorderEcTradeKbn'] == 'R' ? 'Ruten代理購入' : '通常購入',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.nickname.'),
            'key'=>'nickname',
            'value'=>$order['nickname'] ?? '',
            'require'=>'0',
        ];


        $data[] = [
            'name'=>__('lang.beforeShipPayment.'),
            'key'=>'beforeShipPayment',
            'value'=>in_array($order['settlementName'], $this->conf['before_ship_payment']) ? '1' : '0',
            'require'=>'1',
        ];

        $paymentPoint = in_array($order['settlementName'], $this->conf['before_ship_payment']) ? '(配送前入金)' : '(配送后入金)';
        $data[] = [
            'name'=>__('lang.settlementMethod.'),
            'key'=>'settlementName',
            'value'=>$order['settlementName'] . $paymentPoint,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.shipStatus.'),
            'key'=>'shipStatus',
            'value'=>$order['shipStatus'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'特記事項',
            'key'=>'tradeRemarks',
            'value'=>$order['tradeRemarks'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'メモ',
            'key'=>'memo',
            'value'=>$order['memo'] ?? '',
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.email sended.'),
            'key'=>'contactStatus',
            'value'=>$order['contactStatus'] ? __('lang.sended.') : __('lang.not sended.'),
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.email send time.'),
            'key'=>'contactDate',
            'value'=>array_key_exists('contactDate', $order) && $order['contactDate'] ? Tool::formatDatetime($order['contactDate']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.couponAllTotalPrice.'),
            'key'=>'couponTotalPrice',
            'value'=>$order['couponTotalPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.usedPoint.'),
            'key'=>'usePoint',
            'value'=>$order['usePoint'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.requestPrice.'),
            'key'=>'requestPrice',
            'value'=>$order['requestPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'手数料',
            'key'=>'chargePrice',
            'value'=>$order['chargePrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'送料',
            'key'=>'postagePrice',
            'value'=>$order['postagePrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'ギフト手数料(合計)',
            'key'=>'totalGiftWrappingPrice',
            'value'=>$order['totalGiftWrappingPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額小計(10%)',
            'key'=>'totalPriceNormalTax',
            'value'=>$order['totalPriceNormalTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額小計(8%)',
            'key'=>'totalPriceReducedTax',
            'value'=>$order['totalPriceReducedTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額小計(0%)',
            'key'=>'totalPriceNoTax',
            'value'=>$order['totalPriceNoTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'オプション手数料',
            'key'=>'totalItemOptionPrice',
            'value'=>$order['totalItemOptionPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'auスマートパスプレミアム特典プログラム適用金額',
            'key'=>'premiumIssuePrice',
            'value'=>$order['premiumIssuePrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額(10%)',
            'key'=>'requestPriceNormalTax',
            'value'=>$order['requestPriceNormalTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額(8%)',
            'key'=>'requestPriceReducedTax',
            'value'=>$order['requestPriceReducedTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'請求金額(0%)',
            'key'=>'requestPriceNoTax',
            'value'=>$order['requestPriceNoTax'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'auポイント・Pontaポイント（au PAY マーケット限定含む）利用額',
            'key'=>'useAuPointPrice',
            'value'=>$order['useAuPointPrice'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'auポイント・Pontaポイント（au PAY マーケット限定含む）利用数',
            'key'=>'useAuPoint',
            'value'=>$order['useAuPoint'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.settleStatus.'),
            'key'=>'settleStatus',
            'value'=>array_key_exists('settleStatus', $order) ? $order['settleStatus'] : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.authoriTimelimitDate.'),
            'key'=>'authoriTimelimitDate',
            'value'=>array_key_exists('authoriTimelimitDate', $order) ? str_replace('/', '-', $order['authoriTimelimitDate']) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.pgResult.'),
            'key'=>'pgResult',
            'value'=>array_key_exists('pgResult', $order) ? ($order['pgResult'] == '0' ? 'SUCCESS' : ($order['pgResult'] == '1' ? 'FAILED' : 'ERROR')) : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.pgOrderId.'),
            'key'=>'pgOrderId',
            'value'=>array_key_exists('pgOrderId', $order) ? $order['pgOrderId'] : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.pgRequestPrice.'),
            'key'=>'pgRequestPrice',
            'value'=>array_key_exists('pgRequestPrice', $order) ? $order['pgRequestPrice'] : null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryName.'),
            'key'=>'deliveryName',
            'value'=>$order['deliveryName'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.deliveryMethodId.'),
            'key'=>'deliveryMethodId',
            'value'=>$order['deliveryMethodId'],
            'require'=>'0',
        ];

        return $data;
    }
}
