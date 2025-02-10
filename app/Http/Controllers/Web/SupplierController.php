<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SupplierInfo;
use App\Services\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    /**
     * 通过用户的采购平台（1688，淘宝）信息，请求 token
     * @param Request $request
     * @param String $key 用户采购平台信息加密字符串
     * @return mixed
     */
    public function auth(Request $request, $key)
    {
        $supplierEncrypted = dec($key, Config::get('supplier.enc_key'));
        $_supplier = json_decode($supplierEncrypted);

        if (!$_supplier) {
            $msg = '采购用户解析错误';
            return view('supplier.auth', compact('msg'));
        }

        $supplier = SupplierInfo::where(['user_id'=>$_supplier->user_id, 'market'=>$_supplier->market])->first();
        $token = Agent::Supplier($supplier)->setClass('Auth')->getToken();

        /** 直接获取 token，更新数据库 */
        if (!$token['code']) {}

        /** 获取重定向 url */
        if ($token['code'] == 'E301') {
            return redirect($token['result']['url']);
        }
    }

    /**
     * 通过 code 换取 access_token
     * @param Request $request
     * @return mixed
     */
    public function redirect(Request $request)
    {
        $req = $request->only('state', 'code');

        if (!$state = array_val('state', $req)) {
            $msg = '请求参数错误: state';
            return view('supplier.auth', compact('msg'));
        }
        if (!$state = array_val('code', $req)) {
            $msg = '请求参数错误: code';
            return view('supplier.auth', compact('msg'));
        }

        $supplierEncrypted = dec($req['state'], Config::get('supplier.enc_key'));
        $supplier = json_decode($supplierEncrypted);

        if (!$supplier) {
            $msg = '采购用户解析错误';
            return view('supplier.auth', compact('msg'));
        }

        $supplier = SupplierInfo::where(['user_id'=>$supplier->user_id, 'market'=>$supplier->market])->first();
        $token = Agent::Supplier($supplier)->setClass('Auth')->getToken();

        if ($token['code']) {
            $msg = $token['message'];
            return view('supplier.auth', compact('msg'));
        }

        $res = SupplierInfo::where('id', $supplier->id)->update($token['result']);
        if ($res === false) {
            $msg = '获取 token 失败，请稍后再试';
        } else {
            $msg = '获取 token 成功';
        }

        return view('supplier.auth', compact('msg'));
    }

    /**
     * 阿里巴巴回调地址
     * @param Request $request
     */
    public function notify(Request $request)
    {
        Log::info($request);
    }
}
