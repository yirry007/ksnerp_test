<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Logistic;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMore;
use App\Models\Shop;
use App\Models\StoreGoods;
use App\Models\StoreGoodsItem;
use App\Tools\Image\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    /**
     * 获取商品资料列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('market', 'keyword', 'is_depot', 'status', 'page', 'page_size');

        /** 查询数据库返回结果 */
        $pageSize = $req['page_size'] ?? 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $pageSize;

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $itemInstance = Item::where('items.user_id', $user_id)->where(function($query) use($req) {
            /** 按条件筛选订单 */
            if (array_key_exists('market', $req)) {
                $query->where('items.market', $req['market']);
            }
            if (array_key_exists('keyword', $req) && $req['keyword']) {
                $query->where(function($q) use($req) {
                    $keyword = trim($req['keyword']);
                    $q->orWhereRaw("locate('{$keyword}', `ksn_items`.`item_id`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `ksn_items`.`ksn_code`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `ksn_items`.`merged_ksn_code`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `ksn_items`.`merging_ksn_code`) > 0");
                    $q->orWhereRaw("locate('{$keyword}', `ksn_items`.`item_name`) > 0");
                });
            }
            if (array_key_exists('is_depot', $req)) {
                $query->where('items.is_depot', $req['is_depot']);
            }
            if (array_key_exists('status', $req)) {
                $query->where('items.status', $req['status']);
            }
        });

        $count = $itemInstance->count();//用于翻页
        $items = $itemInstance
            ->select(DB::raw('ksn_items.*, ksn_supplies.supply_image, ksn_logistics.nickname, COUNT(ksn_supply_mores.id) AS supply_more_count'))
            ->leftJoin('supplies', 'items.supply_id', '=', 'supplies.id')
            ->leftJoin('supply_mores', 'supplies.id', '=', 'supply_mores.supply_id')
            ->leftJoin('logistics', 'items.logistic_id', '=', 'logistics.id')
            ->offset($offset)
            ->limit($pageSize)
            ->orderBy('items.id', 'DESC')
            ->groupBy('items.id')
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['count'] = $count;
        $return['result']['items'] = $items;

        return response()->json($return);
    }

    /**
     * 发现新商品（订单商品中把未被记录到商品资料的数据保存到商品资料）
     * @return \Illuminate\Http\JsonResponse
     */
    public function discover()
    {
        set_time_limit(0);

        $return = array();

        $discoveredNum = 0;
        $maxSameItemNum = 50;
        $sameItemNum = 0;
        $hasNext = true;
        $idMax = PHP_INT_MAX;

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        while ($sameItemNum < $maxSameItemNum && $hasNext) {
            /** 在 order_items 数据表中获取 id 值小于 $idMax 的两个数据 */
            $orderItems = OrderItem::select(['order_items.id', 'order_items.item_id', 'order_items.item_sub_id', 'order_items.item_management_id', 'order_items.item_number', 'order_items.item_name', 'order_items.item_price', 'order_items.item_url', 'order_items.item_options', 'shops.market'])->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')->where('shops.user_id', $user_id)->where('order_items.id', '<=', $idMax)->orderBy('order_items.id', 'DESC')->limit(2)->get();

            $currentOrderItem = $orderItems[0] ?? null;//当前操作的订单商品
            $nextOrderItem = $orderItems[1] ?? null;//下一个操作的商品

            $hasNext = $nextOrderItem ? true : false;//查看是否有下一个商品
            $idMax = $nextOrderItem ? $nextOrderItem->id : 0;//下一个商品id作为查询的最大id值

            /** 没有获取到任何数据，则直接结束循环 */
            if (!$currentOrderItem) break;

            /** 商品资料中查看有没有一样的商品 */
            $exist = Item::where(['user_id'=>$user_id, 'item_id'=>$currentOrderItem->item_id, 'item_options'=>$currentOrderItem->item_options])->count();

            if ($exist) {
                /** 当前操作的商品数据库中存在 */
                $sameItemNum++;//相同商品个数 +1，连续达到一定数量则终止循环
                continue;
            }

            $sameItemNum = 0;//重置相同商品个数

            $insert = array();
            $insert['user_id'] = $user_id;
            $insert['item_id'] = $currentOrderItem->item_id;
            $insert['item_sub_id'] = $currentOrderItem->item_sub_id;
            $insert['item_management_id'] = $currentOrderItem->item_management_id;
            $insert['item_number'] = $currentOrderItem->item_number;
            $insert['ksn_code'] = strtoupper(uniqid('KSN_'));
            $insert['market'] = $currentOrderItem->market;
            $insert['item_name'] = $currentOrderItem->item_name;
            $insert['item_price'] = $currentOrderItem->item_price;
            $insert['item_url'] = $currentOrderItem->item_url;
            $insert['item_options'] = $currentOrderItem->item_options;
            $insert['create_time'] = date('Y-m-d H:i:s');

            $res = Item::create($insert);
            if (!$res) continue;

            $discoveredNum++;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $discoveredNum;

        return response()->json($return);
    }

    /**
     * 获取商品资料，已被映射的商品，物流商信息
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 商品资料 */
        $item = Item::where('user_id', $user_id)->find($id);

        /** 采购商品 */
        $supply = DB::table('supplies')->where(['id'=>$item->supply_id, 'user_id'=>$user_id])->first();

        /** 附加采购商品 */
        if ($supply) {
            $supplyMore = DB::table('supply_mores')->where('supply_id', $supply->id)->get();
        } else {
            $supplyMore = [];
        }

        /** 可使用物流商/仓库 */
        $logistics = Logistic::getUserLogistics();

        /** 可选推荐采购商品（由于之前没有被准确映射） */
        $recSupply = array();
        if ($item->rec_supply_id) {
            $resSupplyIdArr = explode(',', $item->rec_supply_id);
            $recSupply = DB::table('supplies')->whereIn('id', $resSupplyIdArr)->get();
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['item'] = $item;
        $return['result']['supply'] = $supply;
        $return['result']['supply_more'] = $supplyMore;
        $return['result']['logistics'] = $logistics;
        $return['result']['rec_supply'] = $recSupply;

        return response()->json($return);
    }

    /**
     * 更新商品资料模板
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $return = array();

        $req = $request->only('item', 'supply', 'supply_more');

        if (!array_key_exists('item', $req) || !is_array($req['item'])) {
            $return['code'] = 'E100060';
            $return['message'] = __('lang.Invalid data.');
            return response()->json($return);
        }

        $item = $req['item'];
        $supply = $req['supply'] ?? [];
        $supplyMore = $req['supply_more'] ?? [];

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $itemData = array();
        $supplyId = $supply['id'] ?? 0;
        if ($supply) {
            $supplyData = array();
            $supplyData['supply_code'] = $supply['supply_code'] ?? '';
            $supplyData['supply_opt'] = $supply['supply_opt'] ?? '';
            $supplyData['supply_market'] = $supply['supply_market'] ?? '';
            $supplyData['supply_url'] = $supply['supply_url'] ?? '';
            $supplyData['supply_name'] = $supply['supply_name'] ?? '';
            $supplyData['supply_options'] = $supply['supply_options'] ?? '';
            $supplyData['supply_image'] = $supply['supply_image'] ?? '';
            $supplyData['supply_price'] = $supply['supply_price'] ?? 0;
            $supplyData['supply_unit'] = $supply['supply_unit'] ?? 1;
            $supplyData['min_quantity'] = $supply['min_quantity'] ?? 1;

            /** 1688 通过api获取的商品（有商品选项唯一标识符和商品图片地址），下载该sku图片 */
            if ($supplyData['supply_opt'] && $supplyData['supply_image']) {
                /** 下载sku图片 */
                $image = Image::save($supplyData['supply_image'], 'url');
                $itemData['item_image'] = _url_($image);
                $itemData['item_image_path'] = $image;

                $_item = DB::table('items')->select(['item_image_path'])->where(['id'=>$id, 'user_id'=>$user_id])->first();
                @unlink($_item->item_image_path);
            }

            if ($supplyId) {
                DB::table('supplies')->where(['id'=>$supplyId, 'user_id'=>$user_id])->update($supplyData);
            } else {
                $supplyData['user_id'] = $user_id;
                $supplyData['create_time'] = date('Y-m-d H:i:s');

                $supplyId = DB::table('supplies')->insertGetId($supplyData);
            }

            /** 附加采购商品 */
            DB::table('supply_mores')->where('supply_id', $supplyId)->delete();
            if ($supplyMore) {
                foreach ($supplyMore as $k=>$v) {
                    $supplyMore[$k]['user_id'] = $user_id;
                    $supplyMore[$k]['supply_id'] = $supplyId;
                    $supplyMore[$k]['create_time'] = date('Y-m-d H:i:s');
                    unset($supplyMore[$k]['id']);
                }
                DB::table('supply_mores')->insert($supplyMore);
            }

            /** 设置 items 表的是否库存商品字段 */
            $itemData['is_depot'] = $supplyData['supply_market'] == 'store' ? '1' : '0';

            /** 设置 items 表的物流商 logistic_id */
            $storeGoods = StoreGoods::select(['logistics_id'])->where('code', $supplyData['supply_code'])->first();
            if ($storeGoods) {
                $itemData['logistic_id'] = $storeGoods->logistics_id;
            }
        }

        $itemData['logistic_id'] = $itemData['logistic_id'] ?? $item['logistic_id'];
        $itemData['supply_id'] = $supplyId;
        $itemData['status'] = max($item['status'], 2);
        $itemData['shipping_option'] = $item['shipping_option'] ?? '0';

        $res = DB::table('items')->where(['id'=>$id, 'user_id'=>$user_id])->update($itemData);

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
     * 商品资料图片修改
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateItemImage(Request $request, $id)
    {
        $return = array();

        $req = $request->only('data', 'type');

        if (!$image = array_val('data', $req)) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.No image file.');
            return response()->json($return);
        }
        if (!$type = array_val('type', $req)) {
            $return['code'] = 'E100062';
            $return['message'] = __('lang.No image file type.');
            return response()->json($return);
        }

        $result = Image::save($image, $type);

        if (!$result) {
            $return['code'] = 'E702061';
            $return['message'] = __('lang.Image save failed.');
            return response()->json($return);
        }

        $imageUrl = _url_($result);

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $res = Item::where(['id'=>$id, 'user_id'=>$user_id])->update([
            'item_image'=>$imageUrl,
            'item_image_path'=>$result,
        ]);

        if ($res === false) {
            $return['code'] = 'E303061';
            $return['message'] = __('lang.Image save failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $imageUrl;
        }

        return response()->json($return);
    }

    /**
     * 更新商品资料的待采购商品id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateItemSupplyId(Request $request)
    {
        $return = array();

        $req = $request->only('item_id', 'supply_id');

        $itemId = array_val('item_id', $req);
        $supplyId = array_val('supply_id', $req);

        if (!$itemId || !$supplyId) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $res = Item::where(['id'=>$itemId, 'user_id'=>$user_id])->update([
            'supply_id'=>$supplyId,
            'status'=>'2'
        ]);

        if ($res === false) {
            $return['code'] = 'E303063';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 修改商品资料状态
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function changeStatus(Request $request, $id)
    {
        $return = array();

        $req = $request->only('status');

        if (array_val('status', $req) == 4) {
            $status = 4;
        } else {
            $item = Item::select(['merging_ksn_code', 'is_depot', 'supply_id', 'logistic_id'])->find($id);
            if ($item->merging_ksn_code) {
                /** 已被合并的商品 */
                $status = 3;
            } elseif ($item->is_depot || $item->supply_id || $item->logistic_id) {
                /** 已被编辑的商品 */
                $status = 2;
            } else {
                $status = 0;
            }
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $res = Item::where(['id'=>$id, 'user_id'=>$user_id])->update(['status'=>$status]);

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
     * 商品资料合并
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function merge(Request $request)
    {
        $return = array();

        $req = $request->only('item_ids', 'main_item_id');

        if (!array_key_exists('item_ids', $req) || !array_key_exists('main_item_id', $req)) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 取消映射关系[先取消当前商品的子商品映射，再取消当前商品的父商品的映射] */
        foreach ($req['item_ids'] as $v) {
            /** 获取当前循环商品信息 */
            $_item = Item::select(['id', 'ksn_code', 'merging_ksn_code'])->where('user_id', $user_id)->find($v);
            if (!$_item) continue;

            /** 取消子商品映射 */
            $_mergingItem = Item::select(['is_depot', 'supply_id', 'logistic_id'])->where(['merging_ksn_code'=>$_item->ksn_code, 'user_id'=>$user_id])->first();
            if ($_mergingItem) {
                $_updateMerging = array();
                $_updateMerging['merging_ksn_code'] = '';
                $_updateMerging['status'] = !$_mergingItem->is_depot && !$_mergingItem->supply_id && !$_mergingItem->logistic_id ? 0 : 2;
                Item::where('merging_ksn_code', $_item->ksn_code)->update($_updateMerging);
            }

            /** 取消父商品映射 */
            $_mergedItem = Item::select(['id', 'merged_ksn_code'])->where(['ksn_code'=>$_item->merging_ksn_code, 'user_id'=>$user_id])->first();
            if ($_mergedItem) {
                $_mergedKsnCode = explode(',', $_mergedItem->merged_ksn_code);
                $_mergedKsnCode = array_filter($_mergedKsnCode, function($value) use($_item){
                    return $value != $_item->ksn_code;
                });

                Item::where('id', $_mergedItem->id)->update(['merged_ksn_code'=>implode(',', $_mergedKsnCode)]);
            }
        }

        /** 处理映射商品 */
        $mergingItemIds = array_filter($req['item_ids'], function($value) use($req){
            return $value != $req['main_item_id'];
        });
        $mergedItem = Item::select(['id', 'ksn_code'])->where('user_id', $user_id)->find($req['main_item_id']);
        if (count($mergingItemIds)) {
            $updateMerging = array();
            $updateMerging['merged_ksn_code'] = '';
            $updateMerging['merging_ksn_code'] = $mergedItem->ksn_code;
            $updateMerging['status'] = 3;

            Item::whereIn('id', $mergingItemIds)->update($updateMerging);
        }

        /** 处理被映射的主体商品 */
        $mergingItems = Item::select(['id', 'ksn_code'])->where('user_id', $user_id)->whereIn('id', $mergingItemIds)->get();
        $mergedKsnCode = array();
        foreach ($mergingItems as $v) {
            $mergedKsnCode[] = $v->ksn_code;
        }

        $updateMerged = array();
        $updateMerged['merged_ksn_code'] = implode(',', $mergedKsnCode);
        $updateMerged['merging_ksn_code'] = '';
        $updateMerging['status'] = 2;
        Item::where('id', $mergedItem->id)->update($updateMerged);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        return response()->json($return);
    }

    /**
     * 获取附加采购商品
     * @param $supply_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function supplyMore($supply_id)
    {
        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $supplyMore = DB::table('supply_mores')->where(['user_id'=>$user_id, 'supply_id'=>$supply_id])->get();

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>$supplyMore
        ]);
    }

    /**
     * 附加采购商品数据更新
     * @param Request $request
     * @param $id
     * @param $mode
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSupplyMore(Request $request, $id, $mode='')
    {
        $return = array();

        $req = $request->only('supply_unit');

        if (!count($req)) {
            $return['code'] = 'E100066';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $res = DB::table('supply_mores')->where(['id'=>$id, 'user_id'=>$user_id])->update($req);

        if ($res === false) {
            $return['code'] = 'E303062';
            $return['message'] = __('lang.Data update failed.');
            return response()->json($return);
        }

        /** 更新已映射的订单扩展采购商品 */
        if ($mode == 'reset_mapped') {
            $req2 = $request->only('item_id', 'item_options');

            if (!$itemId = array_val('item_id', $req2)) {
                $return['code'] = 'E100067';
                $return['message'] = __('lang.Parameter error.');
                return response()->json($return);
            }
            if (!$itemOptions = array_val('item_options', $req2)) {
                $return['code'] = 'E100068';
                $return['message'] = __('lang.Parameter error.');
                return response()->json($return);
            }

            /** 查询需要添加（附加采购商品）的订单商品id */
            $orderItemIds = OrderItem::select(DB::raw('GROUP_CONCAT(ksn_order_items.id) AS order_item_id'))
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->where([
                    ['orders.user_id', $user_id],
                    ['order_items.item_id', $itemId],
                    ['order_items.item_options', $itemOptions],
                    ['orders.order_status', '=', '2']
                ])
                ->first();

            if (!$orderItemIds->order_item_id) {
                $return['code'] = 'E301064';
                $return['message'] = __('lang.Can not find order item.');
                return response()->json($return);
            }

            $orderItemIdArr = explode(',', $orderItemIds->order_item_id);
            $resOrder1 = OrderItemMore::where('supply_more_id', $id)->whereIn('order_item_id', $orderItemIdArr)->update($req);
            $resOrder2 = OrderItemMore::leftJoin('order_items', 'order_item_mores.order_item_id', '=', 'order_items.id')->where('order_item_mores.supply_more_id', $id)->whereIn('order_item_mores.order_item_id', $orderItemIdArr)->update([
                'order_item_mores.supply_quantity'=>DB::raw('ksn_order_items.quantity * ksn_order_item_mores.supply_unit')
            ]);

            if ($resOrder1 === false || $resOrder2 === false) {
                $return['code'] = 'E303063';
                $return['message'] = __('lang.Data update failed.');
                return response()->json($return);
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 删除附加采购商品
     * @param Request $request
     * @param $id
     * @param $mode
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSupplyMore(Request $request, $id, $mode='')
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $res = DB::table('supply_mores')->where(['id'=>$id, 'user_id'=>$user_id])->delete();

        if (!$res) {
            $return['code'] = 'E304065';
            $return['message'] = __('lang.Data delete failed.');
            return response()->json($return);
        }

        /** 删除已映射的订单扩展采购商品 */
        if ($mode == 'reset_mapped') {
            $req2 = $request->only('item_id', 'item_options');

            if (!$itemId = array_val('item_id', $req2)) {
                $return['code'] = 'E100068';
                $return['message'] = __('lang.Parameter error.');
                return response()->json($return);
            }
            if (!$itemOptions = array_val('item_options', $req2)) {
                $return['code'] = 'E100069';
                $return['message'] = __('lang.Parameter error.');
                return response()->json($return);
            }

            /** 查询需要添加（附加采购商品）的订单商品id */
            $orderItemIds = OrderItem::select(DB::raw('GROUP_CONCAT(ksn_order_items.id) AS order_item_id'))
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->where([
                    ['orders.user_id', $user_id],
                    ['order_items.item_id', $itemId],
                    ['order_items.item_options', $itemOptions],
                    ['orders.order_status', '=', '2']
                ])
                ->first();

            if (!$orderItemIds->order_item_id) {
                $return['code'] = 'E301065';
                $return['message'] = __('lang.Can not find order item.');
                return response()->json($return);
            }

            $orderItemIdArr = explode(',', $orderItemIds->order_item_id);
            $resOrder = OrderItemMore::where('supply_more_id', $id)->whereIn('order_item_id', $orderItemIdArr)->delete();

            if ($resOrder === false) {
                $return['code'] = 'E303064';
                $return['message'] = __('lang.Data delete failed.');
                return response()->json($return);
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 待采购商品列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sourcing(Request $request)
    {
        $return = array();

        $req = $request->only('supply_type', 'source_status', 'keyword');

        $orderItems = OrderItem::select(DB::raw('ksn_order_items.*, SUM(ksn_order_items.quantity) AS total_count, ksn_orders.market, ksn_logistics.nickname, ksn_logistics.company, ksn_logistics.manager'))
            ->with('orderItemMores')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('logistics', 'order_items.logistic_id', '=', 'logistics.id')
            ->where([
                'orders.order_status'=>'2',
                'order_items.auto_complete'=>'0',
                'order_items.supply_order_id'=>''
            ])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->where(function($query) use($req) {
                $supplyType = array_val('supply_type', $req);
                $sourceStatus = array_val('source_status', $req);
                $keyword = array_val('keyword', $req);

                if ($supplyType == 'matched') {
                    $query->where('supply_id', '>', '0');
                }
                if ($supplyType == 'unmatched') {
                    $query->where('supply_id', '0');
                }
                if ($supplyType == 'depot') {
                    $query->where('is_depot', '1');
                }
                if ($sourceStatus == 'sourcing') {
                    $query->where('supply_order_id', '');
                }
                if ($sourceStatus == 'sourced') {
                    $query->where('supply_order_id', '!=', '');
                }
                if ($keyword) {
                    $query->where(function($q) use($keyword) {
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`sh_shop_id`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`item_id`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`item_name`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`item_options`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_code`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_market`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_name`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_options`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_order_id`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_order_items`.`supply_delivery_number`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `ksn_orders`.`order_id`) > 0");
                    });
                }
            })
            ->groupBy('order_items.item_id', 'order_items.item_options')
            ->orderBy('order_items.id', 'DESC')
            ->get();

        /** 可使用物流商/仓库 */
        $logistics = Logistic::getUserLogistics();

        /** 获取商品库存数量信息 */
        $storeInfo = StoreGoodsItem::getByOrderItem($orderItems);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['items'] = $orderItems;
        $return['result']['store'] = $storeInfo;
        $return['result']['logistics'] = $logistics;

        return response()->json($return);
    }

    /**
     * 采购商品自动映射
     * @return \Illuminate\Http\JsonResponse
     */
    public function mapItem()
    {
        set_time_limit(0);

        $return = array();
        $mapCount = 0;
        $hasNext = true;
        $idMax = PHP_INT_MAX;

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $shopIds = Shop::getUserShop();

        while ($hasNext) {
            $orderItems = OrderItem::select(['order_items.id', 'order_items.order_id', 'order_items.item_id', 'order_items.item_options', 'order_items.quantity'])
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->where([
                    ['orders.order_status', '=', '2'],
                    ['order_items.id', '<=', $idMax],
                    ['order_items.supply_order_id', '=', ''],
                ])
                ->whereIn('order_items.shop_id', $shopIds)
                ->orderBy('order_items.id', 'DESC')
                ->limit(2)
                ->get();

            $currentOrderItem = $orderItems[0] ?? null;//当前操作的订单商品
            $nextOrderItem = $orderItems[1] ?? null;//下一个操作的商品

            $hasNext = $nextOrderItem ? true : false;//查看是否有下一个商品
            $idMax = $nextOrderItem ? $nextOrderItem->id : 0;//下一个商品id作为查询的最大id值

            /** 没有获取到任何数据，则直接结束循环 */
            if (!$currentOrderItem) break;

            $item = Item::where(['user_id'=>$user_id, 'item_id'=>$currentOrderItem->item_id, 'item_options'=>$currentOrderItem->item_options])->first();

            /** 没有查询到商品资料 */
            if (!$item || $item->status == 4) continue;

            if ($item->merging_ksn_code) {
                /** 获取合并的主商品 */
                $item = Item::where('ksn_code', $item->merging_ksn_code)->first();
            }

            /** 合并后的主商品的资料丢失 */
            if (!$item) continue;

            /** 没有采购商品id，则直接跳过 */
            if (!$item->supply_id) continue;

            $supply = DB::table('supplies')->where('id', $item->supply_id)->first();
            /** 没有查询到采购商品信息 */
            if (!$supply) continue;

            /** 更新订单商品的采购商品信息 */
            OrderItem::where('id', $currentOrderItem->id)->update([
                'ksn_code'=>$item->ksn_code,
                'item_image'=>$item->item_image,
                'item_image_path'=>$item->item_image_path,
                'logistic_id'=>$item->logistic_id,
                'supply_id'=>$item->supply_id,
                'supply_code'=>$supply->supply_code,
                'supply_opt'=>$supply->supply_opt,
                'supply_market'=>$supply->supply_market,
                'supply_url'=>$supply->supply_url,
                'supply_name'=>$supply->supply_name,
                'supply_options'=>$supply->supply_options,
                'supply_image'=>$supply->supply_image,
                'supply_price'=>$supply->supply_price,
                'supply_unit'=>$supply->supply_unit,
                'min_quantity'=>$supply->min_quantity,
                'supply_quantity'=>$currentOrderItem->quantity * $supply->supply_unit,
                'shipping_option'=>$item->shipping_option,
            ]);

            /** 处理附加采购商品（删除重新添加，仅限未采购商品） */
            $supplyMore = DB::table('supply_mores')->where('supply_id', $supply->id)->get();
            if ($supplyMore) {
                OrderItemMore::where('order_item_id', $currentOrderItem->id)->delete();

                $orderItemMore = array();
                foreach ($supplyMore as $k=>$v) {
                    $orderItemMore[$k]['order_id'] = $currentOrderItem->order_id;
                    $orderItemMore[$k]['order_item_id'] = $currentOrderItem->id;
                    $orderItemMore[$k]['item_id'] = $currentOrderItem->item_id;
                    $orderItemMore[$k]['supply_id'] = $v->supply_id;
                    $orderItemMore[$k]['user_id'] = $v->user_id;
                    $orderItemMore[$k]['supply_more_id'] = $v->id;
                    $orderItemMore[$k]['supply_code'] = $v->supply_code;
                    $orderItemMore[$k]['supply_opt'] = $v->supply_opt;
                    $orderItemMore[$k]['supply_market'] = $v->supply_market;
                    $orderItemMore[$k]['supply_url'] = $v->supply_url;
                    $orderItemMore[$k]['supply_name'] = $v->supply_name;
                    $orderItemMore[$k]['supply_options'] = $v->supply_options;
                    $orderItemMore[$k]['supply_image'] = $v->supply_image;
                    $orderItemMore[$k]['supply_price'] = $v->supply_price;
                    $orderItemMore[$k]['supply_unit'] = $v->supply_unit;
                    $orderItemMore[$k]['min_quantity'] = $v->min_quantity;
                    $orderItemMore[$k]['supply_quantity'] = $currentOrderItem->quantity * $v->supply_unit;
                }

                if ($orderItemMore) {
                    OrderItemMore::insert($orderItemMore);
                }
            }

            /** 库存商品匹配，官方仓特别处理 */
            if ($item->is_depot) {
                /** 获取仓库信息 */
                $logistic = Logistic::where('id', $item->logistic_id)->first();

                /** 没有仓库信息，则直接跳过 */
                if (!$logistic) continue;

                /** 设置订单商品的仓库信息 */
                $orderItemRes = OrderItem::where('id', $currentOrderItem->id)->update([
                    'is_depot'=>$item->is_depot,
                    'logistic_id'=>$logistic->id,
                    'auto_complete'=>$logistic->auto_complete
                ]);

                if ($orderItemRes === false) continue;

                /** 包含当前订单商品的订单下的所有商品，假如都是自动处理商品，则该订单设置为自动处理订单 */
                $orderItemsInSameOrder = OrderItem::select(['auto_complete', 'is_depot'])->where('order_id', $currentOrderItem->order_id)->get();
                $allAutoComplete = true;//所有商品都是自动处理商品
                $allIsDepot = true;//所有商品都是库存商品

                foreach ($orderItemsInSameOrder as $_v) {
                    if (!$_v->auto_complete) {
                        $allAutoComplete = false;
                    }
                    if (!$_v->is_depot && !$_v->auto_complete) {
                        $allIsDepot = false;
                    }
                }

                if ($allAutoComplete) {
                    Order::where('id', $currentOrderItem->order_id)->update(['auto_complete'=>'1']);
                }
                if ($allIsDepot) {
                    Order::where('id', $currentOrderItem->order_id)->update(['order_status'=>'3']);
                }
            }

            $mapCount++;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $mapCount;

        return response()->json($return);
    }

    /**
     * 根据item_id，获取可用的（最近映射的一条）采购商品url
     * @param $item_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSupplyUrl($item_id)
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $item = Item::select(['supply_id'])->where([['user_id', $user_id], ['item_id', $item_id], ['supply_id', '>', '0']])->orderBy('id', 'desc')->first();

        if (!$item) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = '';
            return response()->json($return);
        }

        $supply = DB::table('supplies')->where('user_id', $user_id)->find($item->supply_id);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $supply ? $supply->supply_url : '';

        return response()->json($return);
    }

    /**
     * 编辑商品资料，采购商品，重新映射订单商品
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function remapItem(Request $request)
    {
        $return = array();

        $req = $request->only('item_id', 'item_options', 'supply');

        if (!$itemId = array_val('item_id', $req)) {
            $return['code'] = 'E100065';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }
        if (!$itemOptions = array_val('item_options', $req)) {
            $return['code'] = 'E100065';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $supply = $req['supply'] ?? [];
        if (!$supply) {
            $return['code'] = 'E100066';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 商品资料，如果没有，则新增一个 */
        $item = Item::where(['user_id'=>$user_id, 'item_id'=>$itemId, 'item_options'=>$itemOptions])->first();
        if (!$item) {
            $orderItem = OrderItem::select(['order_items.id', 'order_items.item_id', 'order_items.item_sub_id', 'order_items.item_management_id', 'order_items.item_number', 'order_items.item_name', 'order_items.item_price', 'order_items.item_url', 'order_items.item_options', 'shops.market'])->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')->where(['shops.user_id'=>$user_id, 'order_items.item_id'=>$itemId, 'order_items.item_options'=>$itemOptions])->orderBy('order_items.id', 'DESC')->first();

            if (!$orderItem) {
                $return['code'] = 'E201067';
                $return['message'] = __('lang.Can not find order item.');
                return response()->json($return);
            }

            $insert = array();
            $insert['user_id'] = $user_id;
            $insert['item_id'] = $orderItem->item_id;
            $insert['item_sub_id'] = $orderItem->item_sub_id;
            $insert['item_management_id'] = $orderItem->item_management_id;
            $insert['item_number'] = $orderItem->item_number;
            $insert['ksn_code'] = strtoupper(uniqid('KSN_'));
            $insert['market'] = $orderItem->market;
            $insert['item_name'] = $orderItem->item_name;
            $insert['item_price'] = $orderItem->item_price;
            $insert['item_url'] = $orderItem->item_url;
            $insert['item_options'] = $orderItem->item_options;
            $insert['create_time'] = date('Y-m-d H:i:s');

            $item = Item::create($insert);
        }

        /** 设置 items 表的是否库存商品字段 */
        $item->is_depot = array_val('supply_market', $supply) == 'store' ? '1' : '0';

        /** 设置 items 表的物流商 logistic_id */
        $storeGoods = StoreGoods::select(['logistics_id'])->where('code', array_val('supply_code', $supply))->first();
        if ($storeGoods) {
            $item->logistic_id = $storeGoods->logistics_id;
        }

        /** 采购商品，如果有则更新，如果没有则新增并更新商品资料的supply_id */
        if (!$item->supply_id) {
            $supply['user_id'] = $user_id;
            $supply['create_time'] = date('Y-m-d H:i:s');
            $supplyId = DB::table('supplies')->insertGetId($supply);

            if ($supplyId) {
                $item->supply_id = $supplyId;
                $item->status = 2;
            }
        } else {
            $supplyId = $item->supply_id;
            DB::table('supplies')->where('id', $supplyId)->update($supply);
        }

        $item->save();

        /** 处理商品图片，1688 通过api获取的商品（有商品选项唯一标识符和商品图片地址），下载该sku图片 */
        if ($supply['supply_opt'] && $supply['supply_image']) {
            /** 先删除原有图片 */
            @unlink($item->item_image_path);

            /** 下载sku图片 */
            $image = Image::save($supply['supply_image'], 'url');
            $item->item_image = _url_($image);
            $item->item_image_path = $image;
            $item->save();
        }

        /** 重新映射，更新订单商品数据 */
        $orderItemUpdate = $supply;
        unset($orderItemUpdate['user_id']);
        $orderItemUpdate['supply_id'] = $supplyId;
        $orderItemUpdate['ksn_code'] = $item->ksn_code;
        $orderItemUpdate['item_image'] = $item->item_image;
        $orderItemUpdate['item_image_path'] = $item->item_image_path;
        $orderItemUpdate['is_depot'] = $item->is_depot;
        $orderItemUpdate['logistic_id'] = $item->logistic_id;
        $orderItemUpdate['supply_quantity'] = DB::raw('ksn_order_items.quantity * ksn_order_items.supply_unit');

        $res = OrderItem::where(['shops.user_id'=>$user_id, 'order_items.item_id'=>$itemId, 'order_items.item_options'=>$itemOptions])->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')->where('orders.order_status', '=', '2')->update($orderItemUpdate);

        if ($res === false) {
            $return['code'] = 'E303068';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $orderItemUpdate;
        }

        return response()->json($return);
    }

    /**
     * 添加订单附加采购商品
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addSupplyMore(Request $request)
    {
        $return = array();

        $req = $request->only('item_id', 'item_options', 'supply');

        if (!$itemId = array_val('item_id', $req)) {
            $return['code'] = 'E100068';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }
        if (!$itemOptions = array_val('item_options', $req)) {
            $return['code'] = 'E100068';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $supply = $req['supply'] ?? [];
        if (!$supply) {
            $return['code'] = 'E100069';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 先插入采购商品 */
        $supply['user_id'] = $user_id;
        $supply['create_time'] = date('Y-m-d H:i:s');
        $supplyMoreId = DB::table('supply_mores')->insertGetId($supply);

        if (!$supplyMoreId) {
            $return['code'] = 'E302064';
            $return['message'] = __('lang.Additional item add failed.');
            return response()->json($return);
        }

        /** 查询需要添加（附加采购商品）的订单商品id */
        $orderItems = OrderItem::select(['order_items.id', 'order_items.order_id', 'order_items.quantity'])
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where([
                ['orders.user_id', $user_id],
                ['order_items.item_id', $itemId],
                ['order_items.item_options', $itemOptions],
                ['orders.order_status', '=', '2']
            ])
            ->get();

        if (!$orderItems) {
            $return['code'] = 'E301063';
            $return['message'] = __('lang.Can not find order item.');
            return response()->json($return);
        }

        $supply['supply_more_id'] = $supplyMoreId;
        $supply['item_id'] = $itemId;
        unset($supply['create_time']);

        $orderItemMoreData = array();
        foreach ($orderItems as $k=>$v) {
            $supply['order_id'] = $v->order_id;
            $supply['order_item_id'] = $v->id;
            $supply['supply_quantity'] = $v->quantity * $supply['supply_unit'];
            $orderItemMoreData[$k] = $supply;
        }

        $orderItemMoreRes = OrderItemMore::insert($orderItemMoreData);

        if (!$orderItemMoreRes) {
            $return['code'] = 'E302067';
            $return['message'] = __('lang.Additional item add failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $supply;
        }

        return response()->json($return);
    }

    /**
     * 更新采购备注
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSupplyMemo(Request $request)
    {
        $return = array();

        $req = $request->only('supply_memo', 'item_id', 'item_options');

        $res = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['order_items.item_id'=>$req['item_id'], 'order_items.item_options'=>$req['item_options']])
            ->where('orders.order_status', '=', '2')
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->update(['order_items.supply_memo'=>$req['supply_memo']]);

        if ($res === false) {
            $return['code'] = 'E303066';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 设置物流商/库存信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateLogistic(Request $request)
    {
        $return = array();

        $req = $request->only('logistic_id', 'item_id', 'item_options');

        $res = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['order_items.item_id'=>$req['item_id'], 'order_items.item_options'=>$req['item_options']])
            ->where('orders.order_status', '=', '2')
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->update(['order_items.logistic_id'=>$req['logistic_id']]);

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
     * 设置物流选项信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateShippingOption(Request $request)
    {
        $return = array();

        $req = $request->only('shipping_option', 'item_id', 'item_options');

        $res = OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(['order_items.item_id'=>$req['item_id'], 'order_items.item_options'=>$req['item_options']])
            ->where('orders.order_status', '=', '2')
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->update(['order_items.shipping_option'=>$req['shipping_option']]);

        if ($res === false) {
            $return['code'] = 'E303062';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 获取采购商品详细
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sourcingView(Request $request)
    {
        $return = array();

        $req = $request->only('item_id', 'item_options');
        $item_id = array_val('item_id', $req);
        $item_options = array_val('item_options', $req);

        if (!$item_id || !$item_options) {
            $return['code'] = 'E100061';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $orderItems = OrderItem::select(['order_items.id', 'order_items.item_id', 'item_name', 'item_price', 'quantity', 'item_url', 'item_options', 'item_image', 'supply_id', 'supply_url', 'supply_name', 'supply_options', 'supply_image', 'supply_price', 'supply_order_id', 'supply_delivery_name', 'supply_delivery_number', 'orders.order_id', 'orders.order_time', 'shops.market', 'shops.shop_id', 'shops.shop_name'])
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('shops', 'order_items.shop_id', '=', 'shops.id')
            ->where([
                'orders.order_status'=>'2',
                'order_items.item_id'=>$item_id,
                'order_items.item_options'=>$item_options,
                'order_items.auto_complete'=>'0',
                'order_items.supply_order_id'=>''
            ])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->get();

        $item = array();
        $supply = null;
        $orders = array();
        foreach ($orderItems as $k=>$v) {
            /** 构建订单列表 */
            $order = array();
            $order['order_item_id'] = $v->id;
            $order['order_id'] = $v->order_id;
            $order['order_time'] = $v->order_time;
            $order['market'] = $v->market;
            $order['shop_id'] = $v->shop_id;
            $order['shop_name'] = $v->shop_name;
            $order['quantity'] = $v->quantity;
            $order['supply_order_id'] = $v->supply_order_id;
            $order['supply_delivery_name'] = $v->supply_delivery_name;
            $order['supply_delivery_number'] = $v->supply_delivery_number;
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
}
