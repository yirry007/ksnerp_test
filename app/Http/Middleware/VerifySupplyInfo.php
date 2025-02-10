<?php

namespace App\Http\Middleware;

use App\Models\SupplierInfo;
use App\Services\Agent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class VerifySupplyInfo
{
    /**
     * 判断用户是否绑定了采购平台应用的信息
     * @param Request $request
     * @param Closure $next
     * @return \Illuminate\Http\JsonResponse|mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $req = $request->only('market');
        if (!array_key_exists('market', $req) || !$req['market']) {
            return response()->json([
                'code'=>'E207011',
                'message'=>__('lang.Invalid market.'),
            ]);
        }

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;
        $market = $req['market'];
        $supplierConfig = Config::get('supplier');

        if (!array_key_exists($market, $supplierConfig)) {
            return response()->json([
                'code'=>'E207012',
                'message'=>__('lang.Invalid market.'),
            ]);
        }

        $supplierInfo = SupplierInfo::where(['user_id'=>$user_id, 'market'=>ucfirst($market)])->first();

        /** 数据库里没有采购用户信息，则新创建一个 */
        if (!$supplierInfo) {
            $supplierInfo = SupplierInfo::create([
                'user_id'       => $user_id,
                'app_key'       => $supplierConfig[strtolower($market)]['app_key'],
                'app_secret'    => $supplierConfig[strtolower($market)]['app_secret'],
                'market'        => ucfirst($market),
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        }

        /** 没有 token 信息， */
        if (!$supplierInfo->token && !$supplierInfo->refresh_token) {
            $result = Agent::Supplier($supplierInfo)->setClass('Auth')->getToken();
            if ($result['code'])
                return response()->json($result);
        }

        return $next($request);
    }
}
