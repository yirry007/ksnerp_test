<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\OrderItemMore;
use App\Models\Shop;
use App\Models\SupplierInfo;
use App\Services\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    /**
     * 当前登录用户授权的所有采购平台
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSupplierInfos()
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $supplierInfos = SupplierInfo::select(['market', 'market_id', 'account', 'username', 'token', 'refresh_token', 'create_time', 'update_time'])->where('user_id', $user_id)->get();

        $infoByMarket = array();
        foreach ($supplierInfos as $v) {
            $infoByMarket[$v->market] = $v;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $infoByMarket;

        return response()->json($return);
    }

    /**
     * 获取加密后的采购用户信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSupplierEncrypted(Request $request)
    {
        $return = array();

        $_supplier = SupplierInfo::getInstance();
        $supplier = [
            'user_id'=>$_supplier->user_id,
            'market'=>$_supplier->market,
            'url'=>_url_('/')
        ];
        $supplierEncrypted = enc(json_encode($supplier), Config::get('supplier.enc_key'));

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = urlencode($supplierEncrypted);

        return response()->json($return);
    }

    /**
     * 账号密码授权供应商平台
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function supplierAccountAuth(Request $request)
    {
        $return = array();

        $req = $request->only('market', 'account', 'pwd');

        if (!$market = array_val('market', $req)) {
            $return['code'] = 'E100073';
            $return['message'] = __('lang.Invalid market.');
            return response()->json($return);
        }
        if (!$account = array_val('account', $req)) {
            $return['code'] = 'E100074';
            $return['message'] = __('lang.Please input username.');
            return response()->json($return);
        }
        if (!$pwd = array_val('pwd', $req)) {
            $return['code'] = 'E100075';
            $return['message'] = __('lang.Please input password.');
            return response()->json($return);
        }

        $supplierConfig = Config::get('supplier');
        if (!array_key_exists($market, $supplierConfig)) {
            $return['code'] = 'E100076';
            $return['message'] = __('lang.Invalid market.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $supplier = SupplierInfo::where(['user_id'=>$user_id, 'market'=>ucfirst($market)])->first();

        /** 数据库里没有采购用户信息，则新创建一个 */
        if (!$supplier) {
            $supplier = SupplierInfo::create([
                'user_id'       => $user_id,
                'app_key'       => $account,
                'app_secret'    => $pwd,
                'market'        => ucfirst($market),
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            $supplier->app_key = $account;
            $supplier->app_secret = $pwd;
            $supplier->save();
        }

        $res = Agent::Supplier($supplier)->setClass('Auth')->getToken();

        if ($res['code']) {
            $return['code'] = $res['code'];
            $return['message'] = $res['message'];
            return response()->json($return);
        }

        $supplier->token = $res['result']['token'];
        $supplier->update_time = date('Y-m-d H:i:s');
        $result = $supplier->save();
        if (!$result) {
            $return['code'] = 'E303071';
            $return['message'] = __('lang.Token refresh failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 取消授权
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function supplierCancelAuth(Request $request)
    {
        $return = array();

        $req = $request->only('market');

        if (!$market = array_val('market', $req)) {
            $return['code'] = 'E100071';
            $return['message'] = __('lang.Invalid market.');
            return response()->json($return);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $res = SupplierInfo::where(['user_id'=>$user_id, 'market'=>ucfirst($market)])->update([
            'market_id'=>'',
            'account'=>'',
            'username'=>'',
            'token'=>'',
            'refresh_token'=>'',
            'update_time'=>date('Y-m-d H:i:s')
        ]);

        if ($res !== false) {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        } else {
            $return['code'] = 'E303072';
            $return['message'] = __('lang.Data update failed.');
        }

        return response()->json($return);
    }

    /**
     * 更新用户采购第三方平台信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSupplierInfo(Request $request)
    {
        $return = array();

        $req = $request->all();

        if (!$userId = array_val('user_id', $req)) {
            $return['code'] = 'E100077';
            $return['message'] = __('lang.User Auth failed.');
            return response()->json($return);
        }
        if (!$market = array_val('market', $req)) {
            $return['code'] = 'E100078';
            $return['message'] = __('lang.Invalid market.');
            return response()->json($return);
        }

        $res = SupplierInfo::where(['user_id'=>$userId, 'market'=>$market])->update([
            'market_id' => $req['market_id'] ?? '',
            'account' => $req['account'] ?? '',
            'username' => $req['username'] ?? '',
            'token' => $req['token'] ?? '',
            'refresh_token' => $req['refresh_token'] ?? '',
            'update_time' => $req['update_time'] ?? '',
        ]);

        if ($res === false) {
            $return['code'] = 'E303079';
            $return['message'] = __('lang.Data update failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        }

        return response()->json($return);
    }

    /**
     * 获取采购商品信息
     * @param $item_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItem($item_id)
    {
        $supplier = SupplierInfo::getInstance();
        $res = Agent::Supplier($supplier)->setClass('Item')->getItem($item_id);

        return response()->json($res);
    }

    /**
     * 获取平台收获地址
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAddress()
    {
        $return = array();

        $supplier = SupplierInfo::getInstance();
        $res = Agent::Supplier($supplier)->setClass('Trade')->getAddress();

        $return['code'] = $res['code'];
        $return['message'] = $res['message'];
        if (array_key_exists('result', $res)) {
            $return['result'] = $res['result'];
        }

        return response()->json($return);
    }

    /**
     * 获取自动生成订单的商品列表以及他的库存
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderItems(Request $request)
    {
        $return = array();

        $req = $request->only('market');

        $supplyMarket = Config::get('supplier')[$req['market']]['name'];

        $orderItems = OrderItem::with('orderItemMores')
            ->select(DB::raw('
                ksn_order_items.*,
                ksn_order_items.ksn_code as `key`,
                SUM(ksn_order_items.quantity) AS `total_quantity`,
                GROUP_CONCAT(ksn_order_items.id) AS `order_item_ids`,
                ksn_orders.full_address,
                ksn_orders.name_1,
                ksn_orders.name_2,
                ksn_orders.phone_1,
                ksn_orders.phone_2,
                ksn_orders.zipcode
            '))
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where([
                'orders.order_status'=>'2',
                'order_items.supply_market'=>$supplyMarket,
                'order_items.supply_order_id'=>'',
            ])
            ->whereIn('order_items.shop_id', Shop::getUserShop())
            ->groupBy('order_items.item_id', 'order_items.item_options')
            ->orderBy('order_items.id', 'DESC')
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $orderItems;

        return response()->json($return);
    }

    /**
     * 根据订单商品的映射关系，在采购平台中自动生成订单
     * @return \Illuminate\Http\JsonResponse
     */
    public function createOrders(Request $request)
    {
        set_time_limit(0);

        $return = array();

        $req = $request->only('address', 'items');

        if (!array_key_exists('address', $req)) {
            $return['code'] = 'E100071';
            $return['message'] = __('lang.Invalid address.');
            return response()->json($return);
        }

        if (!array_key_exists('items', $req)) {
            $return['code'] = 'E100072';
            $return['message'] = __('lang.Invalid item.');
            return response()->json($return);
        }

        $result = array();
        $shopIds = Shop::getUserShop();
        $supplier = SupplierInfo::getInstance();
        foreach ($req['items'] as $k=>$v) {
            $res = Agent::Supplier($supplier)->setClass('Trade')->createOrder([
                'address'=>$req['address'],
                'item'=>$v
            ]);

            if (!$res['code']) {
                /** 更新已采购的订单商品（主商品） */
                OrderItem::leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where([
                        'orders.order_status'=>'2',
                        'order_items.ksn_code'=>$k,
                        'order_items.auto_complete'=>'0',
                        'order_items.supply_order_id'=>''
                    ])
                    ->whereIn('order_items.shop_id', $shopIds)
                    ->update([
                        'supply_order_id'=>$res['result']['orderId'],
                        'supply_price'=>$res['result']['totalSuccessAmount'] / 100,
                        'supply_quantity'=>DB::raw('ksn_order_items.quantity * ksn_order_items.supply_unit')
                    ]);

                /** 创建附加采购商品订单，并更新本地数据表 */
                $orderItemIdArr = explode(',', $v['order_item_ids']);
                $orderItemMores = OrderItemMore::select(DB::raw('*, GROUP_CONCAT(id) AS order_item_more_ids'))
                    ->where([
                        'supply_market'=>$v['supply_market'],
                        'supply_order_id'=>''
                    ])
                    ->whereIn('order_item_id', $orderItemIdArr)
                    ->groupBy('supply_opt')
                    ->get()->toArray();

                $res['result']['order_item_mores'] = array();
                foreach ($orderItemMores as $k1=>$v1) {
                    $res1 = Agent::Supplier($supplier)->setClass('Trade')->createOrder([
                        'address'=>$req['address'],
                        'item'=>[
                            'supply_code'=>$v1['supply_code'],
                            'supply_opt'=>$v1['supply_opt'],
                            'total_quantity'=>$v['total_quantity'],
                            'supply_unit'=>$v1['supply_unit'],
                            'name'=>$v['name'] ?? '',
                            'phone'=>$v['phone'] ?? '',
                            'address'=>$v['address'] ?? '',
                            'zipcode'=>$v['zipcode'] ?? '',
                        ]
                    ]);

                    if (!$res1['code']) {
                        $orderItemMoreIdArr = explode(',', $v1['order_item_more_ids']);
                        OrderItemMore::leftJoin('order_items', 'order_item_mores.order_item_id' , '=', 'order_items.id')
                            ->whereIn('order_item_mores.id', $orderItemMoreIdArr)
                            ->update([
                                'order_item_mores.supply_order_id'=>$res1['result']['orderId'],
                                'order_item_mores.supply_price'=>$res1['result']['totalSuccessAmount'] / 100,
                                'order_item_mores.supply_quantity'=>DB::raw('ksn_order_items.quantity * ksn_order_item_mores.supply_unit')
                            ]);
                    }

                    unset($v1['order_item_more_ids']);
                    $_result1 = $res1['result'] ?? [];
                    $res1['result'] = array_merge($_result1, $v1);

                    $res['result']['order_item_mores'][$k1] = $res1;
                }
            }

            unset($v['order_item_ids']);
            $_result = $res['result'] ?? [];
            $res['result'] = array_merge($_result, $v);

            $result[] = $res;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $result;

        return response()->json($return);
    }
}
