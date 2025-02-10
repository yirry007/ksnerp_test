<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Tools\Image\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChromeController extends Controller
{
    /**
     * chrome extension popup api
     * 获取各个店铺的待采购商品数量
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function mainData(Request $request)
    {
        $return = array();
        $result = array();

        $shopIds = Shop::getUserShop();

        /** 查询所有待采购商品数量（库存商品不属于待采购商品范围） */
        $allSourcingItemCount = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0'])->whereIn('order_items.shop_id', $shopIds)->sum('order_items.quantity');

        $result[] = [
            'id'=>0,
            'market'=>null,
            'shop_id'=>'待采购商品',
            'quantity'=>$allSourcingItemCount
        ];

        /** 查询各个店铺待采购商品数量（库存商品不属于待采购商品范围） */
        foreach ($shopIds as $v) {
            $shopSourcingItemCount = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['orders.order_status'=>'2', 'order_items.shop_id'=>$v, 'order_items.is_depot'=>'0'])->sum('order_items.quantity');
            $shop = Shop::select(['market', 'shop_id'])->find($v);

            $result[] = [
                'id'=>$v,
                'market'=>strtolower($shop->market),
                'shop_id'=>$shop->shop_id,
                'quantity'=>$shopSourcingItemCount
            ];
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $result;

        return response()->json($return);
    }

    /**
     * chrome extension popup api
     * 获取待采购商品列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function itemList(Request $request)
    {
        $return = array();

        $req = $request->only('shop_id');
        $shopId = array_val('shop_id', $req);
        $shopIds = Shop::getUserShop();

        if ($shopId && !in_array($shopId, $shopIds))  {
            $return['code'] = 'E200061';
            $return['message'] = __('lang.Invalid shop info.');
            return response()->json($return);
        }

        $orderItems = OrderItem::select(DB::raw('ksn_order_items.id, ksn_order_items.item_id, item_name, GROUP_CONCAT(ksn_order_items.id) AS ids, SUM(ksn_order_items.quantity) AS quantity, item_options, item_image, supply_id, supply_url, supply_name, supply_options, supply_image, supply_price'))
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0'])
            ->where(function($query) use($shopId, $shopIds){
                if ($shopId) {
                    $query->where('order_items.shop_id', $shopId);
                } else {
                    $query->whereIn('order_items.shop_id', $shopIds);
                }
            })
            ->orderBy('order_items.id', 'DESC')
            ->groupBy('order_items.item_id', 'order_items.item_options')
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $orderItems;

        return response()->json($return);
    }

    /**
     * chrome extension popup api
     * 获取采购商品详细
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function itemInfo(Request $request)
    {
        $return = array();

        $req = $request->only('ids');
        $ids = array_val('ids', $req);

        if (!$ids) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $idArr = explode(',', $ids);
        $orderItems = OrderItem::select(['order_items.item_id', 'item_name', 'item_price', 'quantity', 'item_url', 'item_options', 'item_image', 'supply_id', 'supply_url', 'supply_name', 'supply_options', 'supply_image', 'supply_price', 'orders.order_id', 'orders.order_time', 'shops.market', 'shops.shop_id', 'shops.shop_name'])
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')
            ->whereIn('order_items.id', $idArr)
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->get();

        $item = array();
        $supply = null;
        $orders = array();
        foreach ($orderItems as $k=>$v) {
            /** 构建订单列表 */
            $order = array();
            $order['order_id'] = $v->order_id;
            $order['order_time'] = $v->order_time;
            $order['market'] = $v->market;
            $order['shop_id'] = $v->shop_id;
            $order['shop_name'] = $v->shop_name;
            $order['quantity'] = $v->quantity;
            $orders[] = $order;

            /** 订单商品数量累加 */
            if (array_key_exists('quantity', $item))
                $item['quantity'] += $v->quantity;

            if ($k > 0) continue;

            /** 构建订单商品数据 */
            $item['item_id'] = $v->item_id;
            $item['item_name'] = $v->item_name;
            $item['item_price'] = $v->item_price;
            $item['quantity'] = $v->quantity;
            $item['item_url'] = $v->item_url;
            $item['item_options'] = $v->item_options;
            $item['item_image'] = $v->item_image;

            if (!$v->supply_id) continue;

            /** 构建采购商品数据 */
            $supply = array();
            $supply['supply_id'] = $v->supply_id;
            $supply['supply_url'] = $v->supply_url;
            $supply['supply_name'] = $v->supply_name;
            $supply['supply_options'] = $v->supply_options;
            $supply['supply_image'] = $v->supply_image;
            $supply['supply_price'] = $v->supply_price;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['item'] = $item;
        $return['result']['supply'] = $supply;
        $return['result']['orders'] = $orders;

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 获取待采购和已采购商品数量
     * @return \Illuminate\Http\JsonResponse
     */
    public function itemCount()
    {
        $return = array();

        $shopIds = Shop::getUserShop();

        /** 待采购商品数量 */
        $sourcingItemCount = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0'])->where('order_items.supply_order_id', '=', '')->whereIn('order_items.shop_id', $shopIds)->sum('order_items.quantity');

        /** 已采购商品数量 */
        $sourcedItemCount = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0'])->where('order_items.supply_order_id', '!=', '')->whereIn('order_items.shop_id', $shopIds)->sum('order_items.quantity');

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['sourcing_item_count'] = $sourcingItemCount;
        $return['result']['sourced_item_count'] = $sourcedItemCount;

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 获取待采购商品以及他所在的订单
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSourcingItems()
    {
        $return = array();

        $orderItems = OrderItem::select(DB::raw('ksn_order_items.*, ksn_orders.order_id AS oid, SUM(ksn_order_items.quantity) AS total_count, GROUP_CONCAT(ksn_order_items.order_id) as order_ids'))
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0', 'order_items.supply_order_id'=>''])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->orderBy('order_items.id', 'DESC')
            ->groupBy('order_items.item_id', 'order_items.item_options')
            ->get();

        foreach ($orderItems as $v) {
            $v->orders = OrderItem::select(['order_items.id', 'order_items.quantity', 'orders.market', 'orders.shop_id', 'orders.order_id', 'orders.order_time', 'shops.shop_name'])
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')
                ->where(['order_items.item_id'=>$v->item_id, 'order_items.item_options'=>$v->item_options])
                ->whereIn('orders.id', explode(',', $v->order_ids))
                ->orderBy('orders.order_time')
                ->get();
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $orderItems;

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 更新订单商品的采购信息
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrderItem(Request $request, $id)
    {
        $return = array();

        $req = $request->only('supply_market', 'supply_order_id', 'supply_delivery_code', 'supply_delivery_name', 'supply_delivery_number');

        /** null 转为空字符串 */
        foreach ($req as $k=>$v) {
            $req[$k] = is_null($v) ? '' : $req[$k];
        }

        if (!count($req)) {
            $return['code'] = 'E200062';
            $return['message'] = __('lang.No update data.');
            return response()->json($return);
        }

        $res = OrderItem::where('id', $id)->whereIn('shop_id', Shop::getUserShop())->update($req);
        if ($res === false) {
            $return['code'] = 'E303061';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 验证当前商品商品详细页是否有匹配商品
     * @param string $supplyCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function hasMatched($supplyCode)
    {
        $return = array();

        $hasMatchedItem = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0', 'order_items.supply_order_id'=>'', 'supply_code'=>$supplyCode])->whereIn('order_items.shop_id', Shop::getUserShop())->count();

        if ($hasMatchedItem) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        } else {
            $return['code'] = 'E201061';
            $return['message'] = __('lang.No data.');
        }

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 获取待采购商品的详细信息（选项，数量等）
     * @param string $supplyCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMatchedItem($supplyCode)
    {
        $return = array();

        $matchedItems = OrderItem::select(['order_items.id', 'order_items.item_id', 'item_name', 'item_price', 'quantity', 'item_url', 'item_options', 'item_image', 'supply_id', 'supply_url', 'supply_name', 'supply_options', 'supply_image', 'supply_price', 'orders.order_id', 'orders.order_time', 'orders.market', 'orders.sh_shop_id'])
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0', 'order_items.supply_order_id'=>'', 'supply_code'=>$supplyCode])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->get();

        if (!count($matchedItems)) {
            $return['code'] = 'E201061';
            $return['message'] = __('lang.No data.');
            return response()->json($return);
        }

        $itemInfo = array();
        $optionInfo = array();
        $minPrice = 999999999;
        $maxPrice = 0;
        $quantity = 0;

        foreach ($matchedItems as $k=>$v) {
            $minPrice = $v->supply_price < $minPrice ? $v->supply_price : $minPrice;
            $maxPrice = $v->supply_price > $maxPrice ? $v->supply_price : $maxPrice;
            $quantity += $v->quantity;

            $key = $v->item_id . '|' . $v->item_options;
            if (array_key_exists($key, $optionInfo)) {
                $optionInfo[$key]['quantity'] += $v->quantity;
            } else {
                $optionInfo[$key]['supply_options'] = $v->supply_options;
                $optionInfo[$key]['quantity'] = $v->quantity;
            }

            $_orderData = array();
            $_orderData['market'] = $v->market;
            $_orderData['shop_id'] = $v->sh_shop_id;
            $_orderData['order_id'] = $v->order_id;
            $_orderData['order_time'] = $v->order_time;
            $_orderData['quantity'] = $v->quantity;

            $optionInfo[$key]['orders'][] = $_orderData;

            if ($k) continue;

            /** 以第一个商品构建商品的基本信息 */
            $itemInfo['image_url'] = $v->supply_image;
            $itemInfo['supply_name'] = $v->supply_name;
        }

        $itemInfo['price_range'] = $minPrice != $maxPrice ? $minPrice . ' - ' . $maxPrice : $maxPrice;
        $itemInfo['quantity'] = $quantity;

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['item_info'] = $itemInfo;
        $return['result']['option_info'] = array_values($optionInfo);

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 获取已采购订单以及商品
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSourcedItems()
    {
        $return = array();

        $orderItems = OrderItem::select(['order_items.id', 'order_items.item_id', 'item_name', 'item_price', 'quantity', 'item_url', 'item_options', 'item_image', 'supply_id', 'supply_market', 'supply_url', 'supply_name', 'supply_options', 'supply_image', 'supply_order_id', 'supply_price', 'supply_delivery_code', 'supply_delivery_name', 'supply_delivery_number', 'orders.order_id', 'orders.market', 'orders.sh_shop_id'])
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['orders.order_status'=>'2', 'order_items.is_depot'=>'0'])
            ->where('order_items.supply_order_id', '!=', '')
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->orderBy('order_items.id', 'DESC')
            ->get();

        $result = [];
        foreach ($orderItems as $v) {
            if (array_key_exists($v->order_id, $result)) {
                $result[$v->order_id]['order_items'][] = $v;
            } else {
                $result[$v->order_id]['order_id'] = $v->order_id;
                $result[$v->order_id]['market'] = $v->market;
                $result[$v->order_id]['shop_id'] = $v->sh_shop_id;
                $result[$v->order_id]['order_items'][] = $v;
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = array_values($result);

        return response()->json($return);
    }

    /**
     * chrome extension content api
     * 更新订单状态，等待采购(2) -> 等待出库(3)
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOrder(Request $request)
    {
        set_time_limit(0);

        $return = array();
        $multiResult = array();

        $req = $request->only('order_ids');

        if (
            !array_key_exists('order_ids', $req)
            || !is_array($req['order_ids'])
            || !count($req['order_ids'])
        ) {
            $return['code'] = 'E100031';
            $return['message'] = '无效的订单ID';
            return response()->json($return);
        }

        foreach ($req['order_ids'] as $order_id) {
            $statusUpdateResult = Order::updateOrderStatusInMarket($order_id, 2);
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
     * chrome extension content api
     * 抓取的采购商品数据，更新订单商品和商品资料库
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function itemMatch(Request $request)
    {
        set_time_limit(0);

        $return = array();
        $count = 0;

        $req = $request->only('supply_order_items');

        $supplyOrderItems = $req['supply_order_items'];

        if (!count($supplyOrderItems)) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.No data.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        foreach ($supplyOrderItems as $v) {
            /** 没有采购订单id，直接跳过 */
            if (!$v['supply_order_id']) continue;

            $orderItems = OrderItem::select(['order_items.*', 'shops.market'])->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')->where('supply_order_id', $v['supply_order_id'])->get();

            /** 没有查询到匹配的订单商品，则跳过 */
            if (!count($orderItems)) continue;

            /** 如果没有supply_code，则记录后跳过 */
            if (!array_key_exists('supply_code', $v)) continue;

            $supply = DB::table('supplies')->where(['user_id'=>$user_id, 'supply_code'=>$v['supply_code'], 'supply_market'=>$v['supply_market'], 'supply_options'=>$v['supply_options']])->first();
            $supplyOpt = $v['supply_opt'] ?: 'M_' . md5($v['supply_code'] . '|' . $v['supply_options']);

            /** 没有采购商品信息，则新增一个 */
            if (!$supply) {
                $_supply = array();
                $_supply['user_id'] = $user_id;
                $_supply['supply_code'] = $v['supply_code'];
                $_supply['supply_opt'] = $supplyOpt;
                $_supply['supply_market'] = $v['supply_market'];
                $_supply['supply_url'] = $v['supply_url'];
                $_supply['supply_name'] = $v['supply_name'];
                $_supply['supply_options'] = $v['supply_options'] ?? '';
                $_supply['supply_image'] = $v['supply_image'];
                $_supply['supply_price'] = $v['supply_price'];
                $_supply['create_time'] = date('Y-m-d H:i:s');

                $supply_id = DB::table('supplies')->insertGetId($_supply);
            } else {
                $supply_id = $supply->id;
            }

            /** 添加采购商品失败，则跳过 */
            if (!$supply_id) continue;

            foreach ($orderItems as $v1) {
                /** 更新快递信息 */
                $updateDate = array();
                if ($supplyDeliveryCode = array_val('supply_delivery_code', $v)) {
                    $updateDate['supply_delivery_code'] = $supplyDeliveryCode;
                }
                if ($supplyDeliveryName = array_val('supply_delivery_name', $v)) {
                    $updateDate['supply_delivery_name'] = $supplyDeliveryName;
                }
                if ($supplyDeliveryNumber = array_val('supply_delivery_number', $v)) {
                    $updateDate['supply_delivery_number'] = $supplyDeliveryNumber;
                }
                if ($supplyDeliveryReceived = array_val('supply_delivery_received', $v)) {
                    $updateDate['supply_delivery_received'] = $supplyDeliveryReceived;
                }

                if (count($updateDate)) {
                    OrderItem::where('id', $v1->id)->update($updateDate);
                    $count++;
                }

                /** 已匹配未修改的商品直接跳过 */
                if ($v1->supply_id && ($supply && $supply->supply_code == $v['supply_code'])) continue;

                $item = Item::where(['user_id'=>$user_id, 'item_id'=>$v1->item_id, 'item_options'=>$v1->item_options])->first();

                /** 假如没有商品资料，则新创建一个 */
                if (!$item) {
                    $_item = array();
                    $_item['user_id'] = $user_id;
                    $_item['item_id'] = $v1->item_id;
                    $_item['item_sub_id'] = $v1->item_sub_id;
                    $_item['item_management_id'] = $v1->item_management_id;
                    $_item['item_number'] = $v1->item_number;
                    $_item['ksn_code'] = strtoupper(uniqid('KSN_'));
                    $_item['market'] = $v1->market;
                    $_item['item_name'] = $v1->item_name;
                    $_item['item_price'] = $v1->item_price;
                    $_item['item_url'] = $v1->item_url;
                    $_item['item_options'] = $v1->item_options;
                    $_item['create_time'] = date('Y-m-d H:i:s');

                    /** 下载商品图片，并保存 */
                    $imageRes = Image::save($v['supply_image'], 'url');
                    if ($imageRes) {
                        $_item['item_image'] = _url_($imageRes);
                        $_item['item_image_path'] = $imageRes;
                    }

                    $item = Item::create($_item);
                }

                if (count($orderItems) > 1) {
                    /** 多个订单商品只记录 ksn_items.rec_supply_id (推荐采购商品id) */
                    $supplyIdArr = explode(',', $item->rec_supply_id);
                    if (!in_array($supply_id, $supplyIdArr)) {
                        $supplyIdArr[] = $supply_id;
                    }

                    Item::where('id', $item->id)->update([
                        'rec_supply_id'=>trim(trim(implode(',', $supplyIdArr)), ',')
                    ]);
                } else {
                    /** 只有一个订单商品，则精准匹配 */

                    /** 更新订单商品的匹配信息 */
                    $_orderItem = array();
                    $_orderItem['supply_id'] = $supply_id;
                    $_orderItem['supply_code'] = $v['supply_code'];
                    $_orderItem['supply_opt'] = $supplyOpt;
                    $_orderItem['supply_market'] = $v['supply_market'];
                    $_orderItem['supply_url'] = $v['supply_url'];
                    $_orderItem['supply_name'] = $v['supply_name'];
                    $_orderItem['supply_options'] = $v['supply_options'];
                    $_orderItem['supply_image'] = $v['supply_image'];
                    $_orderItem['supply_price'] = $v['supply_price'];
                    $_orderItem['supply_quantity'] = $v1->quantity * $v1->supply_unit;

                    OrderItem::where('id', $v1->id)->update($_orderItem);

                    /** 更新商品资料里的 supply_id */
                    Item::where('id', $item->id)->update([
                        'supply_id'=>$supply_id,
                        'status'=>'1',
                    ]);
                }
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['count'] = $count;

        return response()->json($return);
    }

    /**
     * 统一更新订单商品的采购，快递信息
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function unityUpdateOrderItem(Request $request, $id)
    {
        $return = array();

        $req = $request->only('unity_supply_market', 'unity_supply_order_id');

        if (
            !$req['unity_supply_market']
            || !$req['unity_supply_order_id']
        ) {
            $return['code'] = 'E100031';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $orderItem = OrderItem::select(['item_id', 'item_options'])->find($id);

        if (!$orderItem) {
            $return['code'] = 'E100032';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $orderItemIds = OrderItem::select(DB::raw('GROUP_CONCAT(ksn_order_items.id) AS order_item_id'))
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where([
                'orders.order_status'=>'2',
                'order_items.is_depot'=>'0',
                'order_items.item_id'=>$orderItem->item_id,
                'order_items.item_options'=>$orderItem->item_options
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
            'supply_market'=>$req['unity_supply_market'],
            'supply_order_id'=>$req['unity_supply_order_id'],
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
