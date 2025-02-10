<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * 用户列表
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('username', 'is_use');

        $payload = getTokenPayload();

        $users = User::where(function($query) use($payload){
            if ($payload->aud) {
                /** 普通用户只能获取同级的子用户 */
                $query->where('parent_id', $payload->aud);
            } else {
                /** 管理员获取自己和自己的手下 */
                $query->orWhere('parent_id', $payload->jti)->orWhere('id', $payload->jti);
            }
        })->where(function($query) use($req){
            /** 按条件筛选用户 */
            if ($username = array_val('username', $req)) {
                $query->whereRaw("locate('{$username}', `username`) > 0");
            }
            if (array_key_exists('is_use', $req)) {
                $query->where('is_use', $req['is_use']);
            }
        })->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $users;

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

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $return = array();

        $req = $request->only('username', 'password', 'menu', 'shops');

        $username = array_val('username', $req);
        $password = array_val('password', $req);

        if (!$username) {
            $return['code'] = 'E100011';
            $return['message'] = __('lang.Please input username.');
            return response()->json($return);
        }
        if (!$password) {
            $return['code'] = 'E100012';
            $return['message'] = __('lang.Please input password.');
            return response()->json($return);
        }

        $exists = User::where('username', $username)->count();

        if ($exists) {
            $return['code'] = 'E200011';
            $return['message'] = __('lang.Username has already exists.');
            return response()->json($return);
        }

        /** 设置parent_id */
        $payload = getTokenPayload();
        $parent_id = $payload->aud ?: $payload->jti;

        $user = User::create([
            'parent_id'=>$parent_id,
            'username'=>$username,
            'password'=>pwdEnc($password),
            'create_time'=>date('Y-m-d H:i:s'),
        ]);

        if (!$user) {
            $return['code'] = 'E302011';
            $return['message'] = __('lang.Data create failed.');
            return response()->json($return);
        }

        /** 给新增用户分配权限 */
        if (array_key_exists('menu', $req) && count($req['menu'])) {
            $menuData = array();
            foreach ($req['menu'] as $k=>$v) {
                $menuData[$k]['user_id'] = $user->id;
                $menuData[$k]['menu_id'] = $v;
            }

            DB::table('user_menu')->insert($menuData);
        }

        /** 给新增用户分配店铺 */
        if (array_key_exists('shops', $req) && count($req['shops'])) {
            $shopsData = array();
            foreach ($req['shops'] as $k=>$v) {
                $shopsData[$k]['user_id'] = $user->id;
                $shopsData[$k]['shop_id'] = $v;
            }

            DB::table('user_shop')->insert($shopsData);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $user;

        return response()->json($return);
    }

    /**
     * 获取用户信息
     * @param $id
     * @return \Illuminate\Http\JsonResponse
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

    /**
     * 更新用户信息
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $return = array();

        $req = $request->only('username', 'password', 'menu', 'shops', 'is_use');

        $username = array_val('username', $req);
        $password = array_val('password', $req);

        if (!$username) {
            $return['code'] = 'E100011';
            $return['message'] = __('lang.Please input username.');
            return response()->json($return);
        }

        $exists = User::where([['id', '!=', $id], ['username', $username]])->count();

        if ($exists) {
            $return['code'] = 'E200011';
            $return['message'] = __('lang.Username has already exists.');
            return response()->json($return);
        }

        /** 验证该用户是否在可操作范围之内 */
        $verifyUserGroup = $this->verifyUserGroup($id);
        if ($verifyUserGroup['state']) {
            $return['code'] = 'E200012';
            $return['message'] = $verifyUserGroup['msg'];
            return response()->json($return);
        }

        /** 设置将要更新的用户名和密码 */
        $updateData = array();
        $updateData['username'] = $username;

        if ($password) {//设置密码
            $updateData['password'] = pwdEnc($password);
        }
        if (array_key_exists('is_use', $req)) {//设置启用状态
            $updateData['is_use'] = $req['is_use'];
        }

        $user = User::where('id', $id)->update($updateData);

        if ($user === false) {
            $return['code'] = 'E303011';
            $return['message'] = __('lang.Data update failed.');
            return response()->json($return);
        }

        /** 修改用户权限（删除后重新添加） */
        if (array_key_exists('menu', $req) && count($req['menu'])) {
            DB::table('user_menu')->where('user_id', $id)->delete();

            $menuData = array();
            foreach ($req['menu'] as $k=>$v) {
                $menuData[$k]['user_id'] = $id;
                $menuData[$k]['menu_id'] = $v;
            }

            DB::table('user_menu')->insert($menuData);
        }

        /** 修改用户的店铺 */
        if (array_key_exists('shops', $req) && count($req['shops'])) {
            DB::table('user_shop')->where('user_id', $id)->delete();

            $shopsData = array();
            foreach ($req['shops'] as $k=>$v) {
                $shopsData[$k]['user_id'] = $id;
                $shopsData[$k]['shop_id'] = $v;
            }

            DB::table('user_shop')->insert($shopsData);
        }

        /** 如果把用户禁用了，清空服务器中保存的 token 数据 */
        if (array_key_exists('is_use', $req) && !$req['is_use']) {
            DB::table('tokens')->where('user_id', $id)->delete();
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 删除用户
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $return = array();

        $user = User::find($id);
        if (!$user->parent_id) {
            $return['code'] = 'E200018';
            $return['message'] = __('lang.Can not delete admin user.');
            return response()->json($return);
        }

        /** 验证该用户是否在可操作范围之内 */
        $verifyUserGroup = $this->verifyUserGroup($id);
        if ($verifyUserGroup['state']) {
            $return['code'] = 'E200012';
            $return['message'] = $verifyUserGroup['msg'];
            return response()->json($return);
        }

        $res = User::destroy($id);

        if ($res === false) {
            $return['code'] = 'E304011';
            $return['message'] = __('lang.Data delete failed.');
            return response()->json($return);
        }

        /** 删除该用户所拥有的权限 */
        DB::table('user_menu')->where('user_id', $id)->delete();

        /** 删除该用户所拥有的权限 */
        DB::table('user_shop')->where('user_id', $id)->delete();

        /** 删除该用户的所有的 token */
        DB::table('tokens')->where('user_id', $id)->delete();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 获取所有菜单（权限）列表
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMenu()
    {
        $return = array();

        $menu = Menu::select(['id', 'title'])
            ->where('id', '!=', 31)
            ->where('id', '!=', 35)
            ->where('id', '>', 1)
            ->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $menu;

        return response()->json($return);
    }

    /**
     * 获取店铺列表
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShops()
    {
        $return = array();

        $payload = getTokenPayload();
        /** 管理员用户直接获取他的用户id，其他用户获取其所属的管理员用户id */
        $shopUserId = $payload->aud ?: $payload->jti;

        $shops = Shop::select(['id', 'shop_id'])->where('user_id', $shopUserId)->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $shops;

        return response()->json($return);
    }

    /**
     * 获取用户所拥有的权限
     * @param $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserMenu($user_id)
    {
        $return = array();

        $user = User::where('id', $user_id)->first();

        if (!$user)  {
            $return['code'] = 'E301011';
            $return['message'] = __('lang.User not exists.');
            return response()->json($return);
        }

        /** 验证该用户是否在可操作范围之内 */
        $verifyUserGroup = $this->verifyUserGroup($user_id);
        if ($verifyUserGroup['state']) {
            $return['code'] = 'E200012';
            $return['message'] = $verifyUserGroup['msg'];
            return response()->json($return);
        }

        $userMenu = DB::table('user_menu')->where('user_id', $user->id)->orderBy('menu_id', 'ASC')->get();

        $menu = array();
        foreach ($userMenu as $v) {
            $menu[] = (string)$v->menu_id;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $menu;

        return response()->json($return);
    }

    /**
     * 获取用户可操作的店铺id
     * @param $user_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserShops($user_id)
    {
        $return = array();

        $user = User::where('id', $user_id)->first();

        if (!$user)  {
            $return['code'] = 'E301011';
            $return['message'] = __('lang.User not exists.');
            return response()->json($return);
        }

        /** 验证该用户是否在可操作范围之内 */
        $verifyUserGroup = $this->verifyUserGroup($user_id);
        if ($verifyUserGroup['state']) {
            $return['code'] = 'E200012';
            $return['message'] = $verifyUserGroup['msg'];
            return response()->json($return);
        }

        $userShops = DB::table('user_shop')->where('user_id', $user->id)->orderBy('shop_id', 'ASC')->get();

        $shops = array();
        foreach ($userShops as $v) {
            $shops[] = (string)$v->shop_id;
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $shops;

        return response()->json($return);
    }

    /**
     * 验证用户是否在可操作范围内
     * @param $id
     * @return array
     */
    private function verifyUserGroup($id)
    {
        $res = array();
        $user = User::find($id);
        $payload = getTokenPayload();

        /** 修改的用户为管理员时，该用户必须为当前登录的用户 */
        if (!$user->parent_id && $user->id == $payload->jti) {
            $res['state'] = 0;
            $res['msg'] = 'SUCCESS';
            return $res;
        }

        /** 修改用户为普通用户是，该用户为当前登录用户的子用户或同一个管理员下的子用户 */
        if ($user->parent_id && ($user->parent_id == $payload->jti || $user->parent_id == $payload->aud)) {
            $res['state'] = 0;
            $res['msg'] = 'SUCCESS';
            return $res;
        }

        $res['state'] = 1;
        $res['msg'] = 'Invalid user_id';
        return $res;
    }
}
