<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\User;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * 根据 username 和 password 生成 access_token 和 refresh_token
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function auth(Request $request)
    {
        $return = array();

        $req = $request->only('username', 'password');

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

        $exists = User::where(['username'=>$req['username']])->first();

        //用户不存在或密码错误则用平台api获取用户数据并登录
        if (!$exists || pwdEnc($req['password']) != $exists->password) {
            $return['code'] = 'E200011';
            $return['message'] = __('lang.Username or password is incorrect.');
            return response()->json($return);
        }
        if (!$exists->is_use) {
            $return['code'] = 'E200014';
            $return['message'] = __('lang.Username is disabled.');
            return response()->json($return);
        }

        //查看是否为管理员(parent_id=0)，普通用户则需要查询管理员id
        $parent_id = 0;
        if ($exists->parent_id) {
            $parent = User::where(['id'=>$exists->parent_id])->first();
            $parent_id = $parent->id;
        }

        $tokenData = [
            'user_id'=>$exists->id,
            'username'=>$exists->username,
            'parent_id'=>$parent_id
        ];

        $menuModel = new Menu();
        $menus = $menuModel->getMenu($exists);

        $menuData = array();
        $apiPaths = array();

        foreach ($menus as $k=>$v) {
            $menuData[$v['menu_group']][] = $v;
            $apiPaths[] = $v['api_route'];
        }

        $token = $this->createToken($tokenData, array_unique($apiPaths));
        $token['username'] = $exists->username;
//        $token['host'] = $exists->domain;
        $token['host'] = 'http://local.ksnerp.com';

        $return['code'] = '';
        $return['message'] = 'SUCCESS';
        $return['result']['token'] = $token;
        $return['result']['menu'] = $this->formatMenu($menuData);

        return response()->json($return);
    }

    /**
     * refresh token
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function refreshToken(Request $request)
    {
        $return = array();
        $req = $request->only('refresh_token');

        $refreshToken = array_val('refresh_token', $req);

        if (!$refreshToken) {
            $return['code'] = 'E100011';
            $return['message'] = __('lang.Parameter error.');
            return response()->json($return);
        }

        $fp = fopen(public_path('refresh.lock'), 'r');

        try {
            //用文件锁来确保第一个用refesh_token来换取access_token的请求最先返回，其他请求依次处理
            flock($fp, LOCK_EX);

            //已过期的 token 解码时会进入 catch 里，因此不必重复验证 token 是否过期
            $payload = JWT::decode($refreshToken, new Key(Config::get('token.api_key'), Config::get('token.jwt_alg')));
            $cacheKey = $payload->sub . '_' . $payload->jti;

            /** 判断用户是否被禁用 */
            $exists = DB::table('tokens')->select(['users.*'])->leftJoin('users', 'tokens.user_id', '=', 'users.id')->where('refresh_token', $refreshToken)->first();
            if ($exists && !$exists->is_use) {
                flock($fp, LOCK_UN);fclose($fp);

                $return['code'] = 'E200014';
                $return['message'] = __('lang.Username is disabled.');
                return response()->json($return);
            }

            if (!$exists) {
                //同时发送refresh token请求时，第一个请求修改数据库中 refresh_token，则其他请求全部失败，此时查看cache里匹配refresh_token
                $cache = Cache::get($cacheKey);
                if (!$cache) {
                    flock($fp, LOCK_UN);fclose($fp);

                    $return['code'] = 'E400011';
                    $return['message'] = 'Invalid refresh token';
                    return response()->json($return);
                }

                $tokenCache = json_decode($cache, true);
                if ($tokenCache['origin_refresh_token'] != $refreshToken) {
                    flock($fp, LOCK_UN);fclose($fp);

                    $return['code'] = 'E400012';
                    $return['msg'] = 'Invalid refresh token';
                    return response()->json($return);
                }

                /** 判断用户是否被禁用 */
                $user = DB::table('tokens')->select(['users.*'])->leftJoin('users', 'tokens.user_id', '=', 'users.id')->where('access_token', $tokenCache['access_token'])->first();
                if (!$user || !$user->is_use) {
                    flock($fp, LOCK_UN);fclose($fp);

                    $return['code'] = 'E200014';
                    $return['message'] = __('lang.Username is disabled.');
                    return response()->json($return);
                }

                flock($fp, LOCK_UN);fclose($fp);
                unset($tokenCache['origin_refresh_token']);

                $return['code'] = '';
                $return['message'] = 'SUCCESS';
                $return['result']['token'] = $tokenCache;

                return response()->json($return);
            }

            //查看是否为管理员(parent_id=0)，普通用户则需要查询管理员id
            $parent_id = 0;
            if ($exists->parent_id) {
                $parent = User::where(['id'=>$exists->parent_id])->first();
                $parent_id = $parent->id;
            }

            $tokenData = [
                'user_id'=>$exists->id,
                'username'=>$exists->username,
                'parent_id'=>$parent_id,
                'refresh_token'=>$req['refresh_token']
            ];

            $menuModel = new Menu();
            $menus = $menuModel->getMenu($exists);

            $apiPaths = array();
            foreach ($menus as $k=>$v) {
                $apiPaths[] = $v['api_route'];
            }

            $token = $this->createToken($tokenData, array_unique($apiPaths));
            $token['username'] = $exists->username;
            $token['host'] = $exists->domain;

            /** 刷新 token 后为了确保原先的 refresh_token 继续有效一段时间，cache 中保存2分钟 */
            Cache::add($cacheKey, json_encode(array_merge($token, ['origin_refresh_token'=>$req['refresh_token']])), 2);

            $return['code'] = '';
            $return['message'] = 'SUCCESS';
            $return['result']['token'] = $token;
        } catch (SignatureInvalidException $e) {  // 签名不正确
            $return['code'] = 'E400013';
            $return['message'] = $e->getMessage();
        } catch (BeforeValidException $e) {  // 签名在某个时间点之后才能用
            $return['code'] = 'E400014';
            $return['message'] = $e->getMessage();
        } catch (ExpiredException $e) {  // token过期
            $return['code'] = 'E400015';
            $return['message'] = $e->getMessage();
        } catch (\Exception $e) {  // 其他错误
            $return['code'] = 'E400016';
            $return['message'] = $e->getMessage();
        }

        flock($fp, LOCK_UN);fclose($fp);

        return response()->json($return);
    }

    /**
     * 用户退出登录，销毁token
     * @param Request $request
     */
    public function logout(Request $request)
    {
        $schema = 'Bearer';
        $header = $request->header('Authorization');
        $token = trim(ltrim($header, $schema));

        DB::table('tokens')->where('access_token', $token)->delete();
    }

    /**
     * 获取所有语言包信息
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLanguagePackage()
    {
        return response()->json([
            'code'=>'',
            'message'=>'SUCCESS',
            'result'=>getLanguage('all')
        ]);
    }

    /**
     * 创建 token 保存数据库并返回
     * @param array $param
     * @param array $paths 用户可访问的 path
     * @return array
     */
    private function createToken($param, $paths)
    {
        $now = time();
        /*
         * sub Subject - This holds the identifier for the token (defaults to user id)
         * iat Issued At - When the token was issued (unix timestamp)
         * exp Expiry - The token expiry date (unix timestamp)
         * nbf Not Before - The earliest point in time that the token can be used (unix timestamp)
         * iss Issuer - The issuer of the token (defaults to the request url)
         * jti JWT Id - A unique identifier for the token (md5 of the sub and iat claims)
         * aud Audience - The intended audience for the token (not required by default)
         * paths - 用户可访问的路由
         */
        /*
         * sub => users.username,
         * iat => token create time,
         * iss => application url,
         * jti => users.id,
         * aud => users.parent_id,
         * paths => user_menu | 用户所拥有的权限,
         */
        $payload = array(
            'sub' => $param['username'],
            'iat' => $now,
            'iss' => _url_('/'),
            'jti' => $param['user_id'],
            'aud' => $param['parent_id'],
            'paths' => implode(',', $paths),
        );

        /** 设置 access_token 和 refresh_token 的过期时间 */
        $accessTokenPayload = $refreshTokenPayload = $payload;
        $accessTokenExpired = $payload['iat'] + Config::get('token.access_token_lifetime');
        $refreshTokenExpired = $payload['iat'] + Config::get('token.refresh_token_lifetime');
        $accessTokenPayload['exp'] = $accessTokenExpired;
        $refreshTokenPayload['exp'] = $refreshTokenExpired;

        /** 生成 access_token，refresh_token 的过期时间 */
        $access_token = JWT::encode($accessTokenPayload, Config::get('token.api_key'), Config::get('token.jwt_alg'));
        $refresh_token = JWT::encode($refreshTokenPayload, Config::get('token.api_key'), Config::get('token.jwt_alg'));

        if (array_key_exists('refresh_token', $param)) {
            //更新原有的 token
            DB::table('tokens')->where('refresh_token', $param['refresh_token'])->update([
                'access_token'=>$access_token,
                'refresh_token'=>$refresh_token,
                'access_token_expired'=>date('Y-m-d H:i:s', $accessTokenExpired),
                'refresh_token_expired'=>date('Y-m-d H:i:s', $refreshTokenExpired),
                'update_time'=>date('Y-m-d H:i:s', $now)
            ]);
        } else {
            //新增 token
            DB::table('tokens')->insert([
                'user_id'=>$param['user_id'],
                'username'=>$param['username'],
                'parent_id'=>$param['parent_id'],
                'access_token'=>$access_token,
                'refresh_token'=>$refresh_token,
                'access_token_expired'=>date('Y-m-d H:i:s', $accessTokenExpired),
                'refresh_token_expired'=>date('Y-m-d H:i:s', $refreshTokenExpired),
                'create_time'=>date('Y-m-d H:i:s', $now)
            ]);
        }

        $return = [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'token_type' => 'Bearer',
        ];
        if ($param['user_id'] == 1) {
            $return['is_super'] = 1;
        }

        return $return;
    }

    /**
     * 菜单（权限）列表数据格式化【转为前端容易使用的格式】
     * @param $menu
     * @return array
     */
    private function formatMenu($menu)
    {
        $return = array();

        $groupMap = [
            '商品管理'=>'/ITEM',
            '订单管理'=>'/ORDER',
            '采购管理'=>'/SOURCE',
            '基本设定'=>'/PREFERENCES',
        ];

        $index = 0;
        foreach ($menu as $k=>$v) {
            $return[$index]['label'] = $k;
            $return[$index]['key'] = (string)$index;
            $return[$index]['type'] = 'group';
            $return[$index]['icon'] = null;
            $return[$index]['page_route'] = $groupMap[$k] ?? '-';

            $childIndex = 0;
            foreach ($v as $v1) {
                $return[$index]['children'][$childIndex]['label'] = $v1['title'];
                $return[$index]['children'][$childIndex]['key'] = $index . '-'.$childIndex;
                $return[$index]['children'][$childIndex]['icon'] = $v1['icon'];
                $return[$index]['children'][$childIndex]['page_route'] = $v1['page_route'];

                $childIndex++;
            }

            $index++;
        }

        return $return;
    }
}
