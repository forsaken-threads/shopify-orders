<?php
declare(strict_types=1);

/**
 * Customer-level aggregates over the orders table.
 *
 * Callers supply their own PDO, so nothing here depends on the web stack —
 * the reports endpoint and batch jobs run the same query.
 */

/**
 * Per-customer aggregate across every customer, optionally limited to orders on
 * or after $dateMin.  Returned keyed by lowercased email.
 *
 * Spend and order count are order-level and item count is line-item level; the
 * two are aggregated separately and joined per customer so the order total is
 * never multiplied across line items.
 */
function customerAggregates(PDO $db, ?string $dateMin): array
{
    // Two distinct placeholders: PDO_SQLite can't reuse a named param in one query.
    $ordDateFilter = $dateMin !== null ? ' AND shopify_created_at   >= :dmin_ord' : '';
    $itmDateFilter = $dateMin !== null ? ' AND o.shopify_created_at >= :dmin_itm' : '';

    $sql = "
        WITH ord AS (
            SELECT lower(customer_email) AS ek,
                   ROUND(SUM(total_price), 2) AS spent,
                   COUNT(*)                   AS order_count
            FROM   orders
            WHERE  TRIM(customer_email) != ''{$ordDateFilter}
            GROUP  BY lower(customer_email)
        ),
        itm AS (
            SELECT lower(o.customer_email) AS ek,
                   SUM(oli.quantity)       AS items
            FROM   orders            o
            JOIN   order_line_items  oli ON oli.order_id = o.id
            WHERE  TRIM(o.customer_email) != ''{$itmDateFilter}
            GROUP  BY lower(o.customer_email)
        )
        SELECT ord.ek                    AS email_key,
               ord.spent                 AS spent,
               ord.order_count           AS order_count,
               COALESCE(itm.items, 0)    AS items
        FROM   ord
        LEFT   JOIN itm ON itm.ek = ord.ek
    ";

    $stmt = $db->prepare($sql);
    if ($dateMin !== null) {
        $stmt->bindValue(':dmin_ord', $dateMin);
        $stmt->bindValue(':dmin_itm', $dateMin);
    }
    $stmt->execute();

    $out = [];
    foreach ($stmt as $r) {
        $ek     = (string) $r['email_key'];
        $spent  = (float) $r['spent'];
        $orders = (int) $r['order_count'];
        $items  = (int) $r['items'];
        $out[$ek] = [
            'email_key'   => $ek,
            'spent'       => $spent,
            'order_count' => $orders,
            'items'       => $items,
            'per_item'    => $items > 0 ? round($spent / $items, 2) : 0.0,
            'per_order'   => round($spent / $orders, 2),
        ];
    }

    return $out;
}
