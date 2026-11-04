<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockPaidMarketDataRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('services.market_data.free_only', true)) {
            return response()->json(['message' => 'EODHD is disabled. No free provider is available for this operation; stored data is preserved.'], 422);
        }

        return $next($request);
    }
}
