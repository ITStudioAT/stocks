<?php

namespace App\Http\Middleware;

use App\Services\TrustedProxyConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    /**
     * Do not trust forwarded hosts or path prefixes, even from configured proxies.
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @return array<int, string>
     */
    protected function proxies(): array
    {
        return TrustedProxyConfiguration::validProxies(config('security.trusted_proxies', []));
    }

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $request->setTrustedProxies($this->proxies(), $this->getTrustedHeaderNames());
    }
}
