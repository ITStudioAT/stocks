<?php

namespace App\Services;

use App\Models\WebsiteAnalysis;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsiteAnalyzer
{
    private const MAX_PAGES = 50;

    private const MAX_STYLESHEETS = 20;

    /**
     * @return array<string, mixed>
     */
    public function analyze(WebsiteAnalysis $analysis, ?Closure $progress = null): array
    {
        $baseUrl = $analysis->url;
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $queue = [$baseUrl];
        $queued = [$baseUrl => true];
        $visited = [];
        $pages = [];
        $pageErrors = [];
        $stylesheets = [];
        $crawlStartedAt = microtime(true);
        $path = "analyses/{$analysis->id}/analysis.json";

        while ($queue !== [] && count($visited) < self::MAX_PAGES) {
            $url = array_shift($queue);

            if (! is_string($url) || isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;
            $page = $this->fetchPage($url);

            if (! $page['ok']) {
                $pageErrors[] = [
                    'type' => 'page',
                    'url' => $url,
                    'status' => $page['status'],
                    'message' => $page['error'],
                ];

                continue;
            }

            $document = $this->document($page['body']);
            $pageAnalysis = $this->pageAnalysis($url, $page, $document);
            $pages[] = $pageAnalysis;

            if ($progress) {
                $progress(count($pages), $this->pagesAssetCount($pages), 'analysis', 0, 0);
            }

            foreach ($pageAnalysis['assets']['stylesheets'] as $stylesheet) {
                if (count($stylesheets) >= self::MAX_STYLESHEETS) {
                    break;
                }

                $stylesheets[$stylesheet] = $stylesheet;
            }

            foreach ($pageAnalysis['links']['internal'] as $link) {
                if (count($visited) + count($queue) >= self::MAX_PAGES) {
                    break;
                }

                $crawlUrl = $this->urlWithoutFragment($link);

                if (parse_url($crawlUrl, PHP_URL_HOST) === $baseHost && ! isset($visited[$crawlUrl], $queued[$crawlUrl])) {
                    $queue[] = $crawlUrl;
                    $queued[$crawlUrl] = true;
                }
            }
        }

        $stylesheetCss = $this->fetchStylesheets(array_values($stylesheets));
        $homepage = $pages[0] ?? $this->emptyPage($baseUrl);
        $colors = $this->colors($pages, $stylesheetCss);
        $fonts = $this->fonts($pages, $stylesheetCss);
        $siteAssets = $this->siteAssets($pages, $stylesheetCss);
        $headerMenu = $this->sharedNavigationLinks($pages, 'header_menu_links');
        $footerInformation = $this->navigationLinks($pages, 'footer_information_links');
        $pages = $this->pagesWithoutSharedNavigationLinks($pages, [
            ...$headerMenu['links'],
            ...$footerInformation['links'],
        ]);
        $homepage = $pages[0] ?? $this->emptyPage($baseUrl);
        $availabilityResources = $this->availabilityResources($pages, $siteAssets);
        $reachabilityTotal = count($availabilityResources);
        $interimResult = $this->analysisResult(
            analysis: $analysis,
            baseUrl: $baseUrl,
            pages: $pages,
            homepage: $homepage,
            siteAssets: $siteAssets,
            colors: $colors,
            fonts: $fonts,
            headerMenu: $headerMenu,
            footerInformation: $footerInformation,
            pageErrors: $pageErrors,
            availabilityErrors: [],
            step: 'reachability',
            reachabilityChecked: 0,
            reachabilityTotal: $reachabilityTotal,
            crawlStartedAt: $crawlStartedAt,
        );

        $this->storeResult($analysis, $path, $interimResult);

        [$availabilityErrors, $reachabilityChecked] = $this->availabilityErrors(
            $pages,
            $availabilityResources,
            $progress,
        );
        $pageErrorUrls = array_column($pageErrors, 'url');
        $availabilityErrors = array_values(array_filter(
            $availabilityErrors,
            fn (array $error): bool => ! in_array($this->urlWithoutFragment($error['url']), $pageErrorUrls, true),
        ));
        $result = $this->analysisResult(
            analysis: $analysis,
            baseUrl: $baseUrl,
            pages: $pages,
            homepage: $homepage,
            siteAssets: $siteAssets,
            colors: $colors,
            fonts: $fonts,
            headerMenu: $headerMenu,
            footerInformation: $footerInformation,
            pageErrors: $pageErrors,
            availabilityErrors: $availabilityErrors,
            step: 'completed',
            reachabilityChecked: $reachabilityChecked,
            reachabilityTotal: $reachabilityTotal,
            crawlStartedAt: $crawlStartedAt,
        );

        $this->storeResult($analysis, $path, $result);

        return [
            ...$result,
            'stored_at' => $path,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<string, mixed>  $homepage
     * @param  array<string, mixed>  $siteAssets
     * @param  array<int, array{value: string, count: int}>  $colors
     * @param  array<int, array{value: string, count: int}>  $fonts
     * @param  array{found: bool, page_count: int, link_count: int, links: array<int, string>}  $headerMenu
     * @param  array{found: bool, page_count: int, link_count: int, links: array<int, string>}  $footerInformation
     * @param  array<int, array{type: string, url: string, status: ?int, message: ?string}>  $pageErrors
     * @param  array<int, array{type: string, url: string, status: ?int, message: string}>  $availabilityErrors
     * @return array<string, mixed>
     */
    private function analysisResult(
        WebsiteAnalysis $analysis,
        string $baseUrl,
        array $pages,
        array $homepage,
        array $siteAssets,
        array $colors,
        array $fonts,
        array $headerMenu,
        array $footerInformation,
        array $pageErrors,
        array $availabilityErrors,
        string $step,
        int $reachabilityChecked,
        int $reachabilityTotal,
        float $crawlStartedAt,
    ): array {
        $errors = [
            ...$pageErrors,
            ...$availabilityErrors,
        ];

        return [
            'analysis' => [
                'id' => $analysis->id,
                'url' => $analysis->url,
                'host' => $analysis->host,
                'company_id' => $analysis->company_id,
                'client_id' => $analysis->client_id,
                'step' => $step,
                'reachability_checked' => $reachabilityChecked,
                'reachability_total' => $reachabilityTotal,
                'started_at' => $analysis->started_at?->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((microtime(true) - $crawlStartedAt) * 1000),
                'limits' => [
                    'max_pages' => self::MAX_PAGES,
                    'max_stylesheets' => self::MAX_STYLESHEETS,
                ],
            ],
            'crawl_metadata' => $this->crawlMetadata($baseUrl),
            'summary' => [
                'pages_discovered' => count($pages),
                'pages_failed' => count($pageErrors),
                'resources_failed' => count($availabilityErrors),
                'reachability_checked' => $reachabilityChecked,
                'reachability_total' => $reachabilityTotal,
                'site_assets' => $siteAssets['total'],
                'site_images' => count($siteAssets['images']) + count($siteAssets['responsive_images']),
                'site_other_files' => count($siteAssets['other_files']),
                'homepage_assets' => $homepage['assets']['total'],
                'homepage_images' => count($homepage['assets']['images']),
                'homepage_other_files' => count($homepage['assets']['other_files']),
                'colors_found' => count($colors),
                'fonts_found' => count($fonts),
            ],
            'homepage' => $homepage,
            'site_structure' => $this->siteStructure($pages),
            'header_menu' => $headerMenu,
            'footer_information' => $footerInformation,
            'site_assets' => $siteAssets,
            'colors' => $colors,
            'fonts' => $fonts,
            'pages' => $pages,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function storeResult(WebsiteAnalysis $analysis, string $path, array $result): void
    {
        Storage::disk('local')->put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($analysis->result_path !== $path) {
            WebsiteAnalysis::query()
                ->whereKey($analysis->id)
                ->update(['result_path' => $path]);

            $analysis->forceFill(['result_path' => $path]);
        }
    }

    /**
     * @return array{ok: bool, status: ?int, content_type: ?string, body: string, headers: array<string, mixed>, transfer: array<string, mixed>, error: ?string}
     */
    private function fetchPage(string $url): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->retry(2, 250)
                ->withUserAgent('Stocks Analyzer/1.0')
                ->get($url);
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'status' => null,
                'content_type' => null,
                'body' => '',
                'error' => $throwable->getMessage(),
            ];
        }

        $contentType = $response->header('content-type');
        $isHtml = str_contains(strtolower($contentType ?? ''), 'html');

        return [
            'ok' => $response->successful() && $isHtml,
            'status' => $response->status(),
            'content_type' => $contentType,
            'body' => $response->body(),
            'headers' => $response->headers(),
            'transfer' => [
                'effective_url' => $url,
                'redirected' => $response->redirect(),
                'bytes' => strlen($response->body()),
            ],
            'error' => $this->pageFetchError($response->status(), $contentType, $isHtml),
        ];
    }

    private function pageFetchError(int $status, ?string $contentType, bool $isHtml): ?string
    {
        if ($status !== 200) {
            return "HTTP status {$status}.";
        }

        if (! $isHtml) {
            return "Expected HTML, got {$contentType}.";
        }

        return null;
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageAnalysis(string $url, array $page, DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $links = $this->links($url, $xpath);
        $assets = $this->assets($url, $xpath);
        $text = trim((string) $document->textContent);

        return [
            'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH) ?: '/',
            'status' => $page['status'],
            'content_type' => $page['content_type'],
            'transfer' => $page['transfer'],
            'title' => $this->firstText($xpath, '//title'),
            'meta' => [
                'charset' => $this->attribute($xpath, '//meta[@charset]', 'charset'),
                'language' => $this->language($document, $xpath),
                'viewport' => $this->meta($xpath, 'viewport'),
                'description' => $this->meta($xpath, 'description'),
                'landing_description' => $this->landingDescription($xpath),
                'keywords' => $this->meta($xpath, 'keywords'),
                'canonical' => $this->attribute($xpath, '//link[@rel="canonical"]', 'href'),
                'robots' => $this->meta($xpath, 'robots'),
                'open_graph' => $this->propertyMeta($xpath, 'og:'),
                'twitter' => $this->propertyMeta($xpath, 'twitter:'),
            ],
            'structure' => [
                'headings' => $this->headings($xpath),
                'landmarks' => [
                    'header' => $xpath->query('//header')->length,
                    'nav' => $xpath->query('//nav')->length,
                    'main' => $xpath->query('//main')->length,
                    'section' => $xpath->query('//section')->length,
                    'article' => $xpath->query('//article')->length,
                    'aside' => $xpath->query('//aside')->length,
                    'footer' => $xpath->query('//footer')->length,
                ],
                'forms' => $xpath->query('//form')->length,
                'tables' => $xpath->query('//table')->length,
                'lists' => $xpath->query('//ul|//ol')->length,
            ],
            'content' => [
                'word_count' => str_word_count($text),
                'character_count' => mb_strlen($text),
            ],
            'accessibility' => [
                'images_missing_alt' => $xpath->query('//img[not(@alt)]')->length,
                'buttons_without_text' => $xpath->query('//button[not(normalize-space())]')->length,
                'inputs_without_label' => $xpath->query('//input[not(@type="hidden") and not(@aria-label) and not(@aria-labelledby) and not(@id=//label/@for)]')->length,
            ],
            'links' => $links,
            'header_menu_links' => $this->links($url, $xpath, $this->headerMenuLinkQuery()),
            'footer_information_links' => $this->links($url, $xpath, $this->footerInformationLinkQuery()),
            'assets' => $assets,
            'inline_style_text' => implode("\n", $this->nodeTexts($xpath->query('//style'))),
            'inline_style_attributes' => $this->styleAttributes($xpath),
        ];
    }

    private function headerMenuLinkQuery(): string
    {
        $id = $this->lowercaseXPath('@id');
        $class = $this->lowercaseXPath('@class');
        $role = $this->lowercaseXPath('@role');

        return implode(' | ', [
            '//header//a[@href]',
            '//nav//a[@href]',
            "//*[contains({$id}, 'header') or contains({$id}, 'headbar') or contains({$class}, 'header') or contains({$class}, 'headbar')]//a[@href]",
            "//*[(contains({$id}, 'menu') or contains({$id}, 'menue') or contains({$class}, 'menu') or contains({$class}, 'menue') or contains(concat(' ', normalize-space({$class}), ' '), ' nav ') or {$role} = 'navigation') and not(ancestor::*[contains({$this->lowercaseXPath('@id')}, 'footer') or contains({$this->lowercaseXPath('@class')}, 'footer')])]//a[@href]",
        ]);
    }

    private function footerInformationLinkQuery(): string
    {
        $id = $this->lowercaseXPath('@id');
        $class = $this->lowercaseXPath('@class');
        $role = $this->lowercaseXPath('@role');

        return implode(' | ', [
            '//footer//a[@href]',
            "//*[(contains({$id}, 'footer') or contains({$class}, 'footer') or contains({$id}, 'foot') or contains({$class}, 'foot') or {$role} = 'contentinfo')]//a[@href]",
        ]);
    }

    private function lowercaseXPath(string $value): string
    {
        return "translate({$value}, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
    }

    /**
     * @return array{internal: array<int, string>, external: array<int, string>, mailto: array<int, string>, telephone: array<int, string>}
     */
    private function links(string $url, DOMXPath $xpath, string $query = '//a[@href]'): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $links = [
            'internal' => [],
            'external' => [],
            'mailto' => [],
            'telephone' => [],
        ];

        foreach ($xpath->query($query) as $node) {
            /** @var DOMElement $node */
            $href = trim($node->getAttribute('href'));

            if (str_starts_with($href, 'mailto:')) {
                $links['mailto'][] = $href;

                continue;
            }

            if (str_starts_with($href, 'tel:')) {
                $links['telephone'][] = $href;

                continue;
            }

            $absolute = $this->absoluteUrl($url, $href);

            if (! $absolute) {
                continue;
            }

            if ($this->isNonHtmlAssetUrl($absolute)) {
                continue;
            }

            if (parse_url($absolute, PHP_URL_HOST) === $host) {
                $links['internal'][] = $absolute;

                continue;
            }

            $links['external'][] = $absolute;
        }

        return array_map(fn (array $items): array => array_values(array_unique($items)), $links);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{found: bool, page_count: int, link_count: int, links: array<int, string>}
     */
    private function sharedNavigationLinks(array $pages, string $linksKey): array
    {
        $pageCount = count($pages);
        $minimumPages = max(2, (int) ceil($pageCount * 0.5));
        $linkCounts = $this->navigationLinkCounts($pages, $linksKey);

        $links = array_keys(array_filter(
            $linkCounts,
            fn (int $count): bool => $count >= $minimumPages,
        ));

        return $this->navigationSummary($pages, $linksKey, $links);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{found: bool, page_count: int, link_count: int, links: array<int, string>}
     */
    private function navigationLinks(array $pages, string $linksKey): array
    {
        return $this->navigationSummary($pages, $linksKey, array_keys($this->navigationLinkCounts($pages, $linksKey)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, int>
     */
    private function navigationLinkCounts(array $pages, string $linksKey): array
    {
        $linkCounts = [];

        foreach ($pages as $page) {
            $navigationLinks = array_unique($this->flattenLinkGroups($page[$linksKey] ?? []));

            foreach ($navigationLinks as $link) {
                $linkCounts[$link] = ($linkCounts[$link] ?? 0) + 1;
            }
        }

        return $linkCounts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $links
     * @return array{found: bool, page_count: int, link_count: int, links: array<int, string>}
     */
    private function navigationSummary(array $pages, string $linksKey, array $links): array
    {
        sort($links);
        $pagesWithSharedHeaderLinks = count(array_filter(
            $pages,
            fn (array $page): bool => count(array_intersect($this->flattenLinkGroups($page[$linksKey] ?? []), $links)) > 0,
        ));

        return [
            'found' => count($links) > 0,
            'page_count' => $pagesWithSharedHeaderLinks,
            'link_count' => count($links),
            'links' => $links,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $sharedNavigationLinks
     * @return array<int, array<string, mixed>>
     */
    private function pagesWithoutSharedNavigationLinks(array $pages, array $sharedNavigationLinks): array
    {
        return array_map(function (array $page) use ($sharedNavigationLinks): array {
            $page['content_links'] = [
                'internal' => array_values(array_diff($page['links']['internal'], $sharedNavigationLinks)),
                'external' => array_values(array_diff($page['links']['external'], $sharedNavigationLinks)),
                'mailto' => array_values(array_diff($page['links']['mailto'], $sharedNavigationLinks)),
                'telephone' => array_values(array_diff($page['links']['telephone'], $sharedNavigationLinks)),
            ];

            return $page;
        }, $pages);
    }

    /**
     * @param  array<string, array<int, string>>  $links
     * @return array<int, string>
     */
    private function flattenLinkGroups(array $links): array
    {
        return [
            ...($links['internal'] ?? []),
            ...($links['external'] ?? []),
            ...($links['mailto'] ?? []),
            ...($links['telephone'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array{type: string, url: string, found_on: array<int, string>}>  $resources
     * @return array{0: array<int, array{type: string, url: string, status: ?int, message: string, found_on: array<int, string>}>, 1: int}
     */
    private function availabilityErrors(array $pages, array $resources, ?Closure $progress): array
    {
        $total = count($resources);
        $checked = 0;
        $errors = [];

        if ($progress) {
            $progress(count($pages), $this->pagesAssetCount($pages), 'reachability', $checked, $total);
        }

        foreach ($resources as $resource) {
            $error = $this->availabilityError($resource);
            $checked++;

            if ($error) {
                $errors[] = $error;
            }

            if ($progress) {
                $progress(count($pages), $this->pagesAssetCount($pages), 'reachability', $checked, $total);
            }
        }

        return [$errors, $checked];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<string, mixed>  $siteAssets
     * @return array<int, array{type: string, url: string, found_on: array<int, string>}>
     */
    private function availabilityResources(array $pages, array $siteAssets): array
    {
        $resources = [];

        foreach ($pages as $page) {
            $pageUrl = $page['url'];

            foreach (['internal', 'external'] as $group) {
                foreach ($page['links'][$group] ?? [] as $url) {
                    $resources[] = ['type' => 'link', 'url' => $url, 'found_on' => [$pageUrl]];
                }

                foreach ($page['content_links'][$group] ?? [] as $url) {
                    $resources[] = ['type' => 'link', 'url' => $url, 'found_on' => [$pageUrl]];
                }
            }

            foreach (['images' => 'image', 'responsive_images' => 'image', 'scripts' => 'file', 'stylesheets' => 'file', 'icons' => 'file', 'preloads' => 'file', 'media' => 'file', 'other_files' => 'file'] as $key => $type) {
                foreach ($page['assets'][$key] ?? [] as $url) {
                    $resources[] = ['type' => $type, 'url' => $url, 'found_on' => [$pageUrl]];
                }
            }
        }

        foreach ($siteAssets['css_referenced_assets'] ?? [] as $url) {
            $resources[] = [
                'type' => 'file',
                'url' => is_array($url) ? ($url['url'] ?? '') : $url,
                'found_on' => [$pages[0]['url'] ?? ''],
            ];
        }

        $uniqueResources = [];

        foreach ($resources as $resource) {
            if (! is_string($resource['url']) || ! $this->shouldCheckAvailability($resource['url'])) {
                continue;
            }

            $key = "{$resource['type']}|{$resource['url']}";

            if (isset($uniqueResources[$key])) {
                $uniqueResources[$key]['found_on'] = array_values(array_unique([
                    ...$uniqueResources[$key]['found_on'],
                    ...$resource['found_on'],
                ]));

                continue;
            }

            $uniqueResources[$key] = [
                ...$resource,
                'found_on' => array_values(array_unique(array_filter($resource['found_on']))),
            ];
        }

        return array_values($uniqueResources);
    }

    /**
     * @param  array{type: string, url: string, found_on: array<int, string>}  $resource
     * @return array{type: string, url: string, status: ?int, message: string, found_on: array<int, string>}|null
     */
    private function availabilityError(array $resource): ?array
    {
        $url = $resource['url'];

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->retry(1, 250)
                ->withUserAgent('Stocks Analyzer/1.0')
                ->get($this->urlWithoutFragment($url));
        } catch (\Throwable $throwable) {
            return [
                'type' => $resource['type'],
                'url' => $url,
                'status' => null,
                'message' => $throwable->getMessage(),
                'found_on' => $resource['found_on'],
            ];
        }

        if ($response->status() === 200) {
            return null;
        }

        return [
            'type' => $resource['type'],
            'url' => $url,
            'status' => $response->status(),
            'message' => "HTTP status {$response->status()}.",
            'found_on' => $resource['found_on'],
        ];
    }

    private function shouldCheckAvailability(string $url): bool
    {
        return filter_var($this->urlWithoutFragment($url), FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function assets(string $url, DOMXPath $xpath): array
    {
        $images = $this->attributesAsUrls($url, $xpath, '//img[@src]', 'src');
        $responsiveImages = $this->srcsetUrls($url, $xpath);
        $scripts = $this->attributesAsUrls($url, $xpath, '//script[@src]', 'src');
        $stylesheets = $this->attributesAsUrls($url, $xpath, '//link[contains(@rel, "stylesheet")][@href]', 'href');
        $icons = $this->attributesAsUrls($url, $xpath, '//link[contains(@rel, "icon")][@href]', 'href');
        $preloads = $this->attributesAsUrls($url, $xpath, '//link[contains(@rel, "preload")][@href]|//link[contains(@rel, "modulepreload")][@href]', 'href');
        $media = $this->attributesAsUrls($url, $xpath, '//video[@src]|//audio[@src]|//source[@src]', 'src');
        $otherFiles = array_values(array_filter(
            [
                ...$this->attributesAsUrls($url, $xpath, '//a[@href]', 'href'),
                ...$preloads,
            ],
            fn (string $asset): bool => $this->isFileUrl($asset),
        ));

        return [
            'total' => count($images) + count($responsiveImages) + count($scripts) + count($stylesheets) + count($icons) + count($preloads) + count($media) + count($otherFiles),
            'images' => $images,
            'responsive_images' => $responsiveImages,
            'scripts' => $scripts,
            'stylesheets' => $stylesheets,
            'icons' => $icons,
            'preloads' => $preloads,
            'media' => $media,
            'other_files' => $otherFiles,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function srcsetUrls(string $baseUrl, DOMXPath $xpath): array
    {
        $urls = [];

        foreach ($xpath->query('//*[@srcset]') as $node) {
            /** @var DOMElement $node */
            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $source = trim(explode(' ', trim($candidate))[0] ?? '');
                $absolute = $this->absoluteUrl($baseUrl, $source);

                if ($absolute) {
                    $urls[] = $absolute;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, string>
     */
    private function attributesAsUrls(string $baseUrl, DOMXPath $xpath, string $query, string $attribute): array
    {
        $urls = [];

        foreach ($xpath->query($query) as $node) {
            /** @var DOMElement $node */
            $absolute = $this->absoluteUrl($baseUrl, $node->getAttribute($attribute));

            if ($absolute) {
                $urls[] = $absolute;
            }
        }

        return array_values(array_unique($urls));
    }

    private function absoluteUrl(string $baseUrl, string $url): ?string
    {
        $url = trim($url);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $urlWithoutFragment = Str::before($url, '#');

        if ($url === '' || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if ($urlWithoutFragment === '' && $fragment) {
            return $this->appendFragment($this->urlWithoutFragment($baseUrl), $fragment);
        }

        if (str_starts_with($urlWithoutFragment, '//')) {
            return $this->appendFragment((parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https').':'.rtrim($urlWithoutFragment, '/'), $fragment);
        }

        if (filter_var($urlWithoutFragment, FILTER_VALIDATE_URL)) {
            return $this->appendFragment(rtrim($urlWithoutFragment, '/'), $fragment);
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?: '/';
        $path = str_starts_with($urlWithoutFragment, '/')
            ? $urlWithoutFragment
            : rtrim(str_replace('\\', '/', dirname($basePath)), '/').'/'.$urlWithoutFragment;

        return $this->appendFragment(rtrim($scheme.'://'.$host.'/'.$this->normalizePath($path), '/'), $fragment);
    }

    private function appendFragment(string $url, ?string $fragment): string
    {
        if (! $fragment) {
            return $url;
        }

        if (! parse_url($url, PHP_URL_PATH)) {
            $url = "{$url}/";
        }

        return "{$url}#{$fragment}";
    }

    private function urlWithoutFragment(string $url): string
    {
        $url = Str::before($url, '#');

        return rtrim($url, '/') ?: $url;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function isFileUrl(string $url): bool
    {
        return preg_match('/\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|csv|json|xml)$/i', parse_url($url, PHP_URL_PATH) ?? '') === 1;
    }

    private function isNonHtmlAssetUrl(string $url): bool
    {
        return preg_match('/\.(avif|bmp|css|gif|ico|jpe?g|js|m4a|mov|mp3|mp4|ogg|pdf|png|svg|webm|webp|woff2?|docx?|xlsx?|pptx?|zip|rar|7z|csv|json|xml)$/i', parse_url($url, PHP_URL_PATH) ?? '') === 1;
    }

    /**
     * @return array<int, string>
     */
    private function fetchStylesheets(array $stylesheets): array
    {
        $css = [];

        foreach ($stylesheets as $stylesheet) {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->retry(2, 250)
                    ->withUserAgent('Stocks Analyzer/1.0')
                    ->get($stylesheet);

                if ($response->successful()) {
                    $css[] = $response->body();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $css;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $stylesheetCss
     * @return array<int, array{value: string, count: int}>
     */
    private function colors(array $pages, array $stylesheetCss): array
    {
        $text = implode("\n", [
            ...array_column($pages, 'inline_style_text'),
            ...array_map(fn (array $page): string => implode("\n", $page['inline_style_attributes']), $pages),
            ...$stylesheetCss,
        ]);

        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]+\)|hsla?\([^)]+\)/i', $text, $matches);

        return $this->countedValues($matches[0] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $stylesheetCss
     * @return array<int, array{value: string, count: int}>
     */
    private function fonts(array $pages, array $stylesheetCss): array
    {
        $text = implode("\n", [
            ...array_column($pages, 'inline_style_text'),
            ...array_map(fn (array $page): string => implode("\n", $page['inline_style_attributes']), $pages),
            ...$stylesheetCss,
        ]);

        preg_match_all('/font-family\s*:\s*([^;}]+)/i', $text, $matches);
        preg_match_all('/@font-face\s*{[^}]*font-family\s*:\s*([^;}]+)/i', $text, $fontFaceMatches);

        $fonts = [];

        foreach ($pages as $page) {
            foreach ($page['assets']['stylesheets'] as $stylesheet) {
                if (str_contains($stylesheet, 'fonts.googleapis.com') || str_contains($stylesheet, 'use.typekit.net')) {
                    $fonts[] = $this->fontNameFromUrl($stylesheet);
                }
            }
        }

        foreach ([...($matches[1] ?? []), ...($fontFaceMatches[1] ?? [])] as $fontStack) {
            array_push($fonts, ...$this->fontNamesFromStack($fontStack));
        }

        return $this->countedValues($fonts);
    }

    /**
     * @return array<int, string>
     */
    private function fontNamesFromStack(string $fontStack): array
    {
        return array_values(array_filter(
            array_map(fn (string $font): string => $this->cleanFontName($font), str_getcsv($fontStack)),
            fn (string $font): bool => $font !== '' && ! in_array(strtolower($font), $this->genericFontFamilies(), true),
        ));
    }

    private function fontNameFromUrl(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        if (! isset($query['family'])) {
            return $this->cleanFontName($url);
        }

        return $this->cleanFontName(strtok((string) $query['family'], ':') ?: (string) $query['family']);
    }

    private function cleanFontName(string $font): string
    {
        return trim(str_replace('+', ' ', $font), " \t\n\r\0\x0B\"'");
    }

    /**
     * @return array<int, string>
     */
    private function genericFontFamilies(): array
    {
        return [
            'serif',
            'sans-serif',
            'monospace',
            'cursive',
            'fantasy',
            'system-ui',
            'ui-serif',
            'ui-sans-serif',
            'ui-monospace',
            'ui-rounded',
            'emoji',
            'math',
            'fangsong',
        ];
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, count: int}>
     */
    private function countedValues(array $values): array
    {
        $counts = array_count_values(array_filter(array_map('trim', $values)));
        arsort($counts);

        return array_map(
            fn (string $value, int $count): array => ['value' => $value, 'count' => $count],
            array_keys($counts),
            $counts,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function siteStructure(array $pages): array
    {
        return [
            'page_tree' => array_map(fn (array $page): array => [
                'url' => $page['url'],
                'path' => $page['path'],
                'title' => $page['title'],
                'headings' => $page['structure']['headings'],
            ], $pages),
            'page_paths' => array_column($pages, 'path'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $stylesheetCss
     * @return array<string, mixed>
     */
    private function siteAssets(array $pages, array $stylesheetCss): array
    {
        $assets = [
            'images' => [],
            'responsive_images' => [],
            'scripts' => [],
            'stylesheets' => [],
            'icons' => [],
            'preloads' => [],
            'media' => [],
            'other_files' => [],
            'css_referenced_assets' => $this->cssReferencedAssets($pages, $stylesheetCss),
        ];

        foreach ($pages as $page) {
            foreach (array_keys($assets) as $type) {
                if ($type === 'css_referenced_assets') {
                    continue;
                }

                $assets[$type] = [
                    ...$assets[$type],
                    ...$page['assets'][$type],
                ];
            }
        }

        foreach ($assets as $type => $values) {
            $assets[$type] = array_values(array_unique($values));
        }

        $assets['total'] = array_sum(array_map('count', $assets));

        return $assets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    private function pagesAssetCount(array $pages): int
    {
        return array_sum(array_map(
            fn (array $page): int => (int) ($page['assets']['total'] ?? 0),
            $pages,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $stylesheetCss
     * @return array<int, string>
     */
    private function cssReferencedAssets(array $pages, array $stylesheetCss): array
    {
        $homepageUrl = $pages[0]['url'] ?? null;
        $text = implode("\n", [
            ...array_column($pages, 'inline_style_text'),
            ...array_map(fn (array $page): string => implode("\n", $page['inline_style_attributes']), $pages),
            ...$stylesheetCss,
        ]);

        preg_match_all('/url\(([^)]+)\)/i', $text, $matches);

        if (! is_string($homepageUrl)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $asset): ?string => $this->absoluteUrl($homepageUrl, trim($asset, " \t\n\r\0\x0B\"'")),
            $matches[1] ?? [],
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private function crawlMetadata(string $baseUrl): array
    {
        $origin = (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https').'://'.parse_url($baseUrl, PHP_URL_HOST);

        return [
            'robots_txt' => $this->fetchText($origin.'/robots.txt'),
            'sitemap_xml' => $this->fetchText($origin.'/sitemap.xml'),
        ];
    }

    /**
     * @return array{url: string, reachable: bool, status: ?int, content: ?string}
     */
    private function fetchText(string $url): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->retry(1, 250)
                ->withUserAgent('Stocks Analyzer/1.0')
                ->get($url);

            return [
                'url' => $url,
                'reachable' => $response->successful(),
                'status' => $response->status(),
                'content' => $response->successful() ? Str::limit($response->body(), 20000, '') : null,
            ];
        } catch (\Throwable) {
            return [
                'url' => $url,
                'reachable' => false,
                'status' => null,
                'content' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPage(string $url): array
    {
        return [
            'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH) ?: '/',
            'assets' => [
                'total' => 0,
                'images' => [],
                'responsive_images' => [],
                'scripts' => [],
                'stylesheets' => [],
                'icons' => [],
                'preloads' => [],
                'media' => [],
                'other_files' => [],
            ],
            'links' => [
                'internal' => [],
                'external' => [],
                'mailto' => [],
                'telephone' => [],
            ],
            'content_links' => [
                'internal' => [],
                'external' => [],
                'mailto' => [],
                'telephone' => [],
            ],
            'header_menu_links' => [
                'internal' => [],
                'external' => [],
                'mailto' => [],
                'telephone' => [],
            ],
            'footer_information_links' => [
                'internal' => [],
                'external' => [],
                'mailto' => [],
                'telephone' => [],
            ],
        ];
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        return trim($xpath->query($query)->item(0)?->textContent ?? '') ?: null;
    }

    private function landingDescription(DOMXPath $xpath): ?string
    {
        $queries = [
            '//main//p[string-length(normalize-space()) > 120]',
            '//section//p[string-length(normalize-space()) > 120]',
            '//article//p[string-length(normalize-space()) > 120]',
            '//p[string-length(normalize-space()) > 120]',
        ];

        foreach ($queries as $query) {
            $description = $this->firstText($xpath, $query);

            if ($description) {
                return preg_replace('/\s+/', ' ', $description);
            }
        }

        return null;
    }

    private function language(DOMDocument $document, DOMXPath $xpath): ?string
    {
        $language = trim($document->documentElement?->getAttribute('lang') ?? '');

        if ($language !== '') {
            return $language;
        }

        return $this->attribute($xpath, '//html', 'lang')
            ?? $this->attribute($xpath, '//html', 'xml:lang')
            ?? $this->meta($xpath, 'language')
            ?? $this->attribute($xpath, '//meta[@http-equiv="content-language"]', 'content');
    }

    private function meta(DOMXPath $xpath, string $name): ?string
    {
        return $this->attribute($xpath, "//meta[@name=\"{$name}\"]", 'content');
    }

    /**
     * @return array<string, string>
     */
    private function propertyMeta(DOMXPath $xpath, string $prefix): array
    {
        $values = [];

        foreach ($xpath->query("//meta[starts-with(@property, \"{$prefix}\") or starts-with(@name, \"{$prefix}\")]") as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $key = $node->getAttribute('property') ?: $node->getAttribute('name');
            $content = trim($node->getAttribute('content'));

            if ($key !== '' && $content !== '') {
                $values[$key] = $content;
            }
        }

        return $values;
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        return trim($node->getAttribute($attribute)) ?: null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function headings(DOMXPath $xpath): array
    {
        $headings = [];

        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $heading) {
            $headings[$heading] = $this->nodeTexts($xpath->query('//'.$heading));
        }

        return $headings;
    }

    /**
     * @return array<int, string>
     */
    private function nodeTexts(DOMNodeList $nodes): array
    {
        $texts = [];

        foreach ($nodes as $node) {
            $text = trim($node->textContent ?? '');

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return array<int, string>
     */
    private function styleAttributes(DOMXPath $xpath): array
    {
        $styles = [];

        foreach ($xpath->query('//*[@style]') as $node) {
            /** @var DOMElement $node */
            $styles[] = $node->getAttribute('style');
        }

        return $styles;
    }
}
