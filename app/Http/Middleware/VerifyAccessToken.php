<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class VerifyAccessToken extends Middleware
{
    /**
     * Handle an incoming request.
     * 验证 access_token 是否有效
     * @param  \Illuminate\Http\Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $return = array();

        $schema = 'Bearer';
        $header = $request->header('Authorization');

        if (!$header) {
            $return['code'] = 'E409011';
            $return['message'] = 'Invalid token';
            return response()->json($return);
        }

        if (ucfirst(substr($header, 0, 6)) != $schema) {
            $return['code'] = 'E409012';
            $return['message'] = 'Token schema yahoo';
            return response()->json($return);
        }

        $token = trim(ltrim($header, $schema));

        try {
            //已过期的 token 解码时会进入 catch 里，因此不必重复验证 token 是否过期
            JWT::decode($token, new Key(Config::get('token.api_key'), Config::get('token.jwt_alg')));

            $exists = DB::table('tokens')->where('access_token', $token)->count();

            /** token验证通过 */
            if ($exists) return $next($request);

            $return['code'] = 'E409014';
            $return['message'] = 'Invalid token';
        } catch (SignatureInvalidException $e) {  // 签名不正确
            $return['code'] = 'E409014';
            $return['message'] = 'Invalid token';
        } catch (BeforeValidException $e) {  // 签名在某个时间点之后才能用
            $return['code'] = 'E409014';
            $return['message'] = 'Invalid token';
        } catch (ExpiredException $e) {  // token过期
            $return['code'] = 'E409014';
            $return['message'] = 'Invalid token. Token has been expired';
        } catch (\Exception $e) {  // 其他错误
            $return['code'] = 'E409014';
            $return['message'] = 'Invalid token';
        }

        return response()->json($return);
    }
}
