<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use JsonException;

/**
 * Breadcrumbs Class
 *
 * Navigation breadcrumbs (Home > Policies > Policy 123) represented as data rather than HTML:
 * markup and CSS classes remain in the application template.
 *
 * Rules:
 *   - the object is immutable: add() returns a clone (no global state, no singleton);
 *   - URLs are local paths ('/policies') or http(s); 'javascript:', '//evil.example' and control
 *     characters are rejected;
 *   - the last item is the current page (active = true); should not be rendered as a link in templates;
 *   - labels are not escaped: escaping should be performed at rendering time.
 *
 * Usage:
 *   require_once 'Breadcrumbs.php';
 *   use Foundry\Breadcrumbs;
 *
 *   $breadcrumbs = (new Breadcrumbs)
 *       ->add('Home', '/')
 *       ->add('Policies', '/policies')
 *       ->add('Policy 123');
 *
 *   foreach ($breadcrumbs->items() as $item) { ... $item['label'], $item['url'], $item['active'] ... }
 *
 *   <script type="application/ld+json"><?= $breadcrumbs->schemaJson('https://app.example.com') ?></script>
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Breadcrumbs
{
    /** @var list<array{label: string, url: ?string}> */
    private array $items = [];

    /**
     * Adds an item (at the end). Omit URL for items that should not be links.
     *
     * @throws InvalidArgumentException If the label is empty or the URL is invalid.
     */
    public function add(string $label, ?string $url = null): self
    {
        $label = trim($label);
        if ($label === '' || ! mb_check_encoding($label, 'UTF-8')) {
            throw new InvalidArgumentException('Breadcrumbs::add(): the label is empty or not valid UTF-8.');
        }

        if ($url !== null) {
            $this->assertUrl($url);
        }

        $copy = clone $this;
        $copy->items[] = ['label' => $label, 'url' => $url];

        return $copy;
    }

    /**
     * Returns the breadcrumb items in order; the last item is the current page.
     *
     * @return list<array{label: string, url: ?string, active: bool, position: int}>
     */
    public function items(): array
    {
        $last = count($this->items) - 1;
        $items = [];
        foreach ($this->items as $index => $item) {
            $items[] = ['label' => $item['label'], 'url' => $item['url'], 'active' => $index === $last, 'position' => $index + 1];
        }

        return $items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Generates schema.org BreadcrumbList structured data (JSON-LD, for search engines) with absolute URLs.
     *
     * @param  string  $baseUrl  Application base URL ('https://app.example.com'), used for local paths.
     * @return array{'@context': string, '@type': string, itemListElement: list<array<string, int|string>>}
     *
     * @throws InvalidArgumentException If the base URL is not an absolute http(s) URL.
     */
    public function schema(string $baseUrl): array
    {
        if (preg_match('#^https?://[^/\s?\#\\\\]+(/[^\s?\#\\\\]*)?$#i', $baseUrl) !== 1) {
            throw new InvalidArgumentException('Breadcrumbs::schema(): the base URL must be an absolute http(s) URL without query string.');
        }

        $elements = [];
        foreach ($this->items() as $item) {
            $element = ['@type' => 'ListItem', 'position' => $item['position'], 'name' => $item['label']];
            if ($item['url'] !== null) {
                $element['item'] = str_starts_with($item['url'], '/') ? rtrim($baseUrl, '/').$item['url'] : $item['url'];
            }

            $elements[] = $element;
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * Returns schema() JSON-encoded, safe for <script type="application/ld+json">: '<', '>', '&' and
     * quotes are encoded so that a label containing '</script>' cannot close the HTML tag.
     *
     * @throws InvalidArgumentException If the base URL is invalid or the data cannot be encoded.
     */
    public function schemaJson(string $baseUrl): string
    {
        try {
            return json_encode($this->schema($baseUrl), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Breadcrumbs::schemaJson(): cannot encode the data: {$e->getMessage()}.", 0, $e);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertUrl(string $url): void
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new InvalidArgumentException('Breadcrumbs::add(): the URL is empty or contains spaces, control characters or backslashes.');
        }

        $local = str_starts_with($url, '/') && ! str_starts_with($url, '//');
        if (! $local && preg_match('#^https?://[^/?\#]+#i', $url) !== 1) {
            throw new InvalidArgumentException('Breadcrumbs::add(): the URL must be a local path (/...) or an absolute http(s) URL.');
        }
    }
}
