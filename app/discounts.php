<?php
declare(strict_types=1);

/**
 * Shared discount-code extraction.
 *
 * Builds the stored value for orders.discount_codes from a decoded Shopify
 * order: every code the order carries, uppercased, comma-joined and wrapped in
 * delimiting commas.  Empty string when the order carried none.
 *
 *   ',VIP10,NEWCUSTOMER5,'   two codes
 *   ',VIP10,'                one code
 *   ''                       none
 *
 * Both ingest paths and the backfill call this, so a row written by the webhook
 * and the same row rebuilt from raw_data cannot disagree.
 */
function discountCodesFor(array $order): string
{
    $codes = [];
    foreach ($order['discount_codes'] ?? [] as $entry) {
        // Shopify matches codes case-insensitively at checkout, so 'vip10' is a
        // reachable spelling of VIP10.  Uppercasing on write keeps lookups exact.
        $code = strtoupper(trim((string) ($entry['code'] ?? '')));
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    if ($codes === []) {
        return '';
    }

    // The wrapping commas are load-bearing rather than formatting: they let
    // LIKE '%,VIP10,%' match a whole code, where '%VIP10%' would also hit VIP100.
    return ',' . implode(',', $codes) . ',';
}
