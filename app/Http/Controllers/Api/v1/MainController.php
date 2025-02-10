<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MainController extends Controller
{
    /**
     * 当前系统版本以及最新系统版本（确认是否要更新）
     * @return \Illuminate\Http\JsonResponse
     */
    public function versionInfo()
    {
        $return = array();

        /** 当前用户服务器系统版本 */
        $payload = getTokenPayload();
        $userId = $payload->aud ?: $payload->jti;
        $user = User::find($userId);
        $currentVersion = DB::table('versions')->where('private_ip', $user->private_ip)->first();

        if (!$currentVersion) {
            $return['code'] = 'E209010';
            $return['message'] = __('lang.Invalid server info.');
            return response()->json($return);
        }

        /** 后台最新版本 */
        $lastVersion = DB::table('update_logs')->select(['version', 'update_time'])->orderBy('id', 'DESC')->first();

        /** 是否需要更新系统 */
        $updatable = preg_replace('/\D/', '', $lastVersion->version) > preg_replace('/\D/', '', $currentVersion->version);

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['current_version'] = $currentVersion->version;
        $return['result']['last_version'] = $lastVersion->version;
        $return['result']['current_update_time'] = explode(' ', $currentVersion->update_time)[0];
        $return['result']['last_update_time'] = explode(' ', $lastVersion->update_time)[0];
        $return['result']['updatable'] = $updatable;

        return response()->json($return);
    }

    /**
     * ERP版本更新
     * @return \Illuminate\Http\JsonResponse
     */
    public function versionUpdate()
    {
        $url = _url_('');

        if (strpos($url, '//alpha.') !== false) {
            $branch = 'alpha';
        } elseif (strpos($url, '//beta.') !== false) {
            $branch = 'beta';
        } else {
            $branch = 'release';
        }

        $command = 'cd /var/www/html/ksnerp_seller';
        $command .= ' && sudo git reset --hard HEAD';
        $command .= ' && sudo git checkout ' . $branch;
        $command .= ' && sudo git pull';
        $command .= ' && sudo chmod -R 777 /var/www/html/ksnerp_seller';

        exec($command);

        /** 获取当前用户 */
        $payload = getTokenPayload();
        $userId = $payload->aud ?: $payload->jti;
        $user = User::find($userId);

        /** 后台最新版本 */
        $lastVersion = DB::table('update_logs')->select(['version'])->orderBy('id', 'DESC')->first();

        /** 更新数据库中的用户的 ERP 版本信息 */
        DB::table('versions')->where('private_ip', $user->private_ip)->update([
            'version'=>$lastVersion->version,
            'update_time'=>date('Y-m-d H:i:s')
        ]);

        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS'
        ]);
    }

    /**
     * 获取版本更新日志
     * @return \Illuminate\Http\JsonResponse
     */
    public function versionUpdateLogs()
    {
        $return = array();

        $logs = DB::table('update_logs')->orderBy('id', 'DESC')->limit(10)->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $logs;

        return response()->json($return);
    }

    /**
     * 获取近一个月的日期以及它的订单数量
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderNumLastMonth()
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $period = 29;
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$period} days", time()));

        $orders = Order::select(['orders.id', 'orders.order_time'])->leftJoin('shops', 'orders.shop_id', '=', 'shops.id')->where(['shops.user_id'=>$user_id, ['orders.order_time', '>=', $startDate]])->orderBy('orders.order_time', 'ASC')->get();

        $dates = array();
        $orderNums = array();

        for ($i=$period;$i>=0;$i--) {
            $_day = date('Y-m-d', strtotime("-{$i} days", time()));

            /** 近一个月的日期列表 */
            $dates[] = $_day;

            /** 初始化近一个月订单数量（全部设置为 0） */
            $orderNums[$_day] = 0;
        }

        foreach ($orders as $v) {
            $orderTime = strtotime($v->order_time);

            for ($i=$period;$i>=0;$i--) {
                /** $i 天前当天开始时间戳 */
                $dayStart = strtotime(date('Y-m-d 00:00:00', strtotime("-{$i} days", time())));
                /** $i 天前当天结束时间戳 */
                $dayEnd = strtotime(date('Y-m-d 23:59:59', strtotime("-{$i} days", time())));
                /** $i 天前日期 */
                $_date = date('Y-m-d', strtotime("-{$i} days", time()));

                /** 有属于该日期的订单，则数量 +1 */
                if ($orderTime >= $dayStart && $orderTime <= $dayEnd) {
                    $orderNums[$_date]++;
                }
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['dates'] = $dates;
        $return['result']['order_numbers'] = array_values($orderNums);
        $return['result']['order_numbers_with_dates'] = $orderNums;

        return response()->json($return);
    }

    /**
     * 获取近一个月的日期以及它的订单金额
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderPriceLastMonth()
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $period = 29;
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$period} days", time()));

        $orders = Order::select(['orders.id', 'orders.order_time', 'orders.total_price'])->leftJoin('shops', 'orders.shop_id', '=', 'shops.id')->where(['shops.user_id'=>$user_id, ['orders.order_time', '>=', $startDate]])->orderBy('orders.order_time', 'ASC')->get();

        $dates = array();
        $orderNums = array();

        for ($i=$period;$i>=0;$i--) {
            $_day = date('Y-m-d', strtotime("-{$i} days", time()));

            /** 近一个月的日期列表 */
            $dates[] = $_day;

            /** 初始化近一个月订单数量（全部设置为 0） */
            $orderNums[$_day] = 0;
        }

        foreach ($orders as $v) {
            $orderTime = strtotime($v->order_time);

            for ($i=$period;$i>=0;$i--) {
                /** $i 天前当天开始时间戳 */
                $dayStart = strtotime(date('Y-m-d 00:00:00', strtotime("-{$i} days", time())));
                /** $i 天前当天结束时间戳 */
                $dayEnd = strtotime(date('Y-m-d 23:59:59', strtotime("-{$i} days", time())));
                /** $i 天前日期 */
                $_date = date('Y-m-d', strtotime("-{$i} days", time()));

                /** 有属于该日期的订单，则数量 +1 */
                if ($orderTime >= $dayStart && $orderTime <= $dayEnd) {
                    $orderNums[$_date] += $v->total_price;
                }
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['dates'] = $dates;
        $return['result']['order_price'] = array_values($orderNums);
        $return['result']['order_price_with_dates'] = $orderNums;

        return response()->json($return);
    }

    /**
     * 获取近一月各店铺订单数量
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderNumPercent()
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $period = 29;
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$period} days", time()));

        $orders = Order::select(DB::raw('ksn_shops.market, ksn_shops.shop_name, ksn_shops.shop_id, COUNT(ksn_orders.id) as order_num'))->leftJoin('shops', 'orders.shop_id', '=', 'shops.id')->where(['shops.user_id'=>$user_id, ['orders.order_time', '>=', $startDate]])->orderBy('orders.order_time', 'ASC')->groupBy('shops.shop_id')->get();

        $result = array();

        foreach ($orders as $k=>$v) {
            $result[$k]['name'] = "[{$v->market}] {$v->shop_name}";
            $result[$k]['value'] = $v->order_num;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $result;

        return response()->json($return);
    }

    /**
     * 获取近一月各店铺订单金额
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderPricePercent()
    {
        $return = array();

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        $period = 29;
        $startDate = date('Y-m-d 00:00:00', strtotime("-{$period} days", time()));

        $orders = Order::select(DB::raw('ksn_shops.market, ksn_shops.shop_name, ksn_shops.shop_id, SUM(ksn_orders.total_price) as order_price'))->leftJoin('shops', 'orders.shop_id', '=', 'shops.id')->where(['shops.user_id'=>$user_id, ['orders.order_time', '>=', $startDate]])->orderBy('orders.order_time', 'ASC')->groupBy('shops.shop_id')->get();

        $result = array();

        foreach ($orders as $k=>$v) {
            $result[$k]['name'] = "[{$v->market}] {$v->shop_name}";
            $result[$k]['value'] = $v->order_price;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $result;

        return response()->json($return);
    }
}
