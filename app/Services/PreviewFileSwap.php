<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class PreviewFileSwap
{
    public function exchange(string $root, string $candidate, string $preserved, string $commit): void
    {
        $private = $this->privateDirectory($root);
        $this->assertNoPendingSwap($root);
        $this->assertDirectory($candidate, $private);
        if (dirname($preserved) !== $private || ! preg_match('/^(template|failed)-[a-f0-9]{32}$/D', basename($preserved))
            || file_exists($preserved) || is_link($preserved) || ! mkdir($preserved, 0700)) {
            throw new RuntimeException('Invalid preview preservation directory.');
        }
        $journal = [
            'format' => 'stocks-preview-swap-v1', 'root' => $root, 'commit' => $commit,
            'candidate' => $candidate, 'preserved' => $preserved, 'phase' => 'backing_up',
            'old_entries' => $this->entries($root), 'new_entries' => $this->entries($candidate),
        ];
        $this->saveJournal($private, $journal);
        try {
            foreach ($this->publicFirst($journal['old_entries']) as $name) {
                $this->move($root.'/'.$name, $preserved.'/'.$name);
            }
            $journal['phase'] = 'installing';
            $this->saveJournal($private, $journal);
            foreach ($this->publicLast($journal['new_entries']) as $name) {
                $this->move($candidate.'/'.$name, $root.'/'.$name);
            }
            $this->finish($private);
        } catch (Throwable $exception) {
            try {
                $this->recover($root, $commit);
            } catch (Throwable) {
                throw new RuntimeException('Preview file exchange stopped. Keep the app closed and run recover-files with this package; private backups and the journal are retained.', previous: $exception);
            }
            throw new RuntimeException('Preview file exchange failed; the previous application files were restored.', previous: $exception);
        }
    }

    public function assertNoPendingSwap(string $root): void
    {
        $path = $root.'/.stocks-preview-private/swap.json';
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('An interrupted preview file exchange requires recover-files before continuing.');
        }
    }

    public function recover(string $root, string $commit): void
    {
        $private = $this->privateDirectory($root);
        $path = $private.'/swap.json';
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 131_072) {
            throw new RuntimeException('Invalid preview exchange journal.');
        }
        $journal = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (($journal['format'] ?? null) !== 'stocks-preview-swap-v1' || ($journal['root'] ?? null) !== $root
            || ($journal['commit'] ?? null) !== $commit || ! in_array($journal['phase'] ?? null, ['backing_up', 'installing', 'restoring'], true)) {
            throw new RuntimeException('Preview exchange journal identity mismatch.');
        }
        foreach (['candidate', 'preserved'] as $field) {
            $this->assertDirectory($journal[$field] ?? '', $private);
        }
        foreach (['old_entries', 'new_entries'] as $field) {
            if (! is_array($journal[$field] ?? null) || count(array_unique($journal[$field])) !== count($journal[$field])) {
                throw new RuntimeException('Invalid preview exchange entries.');
            }
            foreach ($journal[$field] as $name) {
                $this->assertName($name);
            }
        }
        if ($journal['phase'] === 'installing') {
            foreach ($this->publicFirst($journal['new_entries']) as $name) {
                $this->reverseMove($journal['candidate'].'/'.$name, $root.'/'.$name);
            }
        }
        $journal['phase'] = 'restoring';
        $this->saveJournal($private, $journal);
        foreach ($this->publicLast($journal['old_entries']) as $name) {
            $this->reverseMove($root.'/'.$name, $journal['preserved'].'/'.$name);
        }
        $this->finish($private);
    }

    protected function move(string $source, string $destination): void
    {
        if ((! file_exists($source) && ! is_link($source)) || is_link($source)
            || file_exists($destination) || is_link($destination) || ! rename($source, $destination)) {
            throw new RuntimeException('Cannot move a preview entry without overwriting another path.');
        }
    }

    private function reverseMove(string $original, string $moved): void
    {
        $originalExists = file_exists($original) || is_link($original);
        $movedExists = file_exists($moved) || is_link($moved);
        if ($originalExists === $movedExists || is_link($original) || is_link($moved)) {
            throw new RuntimeException('Ambiguous preview recovery paths; no conflicting path will be overwritten.');
        }
        if ($movedExists) {
            $this->move($moved, $original);
        }
    }

    private function privateDirectory(string $root): string
    {
        $private = $root.DIRECTORY_SEPARATOR.'.stocks-preview-private';
        if (realpath($root) !== $root || realpath($private) !== $private || is_link($private)
            || ! is_dir($private) || fileowner($private) !== fileowner($root)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($private) & 0077) !== 0)) {
            throw new RuntimeException('Invalid private preview exchange directory.');
        }

        return $private;
    }

    private function assertDirectory(string $path, string $private): void
    {
        if (dirname($path) !== $private || realpath($path) !== $path || ! is_dir($path) || is_link($path)
            || ! preg_match('/^(release|template|failed)-[a-f0-9]{32}$/D', basename($path))
            || fileowner($path) !== fileowner($private)) {
            throw new RuntimeException('Invalid preview exchange directory.');
        }
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Cannot inspect preview directory entries.');
        }
        $entries = array_values(array_diff($entries, ['.', '..', '.stocks-preview-private']));
        foreach ($entries as $name) {
            $this->assertName($name);
            if (is_link($directory.'/'.$name)) {
                throw new RuntimeException('Preview entry links are not supported.');
            }
        }

        return $entries;
    }

    private function assertName(string $name): void
    {
        if ($name === '' || in_array($name, ['.', '..', '.stocks-preview-private'], true)
            || preg_match('~[\\\\/\x00-\x1f\x7f:]~', $name)) {
            throw new RuntimeException('Invalid preview entry name.');
        }
    }

    /** @param list<string> $entries @return list<string> */
    private function publicFirst(array $entries): array
    {
        return in_array('public', $entries, true) ? ['public', ...array_values(array_diff($entries, ['public']))] : $entries;
    }

    /** @param list<string> $entries @return list<string> */
    private function publicLast(array $entries): array
    {
        return in_array('public', $entries, true) ? [...array_values(array_diff($entries, ['public'])), 'public'] : $entries;
    }

    /** @param array<string, mixed> $journal */
    private function saveJournal(string $private, array $journal): void
    {
        $temporary = $private.'/journal-'.bin2hex(random_bytes(16));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create preview exchange journal.');
        }
        try {
            $contents = json_encode($journal, JSON_THROW_ON_ERROR);
            if (! chmod($temporary, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Cannot persist preview exchange journal.');
            }
        } finally {
            fclose($stream);
        }
        if (! rename($temporary, $private.'/swap.json')) {
            throw new RuntimeException('Cannot publish preview exchange journal.');
        }
    }

    private function finish(string $private): void
    {
        if (! unlink($private.'/swap.json')) {
            throw new RuntimeException('Cannot finish preview file exchange.');
        }
    }
}
