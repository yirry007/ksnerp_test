<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * 管理员列表
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $return = array();
        $req = $request->only('username', 'is_use', 'page');

        /** 查询数据库返回结果 */
        $perpage = 10;//每页显示数量
        $page = array_val('page', $req) ?: 1;
        $offset = ($page - 1) * $perpage;

        $userInstance = User::where('parent_id', '0')->where(function($query) use($req){
            /** 按条件筛选用户 */
            if ($username = array_val('username', $req)) {
                $query->whereRaw("locate('{$username}', `username`) > 0");
            }
            if (array_key_exists('is_use', $req)) {
                $query->where('is_use', $req['is_use']);
            }
        });

        $count = $userInstance->count();
        $users = $userInstance->offset($offset)->limit($perpage)->orderBy('id', 'DESC')->get();

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['count'] = $count;
        $return['result']['admins'] = $users;

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

        $req = $request->only('username', 'password', 'server_ip', 'private_ip', 'domain', 'tag');

        $username = array_val('username', $req);
        $password = array_val('password', $req);
        $serverIP = array_val('server_ip', $req);
        $privateIP = array_val('private_ip', $req);
        $domain = array_val('domain', $req) ?: '';//取值可能为null
        $tag = array_val('tag', $req) ?: '';//取值可能为null

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
        if (!$serverIP) {
            $return['code'] = 'E100013';
            $return['message'] = __('lang.Please input public IP address.');
            return response()->json($return);
        }
        if (!$privateIP) {
            $return['code'] = 'E100014';
            $return['message'] = __('lang.Please input private IP address.');
            return response()->json($return);
        }

        $exists = User::where('username', $username)->count();

        if ($exists) {
            $return['code'] = 'E200011';
            $return['message'] = __('lang.Username has already exists.');
            return response()->json($return);
        }

        $user = User::create([
            'username'=>$username,
            'password'=>pwdEnc($password),
            'server_ip'=>$serverIP,
            'private_ip'=>$privateIP,
            'domain'=>$domain,
            'tag'=>$tag,
            'create_time'=>date('Y-m-d H:i:s'),
        ]);

        if (!$user) {
            $return['code'] = 'E302011';
            $return['message'] = __('lang.Data create failed.');
        } else {
            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result'] = $user;

            /** 查看当前服务器有没有版本管理记录，没有添加一条 */
            $versionExists = DB::table('versions')->where('private_ip', $privateIP)->count();
            if (!$versionExists) {
                DB::table('versions')->insert([
                    'private_ip'=>$privateIP,
                    'version'=>'1.0.0',
                    'update_time'=>date('Y-m-d H:i:s'),
                ]);
            }
        }

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

        $req = $request->only('username', 'password', 'server_ip', 'private_ip', 'is_use', 'domain', 'tag');

        $username = array_val('username', $req);
        $password = array_val('password', $req);
        $serverIP = array_val('server_ip', $req);
        $privateIP = array_val('private_ip', $req);
        $domain = array_val('domain', $req) ?: '';//取值可能为null
        $tag = array_val('tag', $req) ?: '';//取值可能为null

        if (!$username) {
            $return['code'] = 'E100011';
            $return['message'] = __('lang.Please input username.');
            return response()->json($return);
        }
        if (!$serverIP) {
            $return['code'] = 'E100013';
            $return['message'] = __('lang.Please input public IP address.');
            return response()->json($return);
        }
        if (!$privateIP) {
            $return['code'] = 'E100014';
            $return['message'] = __('lang.Please input private IP address.');
            return response()->json($return);
        }

        $exists = User::where([['id', '!=', $id], ['username', $username]])->count();

        if ($exists) {
            $return['code'] = 'E200011';
            $return['message'] = __('lang.Username has already exists.');
            return response()->json($return);
        }

        /** 设置将要更新的用户名和密码 */
        $updateData = array();
        $updateData['username'] = $username;
        $updateData['server_ip'] = $serverIP;
        $updateData['private_ip'] = $privateIP;
        $updateData['domain'] = $domain;
        $updateData['tag'] = $tag;

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

        /** 如果把用户禁用了，清空服务器中保存的 token 数据 */
        if (array_key_exists('is_use', $req) && !$req['is_use']) {
            DB::table('tokens')->where('user_id', $id)->delete();
        }

        /** 查看当前服务器有没有版本管理记录，没有添加一条 */
        $versionExists = DB::table('versions')->where('private_ip', $privateIP)->count();
        if (!$versionExists) {
            DB::table('versions')->insert([
                'private_ip'=>$privateIP,
                'version'=>'1.0.0',
                'update_time'=>date('Y-m-d H:i:s'),
            ]);
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
    {}

    /**
     * 获取格式化的语言包信息（管理员用于更新）
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLanguageData()
    {
        $return = array();

        $languageCN = DB::table('languages')->where('code', 'cn')->first();
        $languageJP = DB::table('languages')->where('code', 'jp')->first();
        $languageEN = DB::table('languages')->where('code', 'en')->first();

        $langCN = json_decode($languageCN->language, true);
        $langJP = json_decode($languageJP->language, true);
        $langEN = json_decode($languageEN->language, true);

        $languageData = array();
        $index = 0;
        foreach ($langCN as $k=>$v) {
            $languageData[$index]['key'] = $k;
            $languageData[$index]['cn'] = $v;
            $index++;
        }

        foreach ($languageData as $k=>$v) {
            foreach ($langJP as $k1=>$v1) {
                if ($k1 == $v['key']) {
                    $languageData[$k]['jp'] = $v1;
                    unset($langJP[$k1]);
                    break;
                }
            }
        }

        foreach ($languageData as $k=>$v) {
            foreach ($langEN as $k1=>$v1) {
                if ($k1 == $v['key']) {
                    $languageData[$k]['en'] = $v1;
                    unset($langJP[$k1]);
                    break;
                }
            }
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result'] = $languageData;

        return response()->json($return);
    }

    /**
     * 更新语言包
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateLanguageData(Request $request)
    {
        $return = array();

        $req = $request->only('update_data');

        $updateData = array();
        foreach ($req['update_data'] as $v) {
            /** $v['code'] 值为 cn|jp|en */
            $updateData[$v['code']][] = ['key'=>$v['key'], 'value'=>$v['value']];
        }

        foreach ($updateData as $code=>$values) {
            if (!count($values)) continue;

            $language = DB::table('languages')->where('code', $code)->first();
            $lang = json_decode($language->language, true);

            foreach ($lang as $k=>$v) {
                foreach ($values as $k1=>$v1) {
                    if ($v1['key'] == $k) {
                        $lang[$k] = $v1['value'];
                        unset($values[$k1]);
                        break;
                    }
                }
            }

            DB::table('languages')->where('code', $code)->update([
                'language'=>str_replace('\\', '', json_encode($lang, JSON_UNESCAPED_UNICODE))
            ]);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }

    /**
     * 新增语言包项目
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function languageAdd(Request $request)
    {
        $return = array();

        $req = $request->only('key', 'cn', 'jp', 'en');

        if (!$key = array_val('key', $req)) {
            $return['code'] = 'E900011';
            $return['message'] = 'Please input key';
            return response()->json($return);
        }

        $cn = array_val('cn', $req) ?: 'cn';
        $jp = array_val('jp', $req) ?: 'jp';
        $en = array_val('en', $req) ?: 'en';

        $languages = DB::table('languages')->get();
        foreach ($languages as $v) {
            $lang = json_decode($v->language, true);

            if ($v->code == 'cn') {
                $lang[$key] = $cn;
            }
            if ($v->code == 'jp') {
                $lang[$key] = $jp;
            }
            if ($v->code == 'en') {
                $lang[$key] = $en;
            }

            DB::table('languages')->where('code', $v->code)->update([
                'language'=>str_replace('\\', '', json_encode($lang, JSON_UNESCAPED_UNICODE))
            ]);
        }

        $return['code'] = '';
        $return['message'] = 'SUCCESS';

        return response()->json($return);
    }
}
