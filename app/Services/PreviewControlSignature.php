<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PreviewControlSignature
{
    /** @return array<string, string> */
    public function headers(string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Stocks-Control-Time' => $timestamp,
            'X-Stocks-Control-Nonce' => $nonce,
            'X-Stocks-Control-Signature' => $this->signature($method, $path, $timestamp, $nonce, $body),
        ];
    }

    public function verify(Request $request): void
    {
        $timestamp = (string) $request->header('X-Stocks-Control-Time', '');
        $nonce = (string) $request->header('X-Stocks-Control-Nonce', '');
        $provided = (string) $request->header('X-Stocks-Control-Signature', '');
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $provided) !== 1
            || strlen($request->getContent()) > 1024
            || ! hash_equals($this->signature($request->method(), $request->path(), $timestamp, $nonce, $request->getContent()), $provided)
            || ! Cache::add('preview-control:'.$nonce, true, 120)) {
            throw new RuntimeException('Invalid preview control signature.');
        }
    }

    private function signature(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        $key = (string) config('security.preview.control_key');
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException('Preview control key is not configured.');
        }

        return hash_hmac('sha256', strtoupper($method)."\n".ltrim($path, '/')."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), hex2bin($key));
    }
}
