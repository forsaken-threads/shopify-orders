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
