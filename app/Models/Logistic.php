<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Logistic extends Model
{
    protected $hidden = [
        'password',
    ];

    public $timestamps = false;

    /**
     * 获取用户可使用的物流商列表
     * @return mixed
     */
    public static function getUserLogistics()
    {
        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        /** 可使用物流商/仓库 */
        //给用户分配的物流商
        $logisticIds = DB::table('user_logistic')->select(DB::raw('GROUP_CONCAT(logistic_id) AS logistic_ids'))->where('user_id', $user_id)->first();
        $logisticIdArr = $logisticIds->logistic_ids ? explode(',', $logisticIds->logistic_ids) : [];

        //通用的物流商
        $logisticAllUserIds = self::select(DB::raw('GROUP_CONCAT(id) AS ids'))->where('all_user', '1')->first();
        $logisticAllUserIdArr = $logisticAllUserIds->ids ? explode(',', $logisticAllUserIds->ids) : [];

        return self::select(['id', 'nickname', 'company', 'manager', 'country', 'address'])->where('is_use', '1')->whereIn('id', array_merge($logisticIdArr, $logisticAllUserIdArr))->get();
    }
}
