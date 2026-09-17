<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_status !== 'active') {
            return response()->json([
                'message' => "You've been deactivated.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}