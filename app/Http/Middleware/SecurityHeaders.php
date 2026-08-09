<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        if (config('app.env') === 'production' && $request->isSecure()) {
            $maxAge = max(0, (int) config('security.headers.hsts_max_age', 31_536_000));
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        if ($request->is('admin', 'admin/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $scriptSources = ["'self'", "'nonce-{$nonce}'"];
        $connectSources = ["'self'"];
        $fontSources = ["'self'", 'data:', 'https://fonts.gstatic.com'];

        if (app()->isLocal() && ($viteOrigin = $this->viteDevelopmentOrigin())) {
            $scriptSources[] = $viteOrigin;
            $connectSources[] = $viteOrigin;
            $fontSources[] = $viteOrigin;
            $connectSources[] = str_starts_with($viteOrigin, 'https://')
                ? 'wss://'.substr($viteOrigin, 8)
                : 'ws://'.substr($viteOrigin, 7);
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            'connect-src '.implode(' ', $connectSources),
            'font-src '.implode(' ', $fontSources),
            "form-action 'self'",
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "img-src 'self' data:",
            "manifest-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            'script-src '.implode(' ', $scriptSources),
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "worker-src 'self' blob:",
        ];

        if (config('app.env') === 'production') {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function viteDevelopmentOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! File::isFile($hotFile)) {
            return null;
        }

        $hotUrl = trim(File::get($hotFile));
        $parts = parse_url($hotUrl);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
