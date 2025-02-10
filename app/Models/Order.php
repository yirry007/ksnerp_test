<?php

namespace App\Models;

use App\Services\Agent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    const DAY_AGO_MAX = 30;
    const ORDER_COUNT_LIMIT = 40;
    const SEARCH_DAY_MAX = 5;
    private $liveOrderCount = 0;//订单抓取数量，数量达N个以后不再模型中获取数据，任务交给后台程序
    private $currentSearchDayAgo = 0;//N天前的订单

    /**
     * 订单列表中添加订单商品
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function orderItems()
    {
        return $this->hasMany('App\Models\OrderItem');
    }

    /**
     * 订单列表中添加邮件发送日志
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function emailSendLogs()
    {
        return $this->hasMany('App\Models\EmailSendLog');
    }

    /**
     * 1.请求 api 获取一定数量的订单数据
     * 2.更新数据库中的订单数据
     * 3.创建后台进程，继续请求 api 获取订单数据，并更新数据库
     * @param $shops
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getLiveOrders($shops)
    {
        foreach ($shops as $k=>$v) {
            $res = self::getOrdersFromApi($v, $this->currentSearchDayAgo);

            if ($res['code']) {
                /** 订单 api 请求失败则列表中删除该店铺，跳过该循环 */
                unset($shops[$k]);
                continue;
            }

            if (!array_key_exists('result', $res) || !$res['result'] || !count($res['result'])) continue;

            /** 有订单数据则更新数据库 */
            self::updateOrders($v, $res['result']);
            $this->liveOrderCount += count($res['result']);
        }

        if (
            $this->liveOrderCount >= self::ORDER_COUNT_LIMIT //订单查询数量足够
            || !count($shops) //或没有可查询的店铺
            || $this->currentSearchDayAgo >= self::SEARCH_DAY_MAX //或查询天数已达设置的最大值
        ) {
            /** 开启 php 后台进程，继续抓取订单数据 */
            self::daemonProcessUpdateOrder($this->currentSearchDayAgo);

            return true;
        } else {
            /** 订单抓取数量不够，则修改订单日期（N天前）后，继续请求 api 抓取数据 */
            $this->currentSearchDayAgo++;
            return $this->getLiveOrders($shops);
        }
    }

    /**
     * 请求 api 获取订单数据
     * @param Object $shop 数据库查询的 shop 数据对象
     * @param int $dayAgo
     * @return array|bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function getOrdersFromApi($shop, $dayAgo = 0)
    {
        if ($dayAgo > self::DAY_AGO_MAX) {
            return [
                'code'=>'E200031',
                'message'=>'order time over 30 days'
            ];
        }

        /** 设置订单日期 */
        $orderDate = date('Y-m-d', strtotime("-$dayAgo day"));
        $param = [
            'startdate'=>$orderDate,
            'enddate'=>$orderDate
        ];

        return Agent::Markets($shop)->setClass('Order')->getOrders($param);
    }

    /**
     * 更新数据库中的订单，返回更新数量
     * @param $shop
     * @param $orders
     * @return int
     */
    public static function updateOrders($shop, $orders)
    {
        $updatedCount = 0;//订单更新数量（更新+新增）

        foreach ($orders as $order) {
            /** 处理订单所属的 shop_id（字段不一致） */
            $order['sh_shop_id'] = $order['shop_id'];//设置平台店铺id
            $order['user_id'] = $shop->user_id;//店铺所有者 user_id
            $order['shop_id'] = $shop->id;//店铺在数据库中的主键id

            /** 设置订单中包含的商品种类数量 */
            $order['item_count'] = count($order['items']);

            /** info 数据转 json 字符串 */
            $order['infos'] = json_encode($order['infos'], JSON_UNESCAPED_UNICODE);

            /** 订单商品数据保存到变量后删除 */
            $items = $order['items'];
            unset($order['items']);

            if ($order['market'] == 'Rakuten') {
                /** 乐天的 package 数据转到 ext1 字段，并删除 package */
                $order['ext1'] = json_encode($order['packages'], JSON_UNESCAPED_UNICODE);
                unset($order['packages']);
            }

            $exists = self::where('order_id', $order['order_id'])->first();

            if ($exists) {
                $orderId = $exists->id;
                /** 订单状态更新[api和数据库数据比较后，以数字大的为基准] */
                $order['order_status'] = max($exists->order_status, $order['order_status']);

                if (!$order['shipping_company'] || $exists->shipping_company) {
                    unset($order['shipping_company']);
                }
                if (!$order['invoice_number_1']) {
                    unset($order['invoice_number_1']);
                }
                if (!$order['invoice_number_2']) {
                    unset($order['invoice_number_2']);
                }

                $affectedRow = self::where('id', $orderId)->update($order);

                /** 影响行数添加到订单更新数量 */
                $updatedCount += $affectedRow;
            } else {
                try {
                    $res = self::create($order);
                } catch (\Exception $e) {
                    Log::info($e);
                    continue;
                }

                if (!$res) continue;

                $updatedCount++;//订单更新数量+1
                $orderId = $res->id;//设置订单id
            }

            /** 开始更新订单商品 */
            foreach ($items as $item) {
                $item['sh_shop_id'] = $order['sh_shop_id'];
                $item['shop_id'] = $order['shop_id'];
                $item['order_id'] = $orderId;
                $item['options'] = json_encode($item['options'], JSON_UNESCAPED_UNICODE);
                $item['item_options'] = $item['item_options'] ? json_encode($item['item_options'], JSON_UNESCAPED_UNICODE) : '{}';
                $item['infos'] = json_encode($item['infos'], JSON_UNESCAPED_UNICODE);

                $itemExists = OrderItem::where(['order_id'=>$orderId, 'item_id'=>$item['item_id'], 'item_sub_id'=>$item['item_sub_id']])->first();

                if ($itemExists) {
                    OrderItem::where(['order_id'=>$orderId, 'item_id'=>$item['item_id'], 'item_sub_id'=>$item['item_sub_id']])->update($item);
                } else {
                    $item['supply_opt'] = 'INIT_' . md5($item['item_id']);
                    OrderItem::create($item);
                }
            }
        }

        return $updatedCount;
    }

    /**
     * 后台执行抓取订单并更新
     * @param int $dayAgo
     */
    public static function daemonProcessUpdateOrder($dayAgo = 0)
    {
        /** php 命令 */
        $php = env('PHP_PATH', 'php');

        /** artisan所在路径 */
        $path = base_path('artisan');

        /** 管理员用户id */
        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 组合 artisan 命令（传到后台运行） */
        $command = "$php $path order:update $user_id $dayAgo";

        /** 命令传给后台进程 */
        execInBackground($command);
    }

    /**
     * 请求 api 更新平台的订单状态，同时更新数据库中的订单状态
     * @param $orderId
     * @param $orderStatus
     * @param array $shippingData
     * @param array $shopEmail
     * @return array
     */
    public static function updateOrderStatusInMarket($orderId, $orderStatus, $shippingData=[], $shopEmail=[])
    {
        $return = array();

        $order = self::with('orderItems')->where('order_id', $orderId)->first();

        if (!$order) {
            $return['code'] = 'E301031';
            $return['message'] = __('lang.Invalid order ID.');
            return $return;
        }
        if ($order->order_status > $orderStatus) {
            $return['code'] = 'E200231';
            $return['message'] = __('lang.Order has been updated.');
            return $return;
        }

        $shop = Shop::find($order->shop_id);

        if (!$shop) {
            $return['code'] = 'E301032';
            $return['message'] = __('lang.Invalid shop info.');
            return $return;
        }

        /** 添加订单的附加信息（特定平台根据这个有特定的操作） */
        $shippingData['infos'] = json_decode($order->infos, true);

        /** 请求 api，更新平台的订单状态 */
//        $marketResult = Agent::Markets($shop)->setClass('Order')->updateOrder($orderId, $orderStatus, $shippingData);
        $marketResult = [
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>['next_status'=>$orderStatus+1]
        ];

        if ($marketResult['code']) {
            $return['code'] = $marketResult['code'];
            $return['message'] = $marketResult['message'];
            return $return;
        }

        /** 发送订单邮件 */
        if (
            ($orderStatus == 0 || $orderStatus == 4)
            && (array_key_exists($shop->shop_id, $shopEmail) && $shopEmail[$shop->shop_id])
        ) {
//            self::sendOrderEmail($shop, $order, $shopEmail[$shop->shop_id]);
        }

        /** 更新本地数据库的订单状态 */
        $updateData = array();
        $updateData['order_status'] = $marketResult['result']['next_status'];

        foreach ($shippingData as $k=>$v) {
            if ($k == 'shipping_company' && $v)
                $updateData['shipping_company'] = $v;
            if ($k == 'invoice_number_1' && $v)
                $updateData['invoice_number_1'] = $v;
            if ($k == 'invoice_number_2' && $v)
                $updateData['invoice_number_2'] = $v;
        }

        $localResult = self::where('id', $order->id)->update($updateData);

        if ($localResult === false) {
            $return['code'] = 'E303031';
            $return['message'] = $marketResult['message'] . '|' . __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = $marketResult['message'];

            /** 统一更新没有设置国际物流信息的订单商品 */
            $orderItemUpdateData = array();
            $orderItemUpdateData['shipping_company'] = $updateData['shipping_company'] ?? '';
            $orderItemUpdateData['invoice_number'] = $updateData['invoice_number_1'] ?? $updateData['invoice_number_2'] ?? '';

            $orderItemUpdateIds = array();

            foreach ($order->orderItems as $v) {
                if (!$v->shipping_company || !$v->invoice_number) {
                    $orderItemUpdateIds[] = $v->id;
                }
            }

            OrderItem::whereIn('id', $orderItemUpdateIds)->update($orderItemUpdateData);
        }

        return $return;
    }

    /**
     * 发送邮件
     * @param $shop
     * @param $order
     * @param $templateId
     * @return boolean
     */
    public static function sendOrderEmail($shop, $order, $templateId)
    {
        $return = false;

        $hostPort = explode(':', $shop->smtp_address);
        /** 是否设置邮件服务器地址与端口 */
        if (count($hostPort) < 2) return $return;
        /** 是否设置邮件服务器用户名及密码 */
        if (!$shop->smtp_username || !$shop->smtp_password) return $return;
        /** 是否有收件人 */
        if (!$order->email) return $return;

        $template = EmailTemplate::find($templateId);
        /** 没有匹配的邮件模板 */
        if (!$template) return $return;

        $config = [
            // 服务器配置
            'encryption'=>strpos(strtolower($shop->smtp_address), 'rakuten') !== false ? '' : 'ssl',
            'host'=>$hostPort[0],
            'port'=>$hostPort[1],
            'username'=>$shop->smtp_username,
            'password'=>$shop->smtp_password,

            // 模板，收件人，发件人配置
            'to'=>$order->email,
            'receiver'=>$order->name_1,
            'from'=>$shop->smtp_email,
            'sender'=>$shop->shop_name,
            'subject'=>$template->title
        ];

        $emailContentHtml = $template->content;

        $emailContentHtml = str_replace([
            '{{items}}',
            '{{shipping}}',
            '{{name}}',
            '{{shop_name}}',
            '{{order_id}}',
            '{{order_time}}',
            '{{address}}',
            '{{zipcode}}',
            '{{phone}}',
            '{{total_price}}',
        ], [
            self::emailProductInfo($order->orderItems),
            self::emailShippingInfo($order),
            $order->name_1,
            $shop->shop_name,
            $order->order_id,
            $order->order_time,
            $order->address_1 . ' ' . $order->address_2,
            $order->zipcode,
            $order->phone_1,
            $order->total_price,
        ], $emailContentHtml);

        $html = "<div>{$emailContentHtml}</div>";

        $logData = [
            'user_id'=>$shop->user_id,
            'order_id'=>$order->id,
            'template_type'=>$template->type,
            'smtp_address'=>$shop->smtp_address,
            'smtp_username'=>$shop->smtp_username,
            'smtp_password'=>$shop->smtp_password,
            'smtp_email'=>$shop->smtp_email,
            'to'=>$order->email,
            'receiver'=>$order->name_1,
            'sender'=>$shop->shop_name,
            'subject'=>$template->title,
            'content'=>$html,
            'create_time'=>date('Y-m-d H:i:s'),
            'update_time'=>date('Y-m-d H:i:s'),
        ];

        $error = '';
        try {
            sendEmail($config, $html);
            $logData['is_success'] = '1';

            $return = true;
        } catch (\Exception $e) {
            $logData['is_success'] = '0';

            $error = json_encode([
                'file'=>$e->getFile(),
                'line'=>$e->getLine(),
                'message'=>$e->getMessage()
            ]);
        }

        $logData['errors'] = $error;

        /** 记录发送邮件日志 */
        DB::table('email_send_logs')->insert($logData);

        return $return;
    }

    /**
     * <邮件>订单商品信息
     * @param $orderItems
     * @return string
     */
    private static function emailProductInfo($orderItems)
    {
        $orderItemString = '------------------------------<br/>';

        foreach ($orderItems as $v) {
            $optionObj = json_decode($v->options, true);
            $options = array_key_exists('data', $optionObj) ? $optionObj['data'] : [];

            /** 重新组合商品选项的字符串 */
            $optionStr = '';
            $index = 0;
            foreach ($options as $key=>$value) {
                if ($index) $optionStr .= ' | ';

                $optionStr .= $key . ': ' . $value;
                $index++;
            }

            $orderItemString .= <<<ITEM
                商品名　　　　　： {$v->item_name}<br/>
                オプション　　　：{$optionStr}<br/>
                単価　　　　　　：{$v->item_price}<br/>
                個数　　　　　　：{$v->quantity}<br/><br/>
ITEM;
        }

        $orderItemString .= '------------------------------<br/>';

        return $orderItemString;
    }

    /**
     * <邮件>配送信息
     * @param $order
     * @return string
     */
    private static function emailShippingInfo($order)
    {
        $shippingStr = '------------------------------<br/>';
        $orderItems = $order->orderItems;

        foreach ($orderItems as $k=>$v) {
            if (!$v->shipping_company || !$v->invoice_number) continue;

            $shippingStr .= <<<SHIPPING
            商品ID　　　　　　　　　：{$v->item_id}<br/>
            お届け方法　　　　　　　：{$v->shipping_company}<br/>
            お問い合わせ伝票番号　　：{$v->invoice_number}<br/>
            出荷日　　　　　　　　　：{$order->shipping_time}<br/><br/>
SHIPPING;
        }

        $shippingStr .= '------------------------------<br/>';

        return $shippingStr;
    }
}
