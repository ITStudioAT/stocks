<?php

namespace App\Http\Middleware;

use App\Services\PreviewBackgroundState;
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

            if (config('security.preview.control_enabled') === true
                && ! $request->is('preview/control')
                && ! app(PreviewBackgroundState::class)->enabled()) {
                return $this->markPreview(response('Stocks preview is stopped.', 503));
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
