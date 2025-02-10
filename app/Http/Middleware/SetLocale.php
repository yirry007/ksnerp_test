<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $validLocale = ['en', 'cn', 'jp'];
        $locale = $request->header('Locale');
        if (!$locale || !in_array($locale, $validLocale)) {
            $locale = 'cn';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
