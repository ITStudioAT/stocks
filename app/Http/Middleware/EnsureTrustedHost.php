<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class EnsureTrustedHost
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $trustedHostPatterns = collect(config('security.trusted_hosts', []))
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '')
            ->map(fn (string $host): string => '^'.preg_quote($host, '/').'$')
            ->values()
            ->all();

        if ($trustedHostPatterns === []) {
            throw new ServiceUnavailableHttpException(null, 'No trusted application hosts are configured.');
        }

        SymfonyRequest::setTrustedHosts($trustedHostPatterns);

        try {
            // Force Symfony to validate the Host header before routing or redirects use it.
            $request->getHost();

            return $next($request);
        } finally {
            SymfonyRequest::setTrustedHosts([]);
        }
    }
}
