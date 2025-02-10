<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyPermission
{
    /**
     * Handle an incoming request.
     * 验证用户访问路由权限
     * @param  \Illuminate\Http\Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /** 获取当前用户访问的路由 */
        $routePath = $request->path();
        $routePaths = explode('/', $routePath);
        $verifyingPath = $routePaths[2];

        /** 用户可访问路由 */
        $payload = getTokenPayload();
        $reqPath = $payload->paths;

        if (!in_array($verifyingPath, explode(',', $reqPath))) {
            return response()->json([
                'code'=>'E403001',
                'message'=>'permission denied',
            ]);
        }

        return $next($request);
    }
}
