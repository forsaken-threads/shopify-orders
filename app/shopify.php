<?php
declare(strict_types=1);

/**
 * Shared Shopify Admin API HTTP helpers for CLI sync scripts.
 *
 * Provides rate-limited GET requests, automatic 429 retry with exponential
 * backoff, cursor-based pagination link parsing, and per-product brand
 * metafield fetching.
 *
 * NOT intended for use in web request handlers — relies on $http_response_header
 * which is populated by file_get_contents() and is only reliable in CLI contexts.
 */

/**
 * Perform a rate-limited GET request to the Shopify Admin API.
 *
 * Enforces a minimum 500 ms gap between all calls (≤ 2 req/s, matching
 * Shopify's leaky-bucket leak rate) using a static timestamp.
 *
 * @return array{body: string, status: int, link: string, callLimit: string, retryAfter: int}
 */
function shopifyGet(string $url, string $accessToken): array
{
    static $lastRequestAt = 0.0;
    $minGap = 0.5; // seconds — 2 req/s
    $now    = microtime(true);
    $gap    = $now - $lastRequestAt;
    if ($lastRequestAt > 0.0 && $gap < $minGap) {
        usleep((int) (($minGap - $gap) * 1_000_000));
    }
    $lastRequestAt = microtime(true);

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", [
                'X-Shopify-Access-Token: ' . $accessToken,
                'Accept: application/json',
            ]),
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        return ['body' => '', 'status' => 0, 'link' => '', 'callLimit' => '', 'retryAfter' => 0];
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m);
    $status = (int) ($m[1] ?? 0);

    $link       = '';
    $callLimit  = '';
    $retryAfter = 0;
    foreach ($http_response_header as $header) {
        if (stripos($header, 'Link:') === 0) {
            $link = $header;
        } elseif (stripos($header, 'X-Shopify-Shop-Api-Call-Limit:') === 0) {
            $callLimit = trim(substr($header, strlen('X-Shopify-Shop-Api-Call-Limit:')));
        } elseif (stripos($header, 'Retry-After:') === 0) {
            $retryAfter = (int) trim(substr($header, strlen('Retry-After:')));
        }
    }

    return ['body' => $body, 'status' => $status, 'link' => $link, 'callLimit' => $callLimit, 'retryAfter' => $retryAfter];
}

/**
 * Call shopifyGet with automatic retry on HTTP 429 responses.
 *
 * Respects the Retry-After header when present; falls back to exponential
 * backoff (2 s, 4 s, 8 s, 16 s) when the header is absent.
 *
 * @return array{body: string, status: int, link: string, callLimit: string, retryAfter: int}
 */
function shopifyGetWithRetry(string $url, string $accessToken, int $maxRetries = 4): array
{
    $attempt = 0;
    while (true) {
        $result = shopifyGet($url, $accessToken);

        if ($result['callLimit'] !== '') {
            echo "    [bucket: {$result['callLimit']}]\n";
        }

        if ($result['status'] !== 429) {
            return $result;
        }

        $attempt++;
        if ($attempt > $maxRetries) {
            return $result;
        }

        $wait = $result['retryAfter'] > 0 ? $result['retryAfter'] : (2 ** $attempt);
        echo "  Rate limited (429) — Retry-After: {$wait}s. Waiting before retry {$attempt}/{$maxRetries}…\n";
        sleep($wait);
    }
}

/**
 * Parse the "next" page cursor URL from a Shopify Link header.
 *
 * Shopify returns: Link: <URL>; rel="next", <URL>; rel="previous"
 * Returns the full next-page URL, or null if there is no next page.
 */
function parseNextUrl(string $linkHeader): ?string
{
    if ($linkHeader === '') {
        return null;
    }
    $parts = preg_split('/,\s*(?=<)/', $linkHeader) ?: [];
    foreach ($parts as $part) {
        if (strpos($part, 'rel="next"') !== false) {
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                return $m[1];
            }
        }
    }
    return null;
}

/**
 * Fetch the custom.brand metafield for a single Shopify product.
 *
 * Results are cached in $cache (keyed by product ID) so repeated calls within
 * a sync run never hit the API more than once per product.
 *
 * @param array<string, string|null> $cache  Passed by reference; shared across all calls.
 */
function fetchProductBrand(
    string $shopDomain,
    string $accessToken,
    string $apiVersion,
    string $productId,
    array &$cache
): ?string {
    if (array_key_exists($productId, $cache)) {
        return $cache[$productId];
    }

    $url = sprintf(
        'https://%s/admin/api/%s/products/%s/metafields.json?namespace=custom&key=brand',
        $shopDomain,
        rawurlencode($apiVersion),
        rawurlencode($productId)
    );

    $result = shopifyGetWithRetry($url, $accessToken);

    if ($result['status'] === 0 || $result['body'] === '') {
        error_log(sprintf('[sync] fetchProductBrand: request failed for product %s', $productId));
        $cache[$productId] = null;
        return null;
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log(sprintf('[sync] fetchProductBrand: HTTP %d for product %s', $result['status'], $productId));
        $cache[$productId] = null;
        return null;
    }

    try {
        $data = json_decode($result['body'], associative: true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log(sprintf('[sync] fetchProductBrand: invalid JSON for product %s: %s', $productId, $e->getMessage()));
        $cache[$productId] = null;
        return null;
    }

    $value             = $data['metafields'][0]['value'] ?? null;
    $cache[$productId] = ($value !== null && $value !== '') ? (string) $value : null;
    return $cache[$productId];
}
