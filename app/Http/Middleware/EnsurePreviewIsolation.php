<?php

namespace App\Http\Middleware;

use App\Services\PreviewIsolation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePreviewIsolation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isolation = app(PreviewIsolation::class);
        if ($isolation->active()) {
            if ($isolation->problems() !== []) {
                return response('Preview configuration requires verification.', 503)
                    ->header('Cache-Control', 'no-store')
                    ->header('X-Robots-Tag', 'noindex, nofollow');
            }

            if ($request->getUser() !== 'preview'
                || ! password_verify((string) $request->getPassword(), (string) config('security.preview.access_password_hash'))) {
                return $this->markPreview(response('Private Stocks preview.', 401)
                    ->header('WWW-Authenticate', 'Basic realm="Stocks Preview", charset="UTF-8"'));
            }

            return $this->markPreview($next($request));
        }

        return $next($request);
    }

    private function markPreview(Response $response): Response
    {
        $response->headers->set('X-Stocks-Preview', 'true');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
