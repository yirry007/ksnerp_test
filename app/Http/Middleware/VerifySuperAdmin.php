<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifySuperAdmin
{
    /**
     * Handle an incoming request.
     * 验证是否为超级管理员
     * @param  \Illuminate\Http\Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        /** Token解码数据 */
        $payload = getTokenPayload();

        if ($payload->jti != 1) {
            return response()->json([
                'code'=>'E403001',
                'message'=>'permission denied',
            ]);
        }

        return $next($request);
    }
}
