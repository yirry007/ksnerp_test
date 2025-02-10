<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Logistic;
use App\Models\StoreGoods;
use App\Models\StoreGoodsItem;
use App\Models\StoreOut;
use App\Models\StoreOutLog;
use App\Models\User;
use App\Tools\Image\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    private $userId;

    public function __construct()
    {
        try {
            $payload = getTokenPayload();
            $this->userId = $payload->aud ?: $payload->jti;
        } catch (\Exception $e) {
            $userId = null;
        }
    }

    /**
     * 获取库存商品
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeInfo(Request $request)
    {
        $return = array();
        $req = $request->only('keyword', 'page');

        /** 查询数据库返回结果 */
        $perpage = 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $perpage;

        $storeGoodsInstance = StoreGoods::leftJoin('logistics', 'store_goods.logistics_id', '=', 'logistics.id')
            ->where([
                'store_goods.user_id'=>$this->userId,
                'store_goods.statu'=>'0',
            ])
            ->where(function($query) use($req) {
                if (array_key_exists('keyword', $req) && $req['keyword']) {
                    $query->where(function($q) use($req) {
                        $keyword = trim($req['keyword']);
                        $q->orWhereRaw("locate('{$keyword}', `usernames`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `name`) > 0");
                        $q->orWhereRaw("locate('{$keyword}', `code`) > 0");
                    });
                }
            });

        $storeCount = $storeGoodsInstance->count();

        $store = $storeGoodsInstance->select(['store_goods.*', 'logistics.nickname'])
            ->with(['storeGoodsItems'])
            ->offset($offset)
            ->limit($perpage)
            ->orderBy('id', 'DESC')
            ->get();

        $requestCount = DB::table('store_in_log')
            ->leftJoin('store_in', 'store_in_log.store_in_id', '=', 'store_in.id')
            ->where('store_in.statu', '0')
            ->count();

        $storeOutListsCount = DB::table('store_out_lists')->where('user_id', $this->userId)->count();

        $users = User::select(['username as label', 'username as value'])->where('parent_id', $this->userId)->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = [
            'store_count'=>$storeCount,
            'store_goods'=>$store,
            'request_count'=>$requestCount,
            'store_out_lists_count'=>$storeOutListsCount,
            'users'=>$users
        ];

        return response()->json($return);
    }

    /**
     * 获取入库申请中的商品
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeRequesting()
    {
        $return = array();

        $requesting = DB::table('store_in_log')
            ->select(['store_goods_items.sku', 'store_in_log.num', 'store_in_log.price', 'store_goods_items.item_json', 'store_goods_items.img_src'])
            ->leftJoin('store_in', 'store_in_log.store_in_id', '=', 'store_in.id')
            ->leftJoin('store_goods_items', 'store_in_log.sku', '=', 'store_goods_items.sku')
            ->where(['store_in.statu'=>'0', 'store_in.user_id'=>$this->userId])
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $requesting;

        return response()->json($return);
    }

    /**
     * 获取商品SKU key（颜色，尺码等）
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSkuKeys(Request $request)
    {
        $return = array();

        $req = $request->only('store_goods_id');

        if (!$storeGoodsId = array_val('store_goods_id', $req)) {
            $return['code'] = 'E100071';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $goodsItem = StoreGoodsItem::select(['item_json'])->where('store_goods_id', $storeGoodsId)->first();
        if (!$goodsItem || !$goodsItem->item_json) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = [];
            return response()->json($return);
        }

        $itemJson = json_decode($goodsItem->item_json, true);
        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = array_keys($itemJson);

        return response()->json($return);
    }

    /**
     * 获取物流商信息
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLogistics()
    {
        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = Logistic::getUserLogistics();

        return response()->json($return);
    }

    /**
     * 商品申请入库
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeRequest(Request $request)
    {
        $return = array();

        $req = $request->only('request_level', 'store_goods_id', 'store_goods_item_id', 'name', 'num', 'price', 'usernames', 'logistic_id', 'waybill_number', 'user_explain', 'sku_group');

        if (!$requestLevel = array_val('request_level', $req)) {
            $return['code'] = 'E100071';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }
        if (!array_member('num', $req)) {
            $return['code'] = 'E100072';
            $return['message'] = __('lang.Please input item number.');
            return response()->json($return);
        }
        if (!array_member('name', $req)) {
            $return['code'] = 'E100071';
            $return['message'] = __('lang.Please input item name.');
            return response()->json($return);
        }
        if (!array_member('logistic_id', $req)) {
            $return['code'] = 'E100073';
            $return['message'] = __('lang.Please select logistic.');
            return response()->json($return);
        }
        if ($requestLevel == 'sku' && !array_val('store_goods_id', $req)) {
            $return['code'] = 'E100074';
            $return['message'] = __('lang.Invalid item.');
            return response()->json($return);
        }
        if ($requestLevel == 'number' && !array_val('store_goods_item_id', $req)) {
            $return['code'] = 'E100075';
            $return['message'] = __('lang.Invalid SKU.');
            return response()->json($return);
        }

        $skuGroup = array();
        $firstImage = '';//SKU第一张图片作为商品主图
        /** 先保存图片，只处理第一个拥有图片的SKU组 */
        if (array_key_exists('sku_group', $req) && is_array($req['sku_group'])) {
            $defaultImageType = 'base64';
            $hasSaved = false;//是否已保存一组SKU图片
            foreach ($req['sku_group'] as $k=>$v) {
                if (!$v['hasImage']) continue;

                foreach ($v['values'] as $k1=>$v1) {
                    if ($hasSaved || !$v1['image']) {
                        $req['sku_group'][$k]['values'][$k1]['image'] = null;
                        continue;
                    }

                    $imagePath = Image::save($v1['image'], $defaultImageType, false);
                    $req['sku_group'][$k]['values'][$k1]['image'] = _url_($imagePath);

                    /** 设置第一张图片作为商品主图 */
                    if (!$firstImage) $firstImage = _url_($imagePath);
                }

                $hasSaved = true;
            }

            /** 重新组合SKU */
            foreach ($req['sku_group'] as $v) {
                $skuGroup[$v['key']] = $v['values'];
            }
        }

        /** 组合算法生成所有SKU组合 */
        $combinedSku = $skuGroup ? $this->generateSku($skuGroup) : [];

        if ($requestLevel == 'all') {
            /** 新增商品 */
            $storeGoodsId = StoreGoods::insertGetId([
                'code'=>strtoupper(uniqid('sg_')),
                'logistics_id'=>$req['logistic_id'],
                'user_id'=>$this->userId,
                'usernames'=>trim(array_val('usernames', $req)),
                'type'=>'1',
                'name'=>$req['name'],
                'img_src'=>$firstImage,
                'addtime'=>time()
            ]);
        } else {
            $storeGoodsId = array_val('store_goods_id', $req);
        }

        /** 先生成入库总申请单 */
        $storeInId = DB::table('store_in')->insertGetId([
            'sn'=>uniqid('SN_' . $req['logistic_id']. '_'),
            'logistics_id'=>$req['logistic_id'],
            'user_id'=>$this->userId,
            'waybill_number'=>$req['waybill_number'] ?? '',
            'num'=>$req['num'],
            'statu'=>'0',
            'user_explain'=>$req['user_explain'] ?? '',
            'addtime'=>time()
        ]);

        $price = array_val('price', $req, true);
        $storeInLogData = array();//详细sku申请列表数据
        if ($requestLevel == 'all' || $requestLevel == 'sku') {
            /** 新增SKU，并组合详细sku申请列表数据 */
            $storeGoodsItemData = array();//新增的SKU数据
            foreach ($combinedSku as $k=>$v) {
                $ksnCode = strtoupper(uniqid());

                $storeGoodsItemData[$k]['sku'] = $ksnCode;
                $storeGoodsItemData[$k]['store_goods_id'] = $storeGoodsId;
                $storeGoodsItemData[$k]['num'] = '';
                $storeGoodsItemData[$k]['price'] = $price;
                $storeGoodsItemData[$k]['addtime'] = time();

                $itemArr = [];
                $imgSrc = '';
                foreach ($v as $k1=>$v1) {
                    $itemArr[trim($k1)] = trim($v1['val']);

                    if (!$imgSrc && $v1['image']) {
                        $imgSrc = $v1['image'];
                    }
                }
                $itemJson = json_encode($itemArr, JSON_UNESCAPED_UNICODE);
                $storeGoodsItemData[$k]['item_json'] = $itemJson;
                $storeGoodsItemData[$k]['img_src'] = $imgSrc;

                /** 生成详细sku申请列表数据 */
                $storeInLogData[$k]['store_in_id'] = $storeInId;
                $storeInLogData[$k]['item_specifications'] = $itemJson;
                $storeInLogData[$k]['sku'] = $ksnCode;
                $storeInLogData[$k]['store_goods_id'] = $storeGoodsId;
                $storeInLogData[$k]['num'] = $req['num'];
                $storeInLogData[$k]['price'] = $price;
                $storeInLogData[$k]['addtime'] = time();
            }

            StoreGoodsItem::insert($storeGoodsItemData);
        } else {
            /** 补充数量 */
            $storeGoodsItemId = array_val('store_goods_item_id', $req);
            $storeGoodsItem = StoreGoodsItem::find($storeGoodsItemId);

            $storeInLogData['store_in_id'] = $storeInId;
            $storeInLogData['item_specifications'] = $storeGoodsItem->item_json;
            $storeInLogData['sku'] = $storeGoodsItem->sku;
            $storeInLogData['store_goods_id'] = $storeGoodsId;
            $storeInLogData['num'] = $req['num'];
            $storeInLogData['price'] = $price;
            $storeInLogData['addtime'] = time();
        }

        DB::table('store_in_log')->insert($storeInLogData);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 重新设置申请入库中的SKU数量
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRequesting(Request $request)
    {
        $return = array();

        $req = $request->only('sku', 'num', 'price');

        if (!array_member('sku', $req)) {
            $return['code'] = 'E100077';
            $return['message'] = __('lang.Please input item number.');
            return response()->json($return);
        }
        $num = array_val('num', $req);
        $price = array_val('price', $req);

        $updateData = array();
        if ($num) {
            $updateData['store_in_log.num'] = $num;
        }
        if ($price) {
            $updateData['store_in_log.price'] = $price;
        }

        if (!count($updateData)) {
            $return['code'] = 'E100078';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $res = DB::table('store_in_log')
            ->leftJoin('store_in', 'store_in_log.store_in_id', '=', 'store_in.id')
            ->where(['store_in_log.sku'=>$req['sku'], 'store_in.statu'=>'0'])
            ->update($updateData);

        if ($res === false) {
            $return['code'] = 'E303075';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 取消申请入库
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelRequesting(Request $request)
    {
        $return = array();

        $req = $request->only('sku');

        if (!array_member('sku', $req)) {
            $return['code'] = 'E100077';
            $return['message'] = __('lang.Please input item number.');
            return response()->json($return);
        }

        $res = DB::table('store_in_log')
            ->leftJoin('store_in', 'store_in_log.store_in_id', '=', 'store_in.id')
            ->where(['store_in_log.sku'=>$req['sku'], 'store_in.statu'=>'0'])
            ->delete();

        if ($res === false) {
            $return['code'] = 'E304075';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 获取库存商品资料
     * @param $code
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGoodsItems($code)
    {
        $return = array();

        $logisticIds = DB::table('user_logistic')->select(DB::raw('GROUP_CONCAT(logistic_id) AS logistic_ids'))->where('user_id', $this->userId)->first();

        if (!$logisticIds->logistic_ids) {
            $return['code'] = 'E201073';
            $return['message'] = __('lang.No logistics.');
            return response()->json($return);
        }

        $logisticIdArr = $logisticIds->logistic_ids ? explode(',', $logisticIds->logistic_ids) : [];
        $storeGoods = StoreGoods::with('storeGoodsItems')->where('code', $code)->whereIn('logistics_id', $logisticIdArr)->first();

        if (!$storeGoods) {
            $return['code'] = 'E301076';
            $return['message'] = 'no data.';
            return response()->json($return);
        }

        $result = array();
        $result['logistic_id'] = $storeGoods->logistics_id;
        $result['item_id'] = $storeGoods->code;
        $result['title'] = $storeGoods->name;
        $result['min_buy'] = 1;

        if ($storeGoods->storeGoodsItems) {
            $images = array();
            $skuGroup = array();
            foreach ($storeGoods->storeGoodsItems as $v) {
                if ($v->img_src) $images[] = $v->img_src;

                $sku = array();
                $sku['spec_id'] = $v->sku;
                $sku['price'] = 0;
                $sku['sku_image'] = $v->img_src;

                $itemJson = json_decode($v->item_json, true);
                $skuIndex = 0;
                foreach ($itemJson as $k1=>$v1) {
                    $sku['data'][$skuIndex] = [$k1=>$v1];
                    $skuIndex++;
                }

                $skuGroup[] = $sku;
            }

            $result['images'] = $images;
            $result['sku'] = $skuGroup;
        }

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>$result
        ]);
    }

    /**
     * 删除库存商品（软删）
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeGoodsDelete($id)
    {
        $return = array();

        $hasNum = StoreGoodsItem::where('store_goods_id', $id)->sum('num');
        if ($hasNum) {
            $return['code'] = 'E200079';
            $return['message'] = __('lang.Can not delete item.');
            return response()->json($return);
        }

        $res = StoreGoods::where('id', $id)->update(['statu'=>'1']);

        if ($res !== false) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        } else {
            $return['code'] = 'E303078';
            $return['message'] = __('lang.Data update failed.');
        }

        return response()->json($return);
    }

    /**
     * 库存商品添加到待出库清单
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addStoreOutList(Request $request)
    {
        $return = array();

        $req = $request->only('id');

        if (!$id = array_val('id', $req)) {
            $return['code'] = '100074';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $exists = DB::table('store_out_lists')->where(['user_id'=>$this->userId,'store_goods_item_id'=>$id])->count();
        if ($exists) {
            $return['code'] = '200072';
            $return['message'] = __('lang.The SKU has exists in out list.');
            return response()->json($return);
        }

        $res = DB::table('store_out_lists')->insert([
            'user_id'=>$this->userId,
            'store_goods_item_id'=>$id
        ]);

        if (!$res) {
            $return['code'] = '302078';
            $return['message'] = __('lang.Data create failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }


    /**
     * 获取申请出库中的商品
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOutRequesting()
    {
        $return = array();

        $outRequesting = StoreOut::select(['store_out.*', 'logistics.nickname'])
            ->with(['storeOutLog'=>function($query){
                $query->select(['store_out_log.*', 'store_goods_items.sku', 'store_goods_items.item_json', 'store_goods_items.img_src'])->leftJoin('store_goods_items', 'store_out_log.sku', '=', 'store_goods_items.sku');
            }])
            ->leftJoin('logistics', 'store_out.logistics_id', '=', 'logistics.id')
            ->where(['store_out.statu'=>'0', 'store_out.user_id'=>$this->userId])
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $outRequesting;

        return response()->json($return);
    }

    /**
     * 待出库清单列表
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOutList()
    {
        $return = array();

        $storeGoodsItemIds = DB::table('store_out_lists')->select(DB::raw('GROUP_CONCAT(store_goods_item_id) AS store_goods_item_ids'))->where('user_id', $this->userId)->groupBy('user_id')->first();

        if (!$storeGoodsItemIds) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = [];
            return response()->json($return);
        }

        $storeGoodsItemIdArr = explode(',', $storeGoodsItemIds->store_goods_item_ids);

        $storeOutList = StoreGoodsItem::whereIn('id', $storeGoodsItemIdArr)->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $storeOutList;

        return response()->json($return);
    }

    /**
     * 删除申请出库
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelOutRequesting($id)
    {
        $return = array();

        $isMine = StoreOut::where(['id'=>$id, 'user_id'=>$this->userId])->count();

        if (!$isMine) {
            $return['code'] = 'E200076';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $resLog = StoreOutLog::where('store_out_id', $id)->delete();
        if ($resLog === false) {
            $return['code'] = 'E304071';
            $return['message'] = __('lang.Error.');
            return response()->json($return);
        }

        $res = StoreOut::destroy($id);

        if ($res === false) {
            $return['code'] = 'E304072';
            $return['message'] = __('lang.Data delete failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 删除待出库清单
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteOutList($id)
    {
        $res = DB::table('store_out_lists')->where(['store_goods_item_id'=>$id, 'user_id'=>$this->userId])->delete();

        if ($res === false) {
            $return['code'] = 'E304077';
            $return['message'] = __('lang.Data delete failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 库存商品申请出库
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOutRequest(Request $request)
    {
        $return = array();

        $req = $request->only('items', 'name', 'tel', 'zipcode', 'address', 'user_explain');

        if (!$name = array_val('name', $req)) {
            $return['code'] = 'E100074';
            $return['message'] = __('lang.Please input receiver.');
            return response()->json($return);
        }
        if (!$tel = array_val('tel', $req)) {
            $return['code'] = 'E100075';
            $return['message'] = __('lang.Please input tel.');
            return response()->json($return);
        }
        if (!$zipcode = array_val('zipcode', $req)) {
            $return['code'] = 'E100076';
            $return['message'] = __('lang.Please input zipcode.');
            return response()->json($return);
        }
        if (!$address = array_val('address', $req)) {
            $return['code'] = 'E100077';
            $return['message'] = __('lang.Please input address.');
            return response()->json($return);
        }

        if (!array_member('items', $req) || !count($req['items'])) {
            $return['code'] = 'E100078';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $items = $req['items'];
        $storeGoodsItemIdNum = array();//id 和 num 映射
        $storeGoodsItemIdArr = array();
        foreach ($items as $v) {
            $storeGoodsItemIdNum[$v['store_goods_item_id']] = $v['num'];
            $storeGoodsItemIdArr[] = $v['store_goods_item_id'];
        }

        /** 获取所有库存商品的SKU */
        $storeGoodsItems = StoreGoodsItem::select(['store_goods_items.*', 'store_goods.id AS store_goods_id', 'store_goods.logistics_id'])->leftJoin('store_goods', 'store_goods_items.store_goods_id', '=', 'store_goods.id')->whereIn('store_goods_items.id', $storeGoodsItemIdArr)->where('user_id', $this->userId)->get();

        /** 根据物流商把商品分组 */
        $storeGoodsItemGroup = array();
        foreach ($storeGoodsItems as $k=>$v) {
            /** 验证数量是否充足 */
            if ($v->num < $storeGoodsItemIdNum[$v->id]) {
                $return['code'] = 'E201076';
                $return['message'] = __('lang.Item number is short.');
                return response()->json($return);
            }

            $storeGoodsItemGroup[$v->logistics_id][$k] = $v;
        }

        /** 开启事务 */
        DB::beginTransaction();

        foreach ($storeGoodsItemGroup as $k=>$v) {
            $createRes = StoreOut::create([
                'sn'=>uniqid('SN_' . $k. '_'),
                'user_id'=>$this->userId,
                'logistics_id'=>$k,
                'type'=>'1',
                'statu'=>'0',
                'name'=>$name,
                'tel'=>$tel,
                'zipcode'=>$tel,
                'address'=>$address,
                'user_explain'=>$req['user_explain'] ?? '',
                'addtime'=>time()
            ]);

            if (!$createRes) {
                DB::rollBack();
                $return['code'] = 'E302079';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            $storeOutLogInsert = array();
            $totalNum = 0;
            foreach ($v as $k1=>$v1) {
                $storeOutLogInsert[$k1]['store_out_id'] = $createRes->id;
                $storeOutLogInsert[$k1]['item_specifications'] = $v1->item_json;
                $storeOutLogInsert[$k1]['sku'] = $v1->sku;
                $storeOutLogInsert[$k1]['store_goods_id'] = $v1->store_goods_id;
                $storeOutLogInsert[$k1]['storehouse_id'] = $v1->store_house_id;
                $storeOutLogInsert[$k1]['storehouse_postion_id'] = $v1->store_house_postion_id;
                $storeOutLogInsert[$k1]['num'] = $storeGoodsItemIdNum[$v1->id];
                $storeOutLogInsert[$k1]['addtime'] = time();

                $totalNum += $storeGoodsItemIdNum[$v1->id];
            }

            $insertRes = StoreOutLog::insert($storeOutLogInsert);
            if (!$insertRes) {
                DB::rollBack();
                $return['code'] = 'E302079';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            $createRes->num = $totalNum;
            $createRes->save();
        }

        $resDel = DB::table('store_out_lists')->whereIn('store_goods_item_id', $storeGoodsItemIdArr)->where('user_id', $this->userId)->delete();
        if ($resDel === false) {
            DB::rollBack();
            $return['code'] = 'E302079';
            $return['message'] = __('lang.Error.');
            return response()->json($return);
        }

        DB::commit();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 根据sku获取库存商品资料
     * @param $sku
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGoodsItemBySku($sku)
    {
        $return = array();

        if (!$sku) {
            $return['code'] = 'E100070';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $item = StoreGoodsItem::select(['store_goods_items.*', 'store_goods.name'])
            ->leftJoin('store_goods', 'store_goods_items.store_goods_id', '=', 'store_goods.id')
            ->where('sku', $sku)
            ->first();

        if (!$item) {
            $return['code'] = 'E301074';
            $return['message'] = __('lang.Invalid SKU.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $item;
        }

        return response()->json($return);
    }

    /**
     * 转移库存
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeTransfer(Request $request)
    {
        $return = array();

        $req = $request->only('store_data');

        if (
            !array_key_exists('store_data', $req)
            || !is_array($req['store_data'])
            || !count($req['store_data'])
        ) {
            $return['code'] = 'E100072';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        DB::beginTransaction();
        foreach ($req['store_data'] as $v) {
            if (!$v['username']) {
                DB::rollBack();
                $return['code'] = 'E200073';
                $return['message'] = __('lang.Please input username.');
                return response()->json($return);
            }

            $userTo = User::where('username', $v['username'])->first();
            if (!$userTo) {
                DB::rollBack();
                $return['code'] = 'E200074';
                $return['message'] = __('lang.Invalid username.');
                return response()->json($return);
            }
            if ($userTo->parent_id) {
                DB::rollBack();
                $return['code'] = 'E200075';
                $return['message'] = __('lang.Invalid username.');
                return response()->json($return);
            }

            $item = StoreGoodsItem::select(['store_goods_items.*', 'store_goods.logistics_id'])
                ->leftJoin('store_goods', 'store_goods_items.store_goods_id', '=', 'store_goods.id')
                ->where('store_goods_items.id', $v['id'])
                ->first();
            if (!$item) {
                DB::rollBack();
                $return['code'] = 'E200076';
                $return['message'] = __('lang.Invalid username.');
                return response()->json($return);
            }
            if ($item->num < $v['transfer_num']) {
                DB::rollBack();
                $return['code'] = 'E200077';
                $return['message'] = __('lang.Item number is short.');
                return response()->json($return);
            }

            /** 检测接收库存的用户有没有对应物流商 */
            $userLogistic = DB::table('user_logistic')->where(['logistic_id'=>$item->logistics_id, 'user_id'=>$userTo->id])->first();
            if (!$userLogistic) {
                DB::rollBack();
                $return['code'] = 'E200078';
                $return['message'] = __('lang.Invalid logistic.');
                return response()->json($return);
            }

            /** 添加出库申请记录 */
            $createRes = StoreOut::create([
                'sn'=>uniqid('SN_' . $item->logistics_id . '_'),
                'user_id'=>$this->userId,
                'logistics_id'=>$item->logistics_id,
                'type'=>'1',
                'statu'=>'4',
                'name'=>$userTo->username,
                'user_explain'=>'Transfer to: ' . $userTo->username,
                'addtime'=>time()
            ]);
            if (!$createRes) {
                DB::rollBack();
                $return['code'] = 'E302079';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            $resOutLog = StoreOutLog::insert([
                'store_out_id'=>$createRes->id,
                'item_specifications'=>$item->item_json,
                'sku'=>$item->sku,
                'store_goods_id'=>$item->store_goods_id,
                'storehouse_id'=>$item->store_house_id,
                'storehouse_postion_id'=>$item->store_house_postion_id,
                'num'=>$v['transfer_num'],
                'addtime'=>time(),
            ]);
            if (!$resOutLog) {
                DB::rollBack();
                $return['code'] = 'E302078';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            /** 出库后减库存 */
            $item->num = $item->num - $v['transfer_num'];
            $desNum = $item->save();
            if ($desNum === false) {
                DB::rollBack();
                $return['code'] = 'E302077';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            /** 查看接收库存的用户之前有没有接收的记录，如果有，则只加数量即可 */
            $storeGoods = StoreGoods::find($item->store_goods_id);
            $storeGoodsItemCompareId = $item->parent_id ?: $item->id;
            $storeGoodsCompareId = $storeGoods->parent_id ?: $storeGoods->id;
            $receivedItem = StoreGoodsItem::select(['store_goods_items.*'])
                ->leftJoin('store_goods', 'store_goods_items.store_goods_id', '=', 'store_goods.id')
                ->where([
                    'store_goods_items.parent_id'=>$storeGoodsItemCompareId,
                    'store_goods.parent_id'=>$storeGoodsCompareId,
                    'store_goods.user_id'=>$userTo->id
                ])
                ->first();

            if (!$receivedItem) {
                $storeGoodsRes = StoreGoods::create([
                    'parent_id'=>$storeGoods->parent_id ?: $storeGoods->id,
                    'code'=>strtoupper(uniqid('sg_')),
                    'logistics_id'=>$storeGoods->logistics_id,
                    'user_id'=>$userTo->id,
                    'statu'=>'0',
                    'type'=>'0',
                    'name'=>$storeGoods->name,
                    'img_src'=>$storeGoods->img_src,
                    'img_name'=>$storeGoods->img_name,
                    'goods_width'=>$storeGoods->goods_width,
                    'goods_height	'=>$storeGoods->goods_height,
                    'goods_length	'=>$storeGoods->goods_length,
                    'goods_quality	'=>$storeGoods->goods_quality,
                    'extend_json	'=>$storeGoods->extend_json,
                    'addtime'=>time(),
                ]);
                if (!$storeGoodsRes) {
                    DB::rollBack();
                    $return['code'] = 'E302076';
                    $return['message'] = __('lang.Error.');
                    return response()->json($return);
                }

                $receivedItem = StoreGoodsItem::create([
                    'parent_id'=>$item->parent_id ?: $item->id,
                    'sku'=>strtoupper(uniqid()),
                    'size'=>$item->size,
                    'store_goods_id'=>$storeGoodsRes->id,
                    'store_house_id'=>$item->store_house_id,
                    'store_house_postion_id'=>$item->store_house_postion_id,
                    'num'=>'0',//记录入库申请后再添加数量
                    'price'=>$item->price,
                    'item_json'=>$item->item_json,
                    'addtime'=>time(),
                    'img_src'=>$item->img_src,
                    'private_sku'=>$item->private_sku,
                    'item_extend_json'=>$item->item_extend_json,
                ]);
                if (!$receivedItem) {
                    DB::rollBack();
                    $return['code'] = 'E302075';
                    $return['message'] = __('lang.Error.');
                    return response()->json($return);
                }
            }

            /** 添加入库申请记录 */
            $userFrom = User::find($this->userId);
            $storeInId = DB::table('store_in')->insertGetId([
                'sn'=>uniqid('SN_' . $item->logistics_id. '_'),
                'logistics_id'=>$item->logistics_id,
                'user_id'=>$userTo->id,
                'waybill_number'=>'',
                'num'=>$v['transfer_num'],
                'statu'=>'1',
                'user_explain'=>'User from ' . $userFrom->username,
                'addtime'=>time()
            ]);
            if (!$storeInId) {
                DB::rollBack();
                $return['code'] = 'E302074';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            $resInLog = DB::table('store_in_log')->insert([
                'store_in_id'=>$storeInId,
                'item_specifications'=>$item->item_json,
                'sku'=>$item->sku,
                'store_goods_id'=>$receivedItem->store_goods_id,
                'num'=>$v['transfer_num'],
                'price'=>$item->price,
                'num_true'=>$v['transfer_num'],
                'addtime'=>$storeInId,
            ]);
            if (!$resInLog) {
                DB::rollBack();
                $return['code'] = 'E302073';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            /** 补充数量 */
            $receivedItem->num = $receivedItem->num + $v['transfer_num'];
            $storeInRes = $receivedItem->save();

            if (!$storeInRes) {
                DB::rollBack();
                $return['code'] = 'E303077';
                $return['message'] = __('lang.Error.');
                return response()->json($return);
            }

            $storeGoods->parent_id = $storeGoodsCompareId;
            $item->parent_id = $storeGoodsItemCompareId;
            $storeGoods->save();
            $item->save();
        }

        DB::commit();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $req;

        return response()->json($return);
    }

    /**
     * 申请入库日志
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeInLogs(Request $request)
    {
        $return = array();
        $req = $request->only('keyword', 'page', 'page_size');

        /** 查询数据库返回结果 */
        $pageSize = $req['page_size'] ?? 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $pageSize;

        $logInstance = DB::table('store_in_log')
            ->leftJoin('store_in', 'store_in_log.store_in_id', '=', 'store_in.id')
            ->leftJoin('store_goods', 'store_in_log.store_goods_id', '=', 'store_goods.id')
            ->where('store_in.user_id', $this->userId)
            ->where(function($query) use($req) {
                if (array_key_exists('keyword', $req) && $req['keyword']) {
                    $query->orWhereRaw("locate('{$req['keyword']}', `ksn_store_goods`.`name`) > 0");
                    $query->orWhereRaw("locate('{$req['keyword']}', `ksn_store_in_log`.`sku`) > 0");
                }
            });

        $count = $logInstance->count();//用于翻页
        $logs = $logInstance->select(['store_in_log.*', 'store_goods.img_src', 'store_goods.name', 'store_in.statu', 'store_in.logistics_explain', 'store_in.user_explain'])->offset($offset)->limit($pageSize)->orderBy('store_in_log.id', 'DESC')->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['count'] = $count;
        $return['result']['logs'] = $logs;

        return response()->json($return);
    }

    /**
     * 申请出库日志
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOutLogs(Request $request)
    {
        $return = array();
        $req = $request->only('keyword', 'page', 'page_size');

        /** 查询数据库返回结果 */
        $pageSize = $req['page_size'] ?? 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $pageSize;

        $logInstance = StoreOutLog::leftJoin('store_out', 'store_out_log.store_out_id', '=', 'store_out.id')
            ->leftJoin('store_goods', 'store_out_log.store_goods_id', '=', 'store_goods.id')
            ->where('store_out.user_id', $this->userId)
            ->where(function($query) use($req) {
                if (array_key_exists('keyword', $req) && $req['keyword']) {
                    $query->orWhereRaw("locate('{$req['keyword']}', `ksn_store_goods`.`name`) > 0");
                    $query->orWhereRaw("locate('{$req['keyword']}', `ksn_store_out_log`.`sku`) > 0");
                }
            });

        $count = $logInstance->count();//用于翻页
        $logs = $logInstance
            ->select([
                'store_out_log.*',
                'store_goods.img_src',
                'store_goods.name AS goods_name',
                'store_out.statu',
                'store_out.do_explain',
                'store_out.user_explain',
                'store_out.name',
                'store_out.tel',
                'store_out.zipcode',
                'store_out.address',
                'store_out.klo_logistics_code',
                'store_out.klo_logistics_name',
                'store_out.klo_logistics_money',
            ])
            ->offset($offset)
            ->limit($pageSize)
            ->orderBy('store_out_log.id', 'DESC')
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['count'] = $count;
        $return['result']['logs'] = $logs;

        return response()->json($return);
    }

    /**
     * 生成SKU组合
     * @param $properties
     * @return array
     */
    private function generateSku($properties) {
        $result = [];
        $propertyNames = array_keys($properties);
        $this->generateCombination(
            $propertyNames,
            $properties,
            [],
            0,
            $result
        );

        return $result;
    }

    /**
     * 循环递归组合SKU
     * @param $propertyNames
     * @param $properties
     * @param $combination
     * @param $index
     * @param $result
     */
    private function generateCombination($propertyNames, $properties, $combination, $index, &$result) {
        if ($index === count($propertyNames)) {
            $result[] = $combination;
        } else {
            $propertyName = $propertyNames[$index];
            $values = $properties[$propertyName];
            foreach ($values as $value) {
                $this->generateCombination(
                    $propertyNames,
                    $properties,
                    array_merge($combination, [$propertyName => $value]),
                    $index + 1,
                    $result
                );
            }
        }
    }
}
