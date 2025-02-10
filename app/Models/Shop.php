<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Shop extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['user_id', 'token', 'refresh_token', 'create_time'];

    public $timestamps = false;

    /**
     * 获取当前登录用户可操作的店铺id（主键）列表
     * @return array
     */
    public static function getUserShop()
    {
        $shop_ids = array();
        $payload = getTokenPayload();

        if (!$payload->aud) {
            /** 管理员用户获取所有属于他的店铺 */
            $shops = self::select(['id'])->where('user_id', $payload->jti)->get();
            foreach ($shops as $v) {
                $shop_ids[] = $v->id;
            }
        } else{
            /** 普通用户只能获取拥有权限的店铺 */
            $userShops = DB::table('user_shop')->where('user_id', $payload->jti)->get();
            foreach ($userShops as $v) {
                $shop_ids[] = $v->shop_id;
            }
        }

        return $shop_ids;
    }
}
