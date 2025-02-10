<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopController extends Controller
{
    /**
     * 获取店铺列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('market', 'shop_name', 'shop_id');

        $shops = Shop::whereIn('id', Shop::getUserShop())->where(function($query) use($req){
            /** 按条件筛选店铺 */
            if (array_key_exists('market', $req)) {
                $query->where('market', $req['market']);
            }
            if ($shopName = array_val('shop_name', $req)) {
                $query->whereRaw("locate('{$shopName}', `shop_name`) > 0");
            }
            if ($shopId = array_val('shop_id', $req)) {
                $query->whereRaw("locate('{$shopId}', `shop_id`) > 0");
            }
        })->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $shops;

        return response()->json($return);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }


    public function store(Request $request)
    {
        $return = array();

        $req = $request->all();

        if (!array_val('market', $req)) {
            $return['code'] = 'E100021';
            $return['message'] = __('lang.Please select shop type.');
            return response()->json($return);
        }
        if (!array_val('shop_name', $req)) {
            $return['code'] = 'E100022';
            $return['message'] = __('lang.Please input shop name.');
            return response()->json($return);
        }
        if (!array_val('shop_id', $req)) {
            $return['code'] = 'E100023';
            $return['message'] = __('lang.Please input shop ID.');
            return response()->json($return);
        }
        if (!array_val('app_key', $req)) {
            $return['code'] = 'E100024';
            $return['message'] = __('lang.Please input APP_KEY.');
            return response()->json($return);
        }

        $exists = Shop::where('shop_id', $req['shop_id'])->count();
        if ($exists) {
            $return['code'] = 'E200021';
            $return['message'] = __('lang.Shop ID has exists.');
            return response()->json($return);
        }

        /** 店铺所属的管理员id */
        $payload = getTokenPayload();
        $req['user_id'] = $payload->aud ?: $payload->jti;

        /** $req中剔除值为null的项 */
        foreach ($req as $k=>$v) {
            if (is_null($v)) unset($req[$k]);
        }

        $req['create_time'] = date('Y-m-d H:i:s');
        $shop = Shop::create($req);

        if (!$shop) {
            $return['code'] = 'E302021';
            $return['message'] = __('lang.Data create failed.');
            return response()->json($return);
        }

        /** 创建店铺token */
//        $token = Agent::Markets($shop)->setClass('Auth')->getToken();
//        if (!$token['code']) {
//            $shop->token = $token['result']['token'];
//            $shop->refresh_token = $token['result']['refresh_token'];
//            $shop->save();
//        }

        /** 绑定店铺邮件模板 */
        if (array_key_exists('email_templates', $req) && count($req['email_templates'])) {
            $emailTemplateInsert = array();
            foreach ($req['email_templates'] as $k=>$v) {
                $emailTemplateInsert[$k]['shop_id'] = $shop->id;
                $emailTemplateInsert[$k]['email_template_id'] = $v;
            }
            DB::table('shop_email_template')->insert($emailTemplateInsert);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $shop;

        return response()->json($return);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $return = array();

        $req = $request->except('url');

        if (!$market = array_val('market', $req)) {
            $return['code'] = 'E100021';
            $return['message'] = __('lang.Please select shop type.');
            return response()->json($return);
        }
        if (!array_val('shop_name', $req)) {
            $return['code'] = 'E100022';
            $return['message'] = __('lang.Please input shop name.');
            return response()->json($return);
        }
        if (!array_val('shop_id', $req)) {
            $return['code'] = 'E100023';
            $return['message'] = __('lang.Please input shop ID.');
            return response()->json($return);
        }
        if (!array_val('app_key', $req)) {
            $return['code'] = 'E100024';
            $return['message'] = __('lang.Please input APP_KEY.');
            return response()->json($return);
        }

        $exists = Shop::where([['id', '!=', $id], ['shop_id', $req['shop_id']]])->count();
        if ($exists) {
            $return['code'] = 'E200021';
            $return['message'] = __('lang.Shop ID has exists.');
            return response()->json($return);
        }

        /** 变量中保存店铺邮件模板id，并删除这个字段（否则影响数据更新） */
        $emailTemplates = array_key_exists('email_templates', $req) && count($req['email_templates']) ? $req['email_templates'] : [];
        unset($req['email_templates']);

        $res = Shop::whereIn('id', Shop::getUserShop())->where('id', $id)->update($req);
        if ($res === false) {
            $return['code'] = 'E303021';
            $return['message'] = __('lang.Data update failed.');
            return response()->json($return);
        }

        /** 重新获取店铺token */
        $shop = Shop::find($id);
//        $token = Agent::Markets($shop)->setClass('Auth')->getToken();
//        if (!$token['code']) {
//            $shop->token = $token['result']['token'];
//            $shop->refresh_token = $token['result']['refresh_token'];
//            $shop->save();
//        }

        /** 绑定店铺邮件模板 */
        if (count($emailTemplates)) {
            DB::table('shop_email_template')->where('shop_id', $id)->delete();

            $emailTemplateInsert = array();
            foreach ($emailTemplates as $k=>$v) {
                $emailTemplateInsert[$k]['shop_id'] = $shop->id;
                $emailTemplateInsert[$k]['email_template_id'] = $v;
            }
            DB::table('shop_email_template')->insert($emailTemplateInsert);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $res;

        return response()->json($return);
    }

    /**
     * 删除店铺
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $return = array();

        $res = Shop::whereIn('id', Shop::getUserShop())->where('id', $id)->delete();

        if ($res === false) {
            $return['code'] = 'E304021';
            $return['message'] = __('lang.Data delete failed.');
            return response()->json($return);
        }

        /** 删除被用户授权的店铺 */
        DB::table('user_shop')->where('shop_id', $id)->delete();

        /** 删除店铺关联的邮件模板 */
        DB::table('shop_email_template')->where('shop_id', $id)->delete();

        /** 删除店铺的订单 */
        Order::where('shop_id', $id)->delete();

        /** 删除店铺的订单商品 */
        DB::table('order_items')->where('shop_id', $id)->delete();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 查看店铺联动状态
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function checkConnection($id)
    {
        $return = array();

        $shop = Shop::whereIn('id', Shop::getUserShop())->find($id);

        if (!$shop) {
            $return['code'] = 'E201021';
            $return['message'] = __('lang.Invalid shop info.');
            return response()->json($return);
        }
        if (!$shop->token) {
            $return['code'] = 'E201022';
            $return['message'] = 'no token data';
            return response()->json($return);
        }

//        $result = Agent::Markets($shop)->setClass('Auth')->checkConnection();
        $result = [
            'code'=>'',
            'message'=>'SUCCESS',
        ];

        /** 联动成功直接返回结果 */
        if ($result['code'] == '') {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
        } else {
            $return['code'] = 'E501021';
            $return['message'] = $result['message'];
        }

        return response()->json($return);
    }

    /**
     * 获取当前用户所有店铺的联动状态
     * @return \Illuminate\Http\JsonResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function checkAllConnection()
    {
        $return = array();

        $shops = Shop::whereIn('id', Shop::getUserShop())->get();

        if (!count($shops)) {
            $return['code'] = 'E201021';
            $return['message'] = 'no shop data.';
            return response()->json($return);
        }

        $result = array();
        foreach ($shops as $v) {
            if (!$v->token) continue;

//            $res = Agent::Markets($v)->setClass('Auth')->checkConnection();
            $res = [
                'code'=>'',
                'message'=>'SUCCESS'
            ];

            $result[$v->id] = $res['code'] == '' ? 'success' : 'failed';
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $result;

        return response()->json($return);
    }
}
