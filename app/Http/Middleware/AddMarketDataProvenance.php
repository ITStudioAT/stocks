<?php

namespace App\Http\Middleware;

use App\Services\MarketDataProvenance;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddMarketDataProvenance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && is_array($payload = $response->getData(true))) {
            $response->setData(MarketDataProvenance::decorate($payload));
        }

        return $response;
    }
}
