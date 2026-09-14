<?php
declare(strict_types=1);

/**
 * Shared title-normalization helper.
 *
 * Produces a lowercase, diacritic-stripped string suitable for storing in
 * products.normalized_title and for normalizing search queries before
 * comparing against that column.
 *
 * Example: "Le Falconé Jawhara" → "le falcone jawhara"
 *
 * Requires the PHP intl extension (normalizer_normalize).
 */
function normalizeTitle(string $s): string
{
    // Decompose characters into base letter + combining diacritical marks.
    $normalized = normalizer_normalize($s, Normalizer::FORM_D);
    if ($normalized === false) {
        return mb_strtolower($s);
    }

    // Strip combining diacritical marks (U+0300–U+036F).
    $s = preg_replace('/[\x{0300}-\x{036F}]/u', '', $normalized) ?? $normalized;

    return mb_strtolower($s);
}

/**
 * Whether a product title names a bundle: the word "bundle" anywhere in it,
 * case-insensitive.
 *
 * Both product writers store products.is_bundle from this, scripts/migrate.php
 * re-derives stored rows with it, and bottles-sold.php applies it to line items
 * whose product is gone — so the rule changes here and nowhere else.
 *
 * Example: "Celebrity Fragrance Bundle #2" → true
 */
function isBundleTitle(string $title): bool
{
    return (bool) preg_match('/\bbundle\b/i', $title);
}
