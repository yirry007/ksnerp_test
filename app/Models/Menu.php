<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Menu extends Model
{
    /**
     * 获取用户所拥有的菜单 [用户登录时与token一并返回给客户端，用于展示菜单]
     * @param $user
     * @return array
     */
    public function getMenu($user)
    {
        $return = array();
        $menuIds = array();//用户所拥有的权限id

        if ($user->parent_id) {
            $userMenu = DB::table('user_menu')->where('user_id', $user->id)->get();

            //用户没有任何权限
            if (!count($userMenu)) return $return;

            foreach ($userMenu as $v) $menuIds[] = $v->menu_id;
        }

        $return = $this->where('id', '>', '1')->where(function($query) use($user, $menuIds){
            if ($user->id > 1) {
                /** 非超级管理员跳过管理员管理菜单 */
                $query->where('id', '!=', 31);
                $query->where('id', '!=', 35);
                $query->where('id', '>', 1);
            }

            if (count($menuIds)) {
                /** 只获取用户所拥有的菜单， */
                $query->whereIn('id', $menuIds);
            }
        })->orderBy('sort', 'DESC')->orderBy('id', 'ASC')->get()->toArray();

        return $return;
    }
}
