<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Logistic;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMore;
use App\Models\Process;
use App\Models\Shop;
use App\Models\EmailTemplate;
use App\Models\EmailSendLog;
use App\Services\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * 获取订单列表，有拉取订单的进程存在或进程刚刚结束，则直接获取数据库中的数据，否则重新请求 api ，并执行后台进程，返回最新10条数据
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('market', 'shop_name', 'sh_shop_id', 'keyword', 'order_status', 'shipment', 'page', 'page_size');

        $shopIds = Shop::getUserShop();
        if (!count($shopIds)) {
            $return['code'] = 'E201031';
            $return['message'] = __('lang.Invalid shop info.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
//        $user_id = $payload->aud ?: $payload->jti;
//        $process = DB::table('processes')->where(['user_id'=>$user_id, 'type'=>1])->first();
//
//        /** 订单拉取进程过期时间 */
//        $expiredTime = time() - env('ORDER_PROCESS_EXPIRE_TIME', 60) * 60;
//
//        /** api获取订单，并运行后台程序 */
//        if (
//            !$process //没有正在运行的抓取订单的进程（从来没有抓取过订单）
//            || (
//                !$process->state //进程停止运行
//                && (!$process->update_time || strtotime($process->update_time) < $expiredTime) //并且进程没有最后更新时间获取最后更新时间已过规定时间
//            )
//        ) {
//            /** 请求 api 获取订单数据，更新数据库中的订单（管理员下的所有的店铺） */
//            $shops = Shop::where('user_id', $user_id)->get();
//            $orderModel = new Order();
//            $orderModel->getLiveOrders($shops);
//        }

        /** 查询数据库返回结果 */
        $pageSize = $req['page_size'] ?? 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $pageSize;

        /** 根据店铺名查询 ksn_shops.id */
        if (array_key_exists('shop_name', $req)) {
            $shopName = trim(urldecode($req['shop_name']));
            $_shopData = Shop::select(DB::raw('GROUP_CONCAT(id) shop_ids'))->whereRaw("locate('{$shopName}', `shop_name`) > 0")->first();
            $shopIdArrByShopName = $_shopData->shop_ids ? explode(',', trim($_shopData->shop_ids)) : [];
            $shopIds = array_intersect($shopIds, $shopIdArrByShopName);
        }

        $orderInstance = Order::whereIn('shop_id', $shopIds)->where(function($query) use($req) {
            /** 按条件筛选订单 */
            if (array_key_exists('market', $req)) {
                $query->where('market', $req['market']);
            }
            if (array_key_exists('sh_shop_id', $req)) {
                $query->where('sh_shop_id', $req['sh_shop_id']);
            }
            if (array_key_exists('keyword', $req) && $req['keyword']) {
                $query->where(function($q) use($req) {
                    $keyword = trim($req['keyword']);
                    $q->orWhereRaw("locate('{$keyword}', `order_id`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `name_1`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `name_2`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `phone_1`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `phone_2`) > 0");
                });
            }
            if (array_key_exists('shipment', $req)) {
                /** 是否填写国际物流信息 */
                if ($req['shipment']) {
                    $query->where([
                        ['shipping_company', '!=', ''],
                        ['invoice_number_1', '!=', '']
                    ]);
                } else {
                    $query->where(function($q) {
                        $q->orWhere('shipping_company', '=', '');
                        $q->orWhere('invoice_number_1', '=', '');
                    });
                }
            }
        });

        /** 订单数量统计（只筛选market和sh_shop_id，不筛选order_id和order_status） */
        $commonCond = ['is_reserved'=>'0', 'auto_complete'=>'0'];

        $totalCount = (clone $orderInstance)->count();
        $init = (clone $orderInstance)->where(array_merge(['order_status'=>'0'], $commonCond))->count();
        $payBeforeDeliver = (clone $orderInstance)->where(array_merge(['order_status'=>'1'], $commonCond))->count();
        $prepareDeliver = (clone $orderInstance)->where(array_merge(['order_status'=>'2'], $commonCond))->count();
        $delivering = (clone $orderInstance)->where(array_merge(['order_status'=>'3'], $commonCond))->count();
        $delivered = (clone $orderInstance)->where(array_merge(['order_status'=>'4'], $commonCond))->count();
        $deliverComplete = (clone $orderInstance)->where(array_merge(['order_status'=>'5'], $commonCond))->count();
        $payAfterDeliver = (clone $orderInstance)->where(array_merge(['order_status'=>'6'], $commonCond))->count();
        $complete = (clone $orderInstance)->where(array_merge(['order_status'=>'7'], $commonCond))->count();
        $cancel = (clone $orderInstance)->where(array_merge(['order_status'=>'98'], $commonCond))->count();
        $reserve = (clone $orderInstance)->where('is_reserved', '1')->count();
        $autoComplete = (clone $orderInstance)->where('auto_complete', '1')->count();

        /** 继续筛选 order_status */
        $orderInstance->where(function($query) use($req, $commonCond){
            if (array_key_exists('order_status', $req)) {
                if ($req['order_status'] == '99') {
                    /** 保留中的订单（前端传递的状态为 99） */
                    $query->where('is_reserved', '1');
                } elseif ($req['order_status'] == '100') {
                    /** 官方仓处理的的订单（前端传递的状态为 100） */
                    $query->where('auto_complete', '1');
                } else {
                    $query->where(array_merge(['order_status'=>$req['order_status']], $commonCond));
                }
            }
        });

        $allCount = $orderInstance->count();//用于翻页
        $orders = $orderInstance->select(['id', 'market', 'sh_shop_id', 'order_id', 'order_status', 'total_price', 'order_time', 'remark', 'is_reserved', 'auto_complete'])->with(['orderItems'=>function($query){
            $query->with('orderItemMores');
        }, 'emailSendLogs'=>function($query){
            $query->select(['order_id', 'template_type', 'is_success']);
        }])->offset($offset)->limit($pageSize)->orderBy('order_time', 'DESC')->get();

        /** 可使用物流商/仓库 */
        $logistics = Logistic::getUserLogistics();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['orders'] = $orders;
        $return['result']['count'] = [
            'all'=>$allCount,
            'total'=>$totalCount,
            '0'=>$init,
            '1'=>$payBeforeDeliver,
            '2'=>$prepareDeliver,
            '3'=>$delivering,
            '4'=>$delivered,
            '5'=>$deliverComplete,
            '6'=>$payAfterDeliver,
            '7'=>$complete,
            '98'=>$cancel,
            '99'=>$reserve,
            '100'=>$autoComplete,
        ];
        $return['result']['status'] = $req['order_status'] ?? null;
        $return['result']['logistics'] = $logistics;

        return response()->json($return);
    }

    /**
     * 更新实时订单数据
     * @return \Illuminate\Http\JsonResponse
     */
    public function ordersUpdate()
    {
//        Order::daemonProcessUpdateOrder();

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS'
        ]);
    }

    /**
     * 查询是否订单正在实时更新中
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkOrderProcess()
    {
        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $process = Process::where(['user_id'=>$user_id, 'type'=>'1'])->first();

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>[
                'state'=>$process && $process->state ? $process->state : 0,
                'update_time'=>$process && $process->update_time ? $process->update_time : '-'
            ]
        ]);
    }

    /**
     * 刷新一个订单并获取返回
     * @param $id int ksn_orders.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $return = array();

        $shopIds = Shop::getUserShop();

//        $order = Order::with('orderItems')->whereIn('shop_id', $shopIds)->find($id);
//
//        if (!$order) {
//            $return['code'] = 'E301031';
//            $return['message'] = __('lang.Invalid order.');
//            return response()->json($return);
//        }
//
//        $shop = Shop::find($order->shop_id);
//
//        $orderLiveData = Agent::Markets($shop)->setClass('Order')->getOrder($order->order_id);
//
//        if ($orderLiveData['code']) {
//            $return['code'] = $orderLiveData['code'];
//            $return['message'] = $orderLiveData['message'];
//            $return['result'] = $order;
//
//            return response()->json($return);
//        }
//
//        /** 更新数据库中的订单 */
//        Order::updateOrders($shop, [$orderLiveData['result']]);

        /** 重新获取订单数据 */
        $order = Order::with('orderItems')->whereIn('shop_id', $shopIds)->find($id);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $order;

        return response()->json($return);
    }

    /**
     * 获取订单状态更新之前确认信息（更新订单弹窗数据）
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderUpdateConfirm(Request $request)
    {
        $return = array();

        $req = $request->only('order_ids');

        if (!array_key_exists('order_ids', $req) || !$req['order_ids']) {
            $return['code'] = 'E100031';
            $return['message'] = __('lang.Invalid order ID.');
            return response()->json($return);
        }

        $orderIds = explode(',', $req['order_ids']);

        $orders = Order::select(['orders.order_id', 'orders.order_status', 'orders.shipping_company', 'orders.invoice_number_1', 'orders.invoice_number_2', 'shops.id as sid', 'shops.market', 'shops.shop_id', 'shops.shop_name'])->leftJoin('shops', 'orders.shop_id', '=', 'shops.id')->whereIn('orders.order_id', $orderIds)->whereIn('orders.shop_id', Shop::getUserShop())->get();

        $confirmOrderData = array();
        $sidArr = array();
        /** 订单数据根据 shop.id 进行分组 */
        foreach ($orders as $k=>$v) {
            $confirmOrderData[$v->sid]['market'] = $v->market;
            $confirmOrderData[$v->sid]['shop_id'] = $v->shop_id;
            $confirmOrderData[$v->sid]['shop_name'] = $v->shop_name;

            $orderData = array();
            $orderData['order_id'] = $v->order_id;
            $orderData['order_status'] = $v->order_status;
            $orderData['shipping_company'] = $v->shipping_company;
            $orderData['invoice_number_1'] = $v->invoice_number_1;
            $orderData['invoice_number_2'] = $v->invoice_number_2;

            $confirmOrderData[$v->sid]['orders'][] = $orderData;

            if (!in_array($v->sid, $sidArr)) {
                $sidArr[] = $v->sid;
            }
        }

        /** 查询邮件模板数据，并加入到已分组的订单数据中 */
        foreach ($sidArr as $v) {
            $shopEmailTemplate = DB::table('shop_email_template')->select(DB::raw('GROUP_CONCAT(email_template_id) as email_template_id'))->where('shop_id', $v)->first();
            if (!$shopEmailTemplate->email_template_id) {
                $confirmOrderData[$v]['email_templates'] = [];
                continue;
            }

            $emailTemplateId = explode(',', $shopEmailTemplate->email_template_id);
            $emailTemplates = EmailTemplate::select(['id', 'type', 'title'])->whereIn('id', $emailTemplateId)->get();

            $confirmOrderData[$v]['email_templates'] = $emailTemplates;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = array_values($confirmOrderData);

        return response()->json($return);
    }

    /**
     * 更新订单状态，并发送邮件（新规订单和等待出库时发送）
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        set_time_limit(0);

        $return = array();
        $multiResult = array();

        $req = $request->only('order_ids', 'order_status', 'shop_email', 'order_shipping', 'shipping_date');

        if (
            !array_key_exists('order_ids', $req)
            || !is_array($req['order_ids'])
            || !count($req['order_ids'])
        ) {
            $return['code'] = 'E100031';
            $return['message'] = __('lang.Invalid order ID.');
            return response()->json($return);
        }
        if (
            !array_key_exists('order_status', $req)
            || !is_numeric($req['order_status'])
        ) {
            $return['code'] = 'E100032';
            $return['message'] = __('lang.Invalid order status.');
            return response()->json($return);
        }

        foreach ($req['order_ids'] as $order_id) {
            /**
             * 根据订单id获取国际物流信息
             * $shippingData: [
             *      shipping_company: some number,
             *      invoice_number_1: some number,
             *      invoice_number_2: some number,
             *      shipping_date: Y-m-d,
             * ]
             */
            $shippingData = $req['order_shipping'][$order_id] ?? [];
            if (count($shippingData)) {
                $shippingData['shipping_date'] = $req['shipping_date'] ?? null;
            }

            /** 店铺和邮件模板id映射关系 */
            $shopEmail = $req['shop_email'] ?? [];

            $statusUpdateResult = Order::updateOrderStatusInMarket($order_id, $req['order_status'], $shippingData, $shopEmail);
            if ($statusUpdateResult['code']) {
                /** 保存状态后继续处理下一个订单 */
                $multiResult[$order_id] = $statusUpdateResult;
                continue;
            }

            $multiResult[$order_id] = [
                'code'=>'',
                'message'=>'SUCCESS'
            ];
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $multiResult;

        return response()->json($return);
    }

    /**
     * 订单保留，还原 $req['reserve] == 1 则保留，$req['reserve] == 0 则还原
     * @param Request $request
     * @param $id int ksn_orders.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderReserve(Request $request, $id)
    {
        $return = array();

        $req = $request->only('reserve');
        $reserve = array_val('reserve', $req) ?: 0;

        $res = Order::where('id', $id)->whereIn('shop_id', Shop::getUserShop())->update(['is_reserved'=>$reserve]);

        if ($res === false) {
            $return['code'] = 'E303031';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 取消订单
     * @param $id int ksn_orders.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderCancel($id)
    {
        $return = array();

//        $order = Order::select(['id', 'shop_id', 'order_id'])->whereIn('shop_id', Shop::getUserShop())->find($id);
//
//        if (!$order) {
//            $return['code'] = 'E301031';
//            $return['message'] = __('lang.Invalid order.');
//            return response()->json($return);
//        }
//
//        $shop = Shop::find($order->shop_id);
//
//        /** 请求各平台订单取消API */
//        $result = Agent::Markets($shop)->setClass('Order')->cancelOrder($order->order_id);
//
//        if ($result['code']) {
//            $return['code'] = 'E503031';
//            $return['message'] = $result['message'];
//            return response()->json($return);
//        }

        $res = Order::where('id', $order->id)->update(['order_status'=>98]);

        if ($res === false) {
            $return['code'] = 'E303031';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 获取邮件发送日志（带上邮件模板信息）
     * @param $id int ksn_orders.id
     * @param $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function emailSendLogsWithTemplates($id, $type)
    {
        $return = array();

        $order = Order::select('shop_id')->whereIn('shop_id', Shop::getUserShop())->find($id);
        if (!$order) {
            $return['code'] = 'E301031';
            $return['message'] = __('lang.Invalid order.');
            return response()->json($return);
        }

        /** 获取邮件发送日志 */
        $emailSendLogs = EmailSendLog::where(['order_id'=>$id, 'template_type'=>$type])->orderBy('id', 'DESC')->get();

        /** 获取邮件模板，用于邮件在发送 */
        $shopTemplates = DB::table('shop_email_template')->select(DB::raw('GROUP_CONCAT(email_template_id) as template_ids'))->where('shop_id', $order->shop_id)->first();

        $emailTemplateDatas = [];
        if ($shopTemplates->template_ids) {
            $templateIdsArr = explode(',', $shopTemplates->template_ids);
            $emailTemplateDatas = EmailTemplate::select(['id', 'title'])->whereIn('id', $templateIdsArr)->where('type', $type)->get();
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['logs'] = $emailSendLogs;
        $return['result']['email_templates'] = $emailTemplateDatas;

        return response()->json($return);
    }

    /**
     * 给用户重新发送邮件
     * @param $id int ksn_orders.id
     * @param $templateId int ksn_email_templates.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendEmail($id, $templateId)
    {
        $return = array();

        if (!$templateId) {
            $return['code'] = 'E200031';
            $return['message'] = __('lang.Invalid Email template.');
            return response()->json($return);
        }

        $order = Order::whereIn('shop_id', Shop::getUserShop())->find($id);
        if (!$order) {
            $return['code'] = 'E301031';
            $return['message'] = __('lang.Invalid order.');
            return response()->json($return);
        }

        $shop = Shop::find($order->shop_id);

        $res = Order::sendOrderEmail($shop, $order, $templateId);

        if ($res) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        } else {
            $return['code'] = 'E600031';
            $return['message'] = __('lang.Email send failed.');
        }

        return response()->json($return);
    }

    /**
     * 更新订单商品的采购，快递，物流等信息
     * @param Request $request
     * @param $id int ksn_order_items.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrderItem(Request $request, $id)
    {
        $return = array();

        $req = $request->only('logistic_id', 'supply_market', 'supply_url', 'supply_order_id', 'supply_price', 'supply_delivery_code', 'supply_delivery_name', 'supply_delivery_number', 'shipping_company', 'invoice_number', 'shipping_option', 'remark');

        /** 重新设置值为 null 的项目 */
        foreach ($req as $k=>$v) {
            if (!is_null($v)) continue;

            $req[$k] = in_array($k, ['logistic_id', 'supply_price', 'shipping_option']) ? '0' : '';
        }

        if (!count($req)) {
            $return['code'] = 'E100031';
            $return['message'] = __('lang.No data.');
            return response()->json($return);
        }

        $shopIds = Shop::getUserShop();

        $res = OrderItem::where('id', $id)->whereIn('shop_id', $shopIds)->update($req);
        if ($res === false) {
            $return['code'] = 'E303031';
            $return['message'] = __('lang.Data update failed.');
            return response()->json($return);
        }

        /** 假如更新的是logistic_id（仓库id），则根据仓库的 auto_complete 属性把订单更新为自动处理 */
        if (array_key_exists('logistic_id', $req)) {
            $logistic = Logistic::where('id', $req['logistic_id'])->first();
            OrderItem::where('id', $id)->whereIn('shop_id', $shopIds)->update(['auto_complete'=>$logistic ? $logistic->auto_complete : 0]);

            /** 包含当前订单商品的订单下的所有商品，假如都是自动处理商品，则该订单设置为自动处理订单，至少一个是非自动处理商品，则订单设置为非自动处理订单 */
            $orderItem = OrderItem::find($id);
            $orderItemsInSameOrder = OrderItem::select(DB::raw('GROUP_CONCAT(auto_complete) AS auto_complete_group'))->where('order_id', $orderItem->order_id)->first();
            $autoCompleteGroup = explode(',', $orderItemsInSameOrder->auto_complete_group);

            $orderAutoCompleteValue = in_array('0', $autoCompleteGroup) ? '0' : '1';
            Order::where('id', $orderItem->order_id)->update(['auto_complete'=>$orderAutoCompleteValue]);
        }

        if (!array_key_exists('shipping_company', $req) && !array_key_exists('invoice_number', $req)) {
            /** 更新项目不是物流信息，则直接返回结果 */
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            return response()->json($return);
        }

        /** 更新项目为物流信息时，根据该订单商品的默认物流设置，相应的更新订单表的物流信息 */
        $orderItem = OrderItem::select(['order_id', 'shipping_company', 'invoice_number', 'shipping_default'])->where('id', $id)->whereIn('shop_id', $shopIds)->first();
        if ($orderItem->shipping_default) {
            Order::where('id', $orderItem->order_id)->update([
                'shipping_company'=>trim($orderItem->shipping_company),
                'invoice_number_1'=>trim($orderItem->invoice_number)
            ]);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        return response()->json($return);
    }

    /**
     * 根据订单商品的国际物流信息的默认值，修改订单表的国际物流信息
     * @param $id int ksn_order_items.id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateShippingData($id)
    {
        $return = array();

        $orderItem = OrderItem::whereIn('shop_id', Shop::getUserShop())->find($id);

        if (!$orderItem) {
            $return['code'] = 'E301031';
            $return['message'] = __('lang.Invalid order.');
            return response()->json($return);
        }

        if (!$orderItem->shipping_company || !$orderItem->invoice_number) {
            $return['code'] = 'E200031';
            $return['message'] = __('lang.Can not search shipping info.');
            return response()->json($return);
        }

        $res = Order::where('id', $orderItem->order_id)->update([
            'shipping_company'=>$orderItem->shipping_company,
            'invoice_number_1'=>$orderItem->invoice_number,
        ]);

        if ($res === false) {
            $return['code'] = 'E303031';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';

            OrderItem::where('order_id', $orderItem->order_id)->update(['shipping_default'=>'0']);
            OrderItem::where('id', $orderItem->id)->update(['shipping_default'=>'1']);
        }

        return response()->json($return);
    }

    /**
     * 获取附加采购商品
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderItemMore($id)
    {
        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $orderItemMore = OrderItemMore::where(['order_item_id'=>$id, 'user_id'=>$user_id])->get();

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>$orderItemMore
        ]);
    }

    /**
     * 更新附加采购商品的采购订单号和快递单号
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrderItemMore(Request $request, $id)
    {
        $return = array();

        $req = $request->only('supply_order_id', 'supply_delivery_number');

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $update = array();
        if (array_key_exists('supply_order_id', $req)) {
            $update['supply_order_id'] = array_val('supply_order_id', $req);
        }
        if (array_key_exists('supply_delivery_number', $req)) {
            $update['supply_delivery_number'] = array_val('supply_delivery_number', $req);
        }

        $res = OrderItemMore::where(['id'=>$id, 'user_id'=>$user_id])->update($update);

        if ($res === false) {
            $return['code'] = 'E303038';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 获取历史订单列表数据（只查询数据库，不请求API）
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function histories(Request $request)
    {
        $return = array();

        $req = $request->only('market', 'sh_shop_id', 'order_id', 'order_status', 'start_date', 'end_date', 'page');
        $shopIds = Shop::getUserShop();
        if (!count($shopIds)) {
            $return['code'] = 'E201031';
            $return['message'] = __('lang.Invalid shop info.');
            return response()->json($return);
        }

        /** 查询数据库返回结果 */
        $perpage = 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $perpage;

        $orderInstance = Order::whereIn('shop_id', $shopIds)->where(function($query) use($req) {
            /** 按条件筛选订单 */
            if (array_key_exists('market', $req)) {
                $query->where('market', $req['market']);
            }
            if (array_key_exists('sh_shop_id', $req)) {
                $query->where('sh_shop_id', $req['sh_shop_id']);
            }
            if (array_key_exists('order_id', $req) && $req['order_id']) {
                $query->where('order_id', $req['order_id']);
            }
            if (array_key_exists('order_status', $req)) {
                if ($req['order_status'] == '99') {
                    /** 保留中的订单（前端传递的状态为 99） */
                    $query->where('is_reserved', '1');
                } else {
                    $query->where('order_status', $req['order_status']);
                }
            }
            if (array_key_exists('start_date', $req)) {
                $query->where('order_time', '>=', $req['start_date'] . ' 00:00:00');
            }
            if (array_key_exists('end_date', $req)) {
                $query->where('order_time', '<=', $req['end_date'] . ' 23:59:59');
            }
        });

        $count = $orderInstance->count();
        $orders = $orderInstance->select(['id', 'market', 'sh_shop_id', 'order_id', 'order_status', 'total_price', 'order_time', 'remark', 'is_reserved'])
            ->with([
                'orderItems'=>function($query){
                    $query->with('orderItemMores')->select(['order_items.*', 'logistics.nickname', 'logistics.company', 'logistics.manager'])->leftJoin('logistics', 'order_items.logistic_id', '=', 'logistics.id');
                },
                'emailSendLogs'=>function($query){
                    $query->select(['order_id', 'template_type', 'is_success']);
                }
            ])
            ->offset($offset)
            ->limit($perpage)
            ->orderBy('order_time', 'DESC')
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['orders'] = $orders;
        $return['result']['count'] = $count;

        return response()->json($return);
    }

    /**
     * 获取历史订单详细（只查询数据库，不请求API）
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function historyView($id)
    {
        $return = array();

        $shopIds = Shop::getUserShop();
        if (!count($shopIds)) {
            $return['code'] = 'E201031';
            $return['message'] = __('lang.Invalid shop info.');
            return response()->json($return);
        }

        $order = Order::with('orderItems')->whereIn('shop_id', $shopIds)->find($id);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $order;

        return response()->json($return);
    }

    /**
     * 获取国际物流信息
     * @param $order_item_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function shippingInfo($order_item_id)
    {
        $return = array();

        $orderItem = OrderItem::select(['shipping_company', 'invoice_number'])->find($order_item_id);

        if (!$orderItem) {
            $return['code'] = 'E205036';
            $return['message'] = __('lang.Can not find order item.');
            return response()->json($return);
        }

        if (!$orderItem->shipping_company || !$orderItem->invoice_number) {
            $return['code'] = 'E205037';
            $return['message'] = __('lang.Invalid order item.');
            return response()->json($return);
        }

        $module = shippingCompanyMap($orderItem->shipping_company);

        if (!$module) {
            $return['code'] = 'E205038';
            $return['message'] = __('lang.Invalid order item.');
            return response()->json($return);
        }

        $deliveryResult = Agent::Delivery($module)->setClass('Crawl')->deliveryInfo($orderItem->invoice_number);
        $return = $deliveryResult;

        if (!$return['code']) {
            $return['result']['shipping_company'] = $module;
        }

        return response()->json($return);
    }

    /**
     * 统一更新订单商品的采购，快递信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unityUpdateOrderItem(Request $request)
    {
        $return = array();

        $req = $request->only('item_id', 'item_options', 'unity_supply_order_id', 'unity_supply_delivery_name', 'unity_supply_delivery_number');

        if (
            !$req['unity_supply_order_id']
            || !$req['unity_supply_delivery_name']
            || !$req['unity_supply_delivery_number']
        ) {
            $return['code'] = 'E100031';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $orderItemIds = OrderItem::select(DB::raw('GROUP_CONCAT(ksn_order_items.id) AS order_item_id'))
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where([
                'orders.order_status'=>'2',
                'order_items.item_id'=>$req['item_id'],
                'order_items.item_options'=>$req['item_options'],
                'order_items.auto_complete'=>'0',
                'order_items.supply_order_id'=>''
            ])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->first();

        if (!$orderItemIds->order_item_id) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = 0;
            return response()->json($return);
        }

        $orderItemIdArr = explode(',', $orderItemIds->order_item_id);

        $res = OrderItem::whereIn('id', $orderItemIdArr)->update([
            'supply_order_id'=>$req['unity_supply_order_id'],
            'supply_delivery_name'=>$req['unity_supply_delivery_name'],
            'supply_delivery_number'=>$req['unity_supply_delivery_number'],
        ]);

        if ($res === false) {
            $return['code'] = 'E303031';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = count($orderItemIdArr);
        }

        return response()->json($return);
    }
}
