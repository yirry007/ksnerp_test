<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInfo extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    /**
     * 获取当前操作的采购应用信息
     * VerifySupplyInfo 中间中已经验证了参数（market）和数据是否存在，因此不再验证
     * @return mixed
     */
    public static function getInstance()
    {
        $market = request()->get('market');

        $payload = getTokenPayload();
        $user_id = $payload->aud ?: $payload->jti;

        return self::where(['user_id'=>$user_id, 'market'=>ucfirst($market)])->first();
    }
}
