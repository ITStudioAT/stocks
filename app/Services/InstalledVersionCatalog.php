<?php

namespace App\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;

class InstalledVersionCatalog
{
    /** @return array{current_version: string, versions: array<int, array{key: string, label: string, version: string}>} */
    public function payload(): array
    {
        $currentVersion = config('stocks.version');
        $frontendVersions = $this->frontendVersions();
        $versions = [
            $this->version('laravel', 'Laravel', app()->version()),
            $this->version('php', 'PHP', PHP_VERSION),
            $this->version('laravel-ai', 'Laravel AI', $this->composerPackageVersion('laravel/ai')),
            $this->version('sanctum', 'Laravel Sanctum', $this->composerPackageVersion('laravel/sanctum')),
            $this->version('permissions', 'Berechtigungen', $this->composerPackageVersion('spatie/laravel-permission')),
            $this->version('vue', 'Vue', $frontendVersions['vue'] ?? null),
            $this->version('vuetify', 'Vuetify', $frontendVersions['vuetify'] ?? null),
            $this->version('vite', 'Vite', $frontendVersions['vite'] ?? null),
            $this->version('tailwindcss', 'Tailwind CSS', $frontendVersions['tailwindcss'] ?? null),
            $this->version('pinia', 'Pinia', $frontendVersions['pinia'] ?? null),
        ];

        return [
            'current_version' => is_string($currentVersion) && filled($currentVersion)
                ? $currentVersion
                : 'x.x.x',
            'versions' => array_values(array_filter($versions)),
        ];
    }

    /** @return array{key: string, label: string, version: string}|null */
    private function version(string $key, string $label, ?string $version): ?array
    {
        if (blank($version)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'version' => ltrim($version, 'v'),
        ];
    }

    private function composerPackageVersion(string $package): ?string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }

    /** @return array<string, string> */
    private function frontendVersions(): array
    {
        $path = base_path('package-lock.json');

        if (! File::isFile($path)) {
            return [];
        }

        $contents = File::get($path);
        $lock = json_decode($contents, true);

        if (! is_array($lock)) {
            return [];
        }

        return collect(['vue', 'vuetify', 'vite', 'tailwindcss', 'pinia'])
            ->mapWithKeys(function (string $package) use ($lock): array {
                $version = $lock['packages']["node_modules/{$package}"]['version'] ?? null;

                return is_string($version) && filled($version)
                    ? [$package => $version]
                    : [];
            })
            ->all();
    }
}
