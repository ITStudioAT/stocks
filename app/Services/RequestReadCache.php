<?php

namespace App\Services;

use Closure;

class RequestReadCache
{
    public function remember(string $key, Closure $read): mixed
    {
        $request = request();
        if ($request->route() === null) {
            return $read();
        }

        $values = $request->attributes->get('stocks.reads', []);
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        $value = $read();
        $values = $request->attributes->get('stocks.reads', []);
        $values[$key] = $value;
        $request->attributes->set('stocks.reads', $values);

        return $value;
    }

    public function clear(): void
    {
        request()->attributes->remove('stocks.reads');
    }
}
