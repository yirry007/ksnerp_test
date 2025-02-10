<?php

namespace App\Services\Markets\Qoo10;

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

        if ($orders['code'] || !array_key_exists('result', $orders)) {
            return $orders;
        }

        $result = array();
        foreach ($orders['result'] as $v) {
            array_unshift($result, $this->dataMap($v));
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
            !array_key_exists('confirmOrder', $updatingInfo)
            && !array_key_exists('confirmShipping', $updatingInfo)
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
        if (array_key_exists('confirmShipping', $updatingInfo)) {
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

        $url = Api::QOO10_API_PREFIX . '/Claim.SetCancelProcess';

        $data = array();
        $data['ContrNo'] = $orderId;
        $data['SellerMemo'] = '注文オプションの品切れでございます';

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
        $return = array();

        $shippingUrl = Api::QOO10_API_PREFIX . '/ShippingBasic.GetShippingInfo_v2';
        $claimUrl = Api::QOO10_API_PREFIX . '/ShippingBasic.GetClaimInfo_V3';

        /** 0:配送待ち(1),配送要請(2), 3:配送準備(3), 4:配送中(4), 5:配送完了(5) */
        $ShippingStats = ['0', '3', '4', '5'];
        /** 1:キャンセル要請，2:キャンセル中，3:キャンセル完了 */
        $ClaimStat = ['1', '2', '3'];

        $startdate = $params['startdate'] ?? date('Y-m-d');
        $enddate = $params['enddate'] ?? date('Y-m-d');

        $search = array();
        $search['search_Sdate'] = str_replace('-', '', $startdate);
        $search['search_Edate'] = str_replace('-', '', $enddate);

        $orderData = array();
        /** 获取正常状态的订单 */
        foreach ($ShippingStats as $v) {
            $response = Requester::send($this->shop, $shippingUrl, $this->method, array_merge($search, ['ShippingStat'=>$v]));
            $result = json_decode($response, true);
            $orders = Responser::format($result);

            if ($orders['code']) continue;

            $orderData = array_merge($orderData, $orders['result']);
        }
        /** 获取取消状态的订单 */
        foreach ($ClaimStat as $v) {
            $response = Requester::send($this->shop, $claimUrl, $this->method, array_merge($search, ['ClaimStat'=>$v]));
            $result = json_decode($response, true);
            $orders = Responser::format($result);

            if ($orders['code']) continue;

            $orderData = array_merge($orderData, $orders['result']);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $orderData;

        return $return;
    }

    /**
     * Api获取订单详细
     * @param string $orderId
     * @return array
     */
    private function orderView($orderId)
    {
        $url = Api::QOO10_API_PREFIX . '/ShippingBasic.GetShippingAndClaimInfoByOrderNo_V2';

        $search = array();
        $search['OrderNo'] = $orderId;

        $response = Requester::send($this->shop, $url, $this->method, $search);
        $result = json_decode($response, true);
        return Responser::format($result);
    }

    /**
     * 卖家确认订单
     * @param $orderId
     * @return array
     */
    private function confirmOrder($orderId)
    {
        $url = Api::QOO10_API_PREFIX . '/ShippingBasic.SetSellerCheckYN_V2';

        $data = array();
        $data['OrderNo'] = $orderId;
        $data['EstShipDt'] = date('Ymd', time() + $this->conf['default_delay_date'] * 86400);//预计发货日
        $data['DelayType'] = $this->conf['default_delay_type'];;//发货延迟理由

        $response = Requester::send($this->shop, $url, $this->method, $data);
        $result = json_decode($response, true);
        return Responser::format($result);
    }

    /**
     * 更新订单配送信息
     * @param $orderId
     * @param $infos
     * @return array
     */
    private function updateOrderShipping($orderId, $infos)
    {
        $url = Api::QOO10_API_PREFIX . '/ShippingBasic.SetSendingInfo';

        $data = array();
        $data['OrderNo'] = $orderId;
        $data['ShippingCorp'] = $infos['shipping_company'];
        $data['TrackingNo'] = $infos['invoice_number_1'] ?? $infos['invoice_number_2'];

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

        if (array_key_exists('claimStatus', $data) && $data['claimStatus']) {
            $type = 'claim';
            $status = $data['claimStatus'];
        } else {
            $type = 'shipping';
            $status = $data['shippingStatus'];
        }

        $_data['market'] = 'Qoo10';
        $_data['device'] = '';
        $_data['shop_id'] = $this->shop->shop_id;
        $_data['order_id'] = $data['orderNo'];
        $_data['order_status'] = Tool::orderStatusMap($status, $type);
        $_data['order_time'] = $data['orderDate'];
        $_data['pay_method'] = $data['PaymentMethod'] ?? '';
        $_data['pay_status'] = $data['PaymentDate'] ? 1 : 0;
        $_data['pay_time'] = $data['PaymentDate'];
        $_data['currency'] = 'JPY';
        $_data['shipping_time'] = $data['ShippingDate'] ?? null;
        $_data['shipping_company'] = $data['deliveryCompany'] ?? '';
        $_data['invoice_number_1'] = $data['TrackingNo'] ?? '';
        $_data['invoice_number_2'] = '';
        $_data['remark'] = $data['ShippingMsg'] ?? '';
        $_data['total_price'] = $data['total'] ?? 0;
        $_data['zipcode'] = $data['zipCode'];
        $_data['country'] = 'Japan';
        $_data['prefecture'] = '';
        $_data['city'] = '';
        $_data['address_1'] = $data['shippingAddr'] ?? '';
        $_data['address_2'] = '';
        $_data['full_address'] = $_data['address_1'];
        $_data['name_1'] = $data['receiver'] ?? '';
        $_data['name_2'] = $data['receiver_gata'] ?? '';
        $_data['phone_1'] = $data['receiverMobile'];
        $_data['phone_2'] = '';
        $_data['email'] = $data['buyerEmail'] ?? '';
        $_data['items'] = $this->setItemData($data);
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
        $data[0]['item_id'] = $items['itemCode'];
        $data[0]['item_sub_id'] = '';
        $data[0]['item_management_id'] = $items['sellerItemCode'];
        $data[0]['item_name'] = $items['itemTitle'];
        $data[0]['item_number'] = '';
        $data[0]['item_price'] = $items['orderPrice'] ?? 0;
        $data[0]['quantity'] = $items['orderQty'];
        $data[0]['item_url'] = 'https://www.qoo10.jp/item/g/' . $items['itemCode'];
        $data[0]['options'] = [
            'id'=>'0',
            'manage_id'=>'',
            'data'=>Tool::formatOption($items['option'] ?? ''),
            'price'=>0,
        ];
        $data[0]['item_options'] = $data[0]['options']['data'];

        /** 添加商品的其他信息 */
        $infos = array();
        $data[0]['infos'] = $infos;

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
            'name'=>__('lang.packNo.'),
            'key'=>'packNo',
            'value'=>$order['packNo'],
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.hopeDate.'),
            'key'=>'hopeDate',
            'value'=>$order['hopeDate'] ?? null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.PaymentNation.'),
            'key'=>'PaymentNation',
            'value'=>$order['PaymentNation'] ?? null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.DeliveryCompany.'),
            'key'=>'DeliveryCompany',
            'value'=>$order['DeliveryCompany'] ?? null,
            'require'=>'1',
        ];

        $data[] = [
            'name'=>__('lang.shippingStatus.'),
            'key'=>'shippingStatus',
            'value'=>$order['shippingStatus'] ?? null,
            'require'=>'1',
        ];

        $data[] = [
            'name'=>__('lang.PackingNo.'),
            'key'=>'PackingNo',
            'value'=>$order['PackingNo'] ?? null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.SellerDeliveryNo.'),
            'key'=>'SellerDeliveryNo',
            'value'=>$order['SellerDeliveryNo'] ?? null,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>__('lang.discount.'),
            'key'=>'discount',
            'value'=>$order['discount'] ?? 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'着払い決済金額',
            'key'=>'cod_price',
            'value'=>$order['cod_price'] ?? 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'総供給原価',
            'key'=>'SettlePrice',
            'value'=>$order['SettlePrice'] ?? 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'販売者負担カート割引',
            'key'=>'Cart_Discount_Seller',
            'value'=>$order['Cart_Discount_Seller'] ?? 0,
            'require'=>'0',
        ];

        $data[] = [
            'name'=>'Qoo10負担カート割引',
            'key'=>'Cart_Discount_Qoo10',
            'value'=>$order['Cart_Discount_Qoo10'] ?? 0,
            'require'=>'0',
        ];

        return $data;
    }
}
