<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('auth.user_id')) {
            return redirect()->route('auth.login');
        }

        return $next($request);
    }
}
