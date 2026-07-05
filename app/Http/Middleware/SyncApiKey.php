<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SyncApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = config('services.sync.api_key');

        if (!$key || $request->bearerToken() !== $key) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
