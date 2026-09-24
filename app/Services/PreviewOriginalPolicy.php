<?php

namespace App\Services;

use InvalidArgumentException;

class PreviewOriginalPolicy
{
    /** @param list<string> $sourceSecrets */
    public function __construct(private array $sourceSecrets = []) {}

    /**
     * Preserve original application data and password hashes. Only disposable login tokens
     * and embedded integration credentials are removed; no names, notes or history are dropped.
     *
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @return array<string, list<array<string, scalar|null>>>
     */
    public function prepare(array $tables): array
    {
        $columns = (new PreviewOriginalSchema)->columns();
        foreach ($tables as $table => &$rows) {
            if (! isset($columns[$table]) || ! array_is_list($rows)) {
                throw new InvalidArgumentException('Original snapshot contains an unapproved table.');
            }
            foreach ($rows as &$row) {
                if (! is_array($row) || array_diff(array_keys($row), $columns[$table]) !== []) {
                    throw new InvalidArgumentException('Original snapshot contains an unapproved column.');
                }
                foreach ($row as $column => &$value) {
                    if (! is_scalar($value) && $value !== null) {
                        throw new InvalidArgumentException('Invalid original snapshot value.');
                    }
                    if ($table === 'users' && $column === 'remember_token') {
                        $value = null;
                    } elseif ($table === 'users' && $column === 'password') {
                        if (! is_string($value) || password_get_info($value)['algo'] === null) {
                            throw new InvalidArgumentException('Original user password is not a supported password hash.');
                        }
                    } elseif (is_string($value)) {
                        $value = $this->redact($value);
                    }
                }
                unset($value);
            }
            unset($row);
        }
        unset($rows);

        return $tables;
    }

    private function redact(string $value): string
    {
        foreach ($this->sourceSecrets as $secret) {
            if ($secret !== '') {
                $value = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[preview-redacted]', $value);
            }
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $redacted = $this->redactJson($decoded);

            return $redacted === $decoded ? $value : json_encode($redacted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return preg_replace('/((?:api[_-]?(?:key|token)|access[_-]?token|password|secret)(?:=|%3d))[^&\s"\'<>]+/i', '$1[preview-redacted]', $value) ?? $value;
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function redactJson(array $values): array
    {
        foreach ($values as $key => &$value) {
            if (is_string($key) && preg_match('/^(?:password|secret|(?:api|access|refresh)[_-]?token|credentials?|api[_-]?key|client[_-]?secret|authorization)$/i', $key)) {
                $value = '[preview-redacted]';
            } elseif (is_array($value)) {
                $value = $this->redactJson($value);
            } elseif (is_string($value)) {
                $value = $this->redact($value);
            }
        }
        unset($value);

        return $values;
    }
}
