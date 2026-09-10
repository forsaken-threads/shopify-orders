<?php
declare(strict_types=1);

/**
 * Shared CSV formula-injection guard.
 *
 * Excel, LibreOffice and Google Sheets evaluate a field that opens with
 * = + - @ as a formula when the download is opened.  Names and addresses in
 * these exports come from whoever filled in the Shopify checkout, and the
 * reports permission is admin-and-above, so an unguarded export carries a
 * stranger's payload onto an administrator's machine.
 */

/**
 * Prefix $value with an apostrophe when a spreadsheet would read it as a
 * formula, and return it untouched otherwise.
 *
 * Only a value that would actually execute is altered — a blanket prefix would
 * reach every ordinary name, and these same rows are meant to feed mailing
 * labels, where 'Smith would print.  The check trims first because leading
 * whitespace ahead of the = does not stop a spreadsheet evaluating it, while
 * the prefix goes on the original so nothing else about the value changes.
 *
 * Apply this to customer-supplied text only.  A leading - is legitimate on a
 * negative number, and the exports' own numeric columns are formatted here
 * rather than typed by anyone.
 */
function csvSafe(string $value): string
{
    $trimmed = ltrim($value, " \t\n\r\0\x0B");

    return $trimmed !== '' && str_contains('=+-@', $trimmed[0])
        ? "'" . $value
        : $value;
}
