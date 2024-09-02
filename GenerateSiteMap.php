<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use DOMDocument;
use DOMXPath;

/**
 * Command to generate a sitemap for the website.
 *
 * Skipping Patterns:
 * - URLs containing "javascript:void(0)"
 * - URLs starting with "tel:"
 * - URLs containing non-breaking spaces (U+00A0)
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate {--visit-external}';
    protected $description = 'Generate the sitemap for the website';

    private $visitedUrls = [];
    private $sitemapUrls = [];
    private $baseUrls = [
        '/',
    ];
    private $skippedUrls = []; // To store skipped URLs

    // Patterns to skip
    private $skippingPatterns = [
        "/\xC2\xA0/", // non breaking space
        '/javascript:void\(0\)/i',  // Match javascript:void(0) anywhere in the URL
        '/tel:/i',
        // '/tel:\+\d+/i',             // Match telephone URLs
        // '/^#/i',                    // Match anchor links
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting to generate sitemap...');

        $visitExternal = $this->option('visit-external');

        foreach ($this->baseUrls as $basePath) {
            $baseUrl = URL::to($basePath);
            $this->info('Crawling base URL: ' . $baseUrl);
            $this->crawlUrl($baseUrl, $baseUrl, $visitExternal);
        }

        $sitemap = view('sitemap', ['urls' => $this->sitemapUrls])->render();
        File::put(public_path('sitemap.xml'), $sitemap);

        // Save skipped URLs to a file
        $this->saveSkippedUrls();

        $this->info('Sitemap generated successfully!');
    }

    private function crawlUrl($url, $baseUrlContext = null, $visitExternal = false)
    {
        $url = rtrim($url, '/');

        if ($this->containsUnwantedPatterns($url)) {
            $this->info('Skipping URL with unwanted pattern: ' . $url);
            $this->skippedUrls[] = $url;
            return;
        }

        if (in_array($url, $this->visitedUrls)) {
            return;
        }

        if (!$visitExternal && !$this->isInternalLink($url)) {
            return;
        }

        if ($baseUrlContext && !$this->isUrlInContext($url, $baseUrlContext)) {
            return;
        }

        $this->info('Crawling: ' . $url);
        $this->visitedUrls[] = $url;

        try {
            $response = Http::get($url);

            if ($response->successful()) {
                if (!in_array($url, $this->sitemapUrls)) {
                    $this->sitemapUrls[] = $url;
                }
                $html = $response->body();
                $this->extractAndCrawlLinks($html, $url, $baseUrlContext, $visitExternal);
            }
        } catch (\Exception $e) {
            $this->error('Error crawling ' . $url . ': ' . $e->getMessage());
        }
    }

    private function extractAndCrawlLinks($html, $currentUrl, $baseUrlContext = null, $visitExternal = false)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');
        $filteredUrls = [];

        // First, filter and collect URLs
        foreach ($nodes as $node) {
            $href = $node->getAttribute('href');
            $fullUrl = $this->resolveUrl($href, $currentUrl);

            if ($this->containsUnwantedPatterns($fullUrl)) {
                $this->info('Skipping URL with unwanted pattern: ' . $fullUrl);
                $this->skippedUrls[] = $fullUrl;
                continue;
            }

            $this->crawlUrl($fullUrl, $baseUrlContext, $visitExternal);
        }
    }

    private function containsUnwantedPatterns($url)
    {
        foreach ($this->skippingPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        return false;
    }

    private function resolveUrl($url, $currentUrl)
    {
        if (parse_url($url, PHP_URL_HOST) === null) {
            return URL::to(rtrim($currentUrl, '/') . '/' . ltrim($url, '/'));
        }

        return $url;
    }

    private function isInternalLink($url)
    {
        $baseHost = parse_url(URL::to('/'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $urlHost === $baseHost || empty($urlHost);
    }

    private function isUrlInContext($url, $baseUrlContext)
    {
        return strpos($url, $baseUrlContext) === 0;
    }

    private function saveSkippedUrls()
    {
        $uniqueSkippedUrls = array_unique($this->skippedUrls);
        $filePath = public_path('skipped_urls.txt');
        File::put($filePath, implode(PHP_EOL, $uniqueSkippedUrls));
        $this->info('Skipped URLs saved to: ' . $filePath);
    }
}
