<?php
declare(strict_types=1);

/**
 * Application changelog.
 *
 * Each entry is an associative array with:
 *   version  — semver string; must match app_version in app/config.php for the
 *              "current" entry (the top of the list).
 *   date     — release date (YYYY-MM-DD) shown in the modal.
 *   title    — short headline describing the release.
 *   notes    — list of user-facing bullet points.
 *
 * Newest entries first.  When you bump app_version in app/config.php, add a
 * new entry here in the same commit so deployed users see the changes in the
 * header's release modal.
 */

return [
    [
        'version' => '1.20.0',
        'date'    => '2026-08-25',
        'title'   => 'Print a label for any product, without an order',
        'notes'   => [
            'There is a new Products page in the top menu.  Type a couple of letters of a perfume\'s name, pick it from the list that appears, choose 1ml, 5ml or 10ml, and print.  It is for the times a label is damaged or lost and you just need another one -- you no longer have to find the order it came from.',
            'The label starts out with the title and brand this product is normally printed with, and both are editable before you print.  As with the one-off Print button on an order, an edit is remembered as this product\'s new preferred wording unless you tick "Don\'t save edits".',
            'Size starts at 1ml, which is what the great majority of labels are, and each press of Print sends one label.  Press it twice for two.',
            'Bundles are deliberately left out of the search: a bundle has no ml size of its own, and its labels are still printed from the Bundles page.',
            'Anyone who can print labels from an order can use this -- the same people, no new permission to hand out.',
        ],
    ],
    [
        'version' => '1.19.0',
        'date'    => '2026-07-30',
        'title'   => 'See which discount code an order was placed with',
        'notes'   => [
            'The Orders list has a new Code column showing the discount code an order was placed with -- VIP10, NEWCUSTOMER5, whatever it happened to be.  Orders placed without a code leave the column empty, and the handful that used two codes show both.',
            'This is what makes a promotion measurable in the flow of work: VIP10 redemptions show up as the orders come through, rather than needing a report to be run.',
            'Only codes somebody actually typed in are shown.  Automatic discounts -- the kind Shopify applies on its own without a code being entered -- have no code to show, so those orders look the same here as undiscounted ones.  That is about half of all discounted orders, so read the column as "which code", not as "was this discounted".',
            'Existing orders get their codes filled in by the deploy step below, so the history is there straight away.  VIP10 itself has not been used yet, so what shows at first is older promotions -- that is expected rather than a fault.',
            'Deploy step: production needs `php scripts/migrate.php` for the new discount_codes column, and then `php scripts/backfill-discount-codes.php --apply` to fill it in for orders that already exist.',
        ],
    ],
    [
        'version' => '1.18.0',
        'date'    => '2026-07-30',
        'title'   => 'VIPs: a nightly top-fifty ranking, a report, and a badge on your orders',
        'notes'   => [
            'The app now works out a VIP list every night at 3:00 AM, just after the paid-orders sync so it counts the night\'s orders rather than yesterday\'s.  A customer earns a star for each of four measures they place in the top fifty of -- money spent and items bought, each counted over the trailing six months and over all time -- and the fifty highest star totals become that night\'s VIPs.',
            'Reports has a new VIPs card showing the current list: rank, customer, how many stars they hold and which ones, and the spend and item figures behind them for both windows.  It has its own Download CSV button, and like the other reports it is admin and root only.',
            'Orders now carry a VIP badge -- a dark VIP pill followed by four stars, filled in for the ones that customer holds.  It shows on the order list, on the single order page, and in the header search results, so you can see who you are dealing with without opening a report.  Hover a star to see which measure it stands for.',
            'Two things worth knowing about the list.  Every night is kept rather than overwritten, so a day is only ever scored once -- re-running it reports and stops instead of replacing that day\'s rows.  And customers who tie on all four measures are separated at random, so the last few places can differ from one night to the next even when nothing underneath has changed.  That is expected rather than a fault.',
            'Nobody is a VIP until the nightly job has run for the first time.  Until then no badge appears anywhere and the report says plainly that there is no list yet.',
            'Deploy step: production needs `php scripts/migrate.php` for the new vip_scores table and `sudo scripts/publish-artifacts.sh` to install the 3:00 AM cron entry.',
        ],
    ],
    [
        'version' => '1.17.0',
        'date'    => '2026-07-28',
        'title'   => 'Print several copies of a bundle at once',
        'notes'   => [
            'The bundle print modal has a new Copies box in the bottom left.  Set it to 3 and you get three full sets of labels -- three bundle name labels and three of each component -- in a single run, instead of reopening the modal for each one.',
            'The Qty column and the total label count update as you type, so you can see exactly how many labels are coming before you print.',
            'If some labels fail and you land on the retry screen, Copies is still editable.  A row is marked failed even when only one of its copies did not print, so turning Copies down to 1 before hitting Retry avoids reprinting the ones that came out fine.',
        ],
    ],
    [
        'version' => '1.16.0',
        'date'    => '2026-07-13',
        'title'   => 'Top Customers: rank by orders, and compare any window against all time',
        'notes'   => [
            'The Top Customers report now ranks five ways: total spent, total orders, total items, spend per order (average order value), and spend per item.',
            'The "spend per order" view only considers repeat customers -- those with at least two orders -- so it shows who reliably places large orders rather than who happened to place one big one.  "Spend per item" keeps its existing volume floor.',
            'When you pick a timeframe other than all time, a new column on the right shows each customer\'s lifetime figure for whatever you are ranking by.  Someone with 12 orders in the last year and 35 all time reads very differently from someone with 12 and 12, and now you can see which is which at a glance.',
            'The report now opens on the last 30 days instead of all time, so recent activity is what you see first.  The Download CSV button still matches whatever ranking and timeframe you have selected, lifetime column included.',
        ],
    ],
    [
        'version' => '1.15.0',
        'date'    => '2026-07-10',
        'title'   => 'Rank top customers by items or spend-per-item, over any timeframe',
        'notes'   => [
            'The Top Customers report can now rank three ways: total amount spent (the original), total number of items purchased, and average spend per item.  The "spend per item" view only considers customers who buy in real volume (the top ~40% by item count), so a single big one-off order does not float someone to the top.',
            'Each row now shows orders, items, total spent, and dollars-per-item together, and the column being used to rank is highlighted so it is clear what the list is sorted by.',
            'A timeframe picker lets you limit the ranking to a recent window -- all time, the last 30 or 90 days, year to date, or the trailing 12 months -- handy for finding who has been buying lately rather than ever.  The Download CSV button always matches whatever ranking and timeframe you have selected.',
        ],
    ],
    [
        'version' => '1.14.0',
        'date'    => '2026-07-10',
        'title'   => 'Top Customers report with mailing addresses',
        'notes'   => [
            'The Reports page has a new "Top Customers" section that lists your highest-spending customers along with their mailing addresses.  Choose how many to show (100 by default) and press Load to see the ranking on screen, or press Download CSV to save the whole list as a spreadsheet.',
            'Each row shows the customer\'s name, email, number of orders, total spent, and the mailing address from their most recent order -- everything you need to send them something in the mail.',
        ],
    ],
    [
        'version' => '1.13.0',
        'date'    => '2026-06-17',
        'title'   => 'Company contact details on the earnings statement',
        'notes'   => [
            'The printable earnings statement now shows the company phone number, website, and email beneath the title in the letterhead, so a printed or PDF copy carries our contact information.',
        ],
    ],
    [
        'version' => '1.12.0',
        'date'    => '2026-06-17',
        'title'   => 'Printable earnings statement for an employee',
        'notes'   => [
            'The Time cards page now has an "Earnings statement" button that appears once you pick an hourly employee.  It opens a clean, official-looking sheet of that employee\'s paid weeks for the year -- week ending, hours, pay rate, amount, and the date paid -- with a total at the bottom, ready to print or save as a PDF.',
            'Only weeks that have been marked paid are listed, and the dollar figures are exactly what was recorded as paid at the time -- so the statement always matches what was actually disbursed.  It only ever reflects pay tracked in the system; nothing from before is included.',
            'Defaults to the current year.  If an employee has paid weeks spanning more than one year, a year picker appears so you can print a statement for an earlier year.',
        ],
    ],
    [
        'version' => '1.11.0',
        'date'    => '2026-06-11',
        'title'   => 'Print labels for bundles that have gone to draft',
        'notes'   => [
            'Bundle Lookup can now show completed bundles whose Shopify product has been set to draft, so you can reprint labels for previous orders without having to reactivate a product you no longer carry.',
            'Draft bundles stay hidden by default.  Tick the new "Include draft bundles" checkbox above the lookup list to reveal them; each draft bundle is marked with a "Draft" badge.  The checkbox only appears when there is at least one completed draft bundle.',
        ],
    ],
    [
        'version' => '1.10.0',
        'date'    => '2026-06-02',
        'title'   => 'Items without an ML size default to 1ml when printing',
        'notes'   => [
            'When a line item has no ML size associated with it, label printing now treats it as a 1ml instead of leaving it blank.  Previously such an item hid its one-off Print button and would fail with an "Invalid or missing ML size" error if printed as part of a full order.',
            'This applies everywhere labels are printed: the full-order print modal, the one-off print button on the order detail and orders pages, and bundle component rows that have no ML variants (their ML dropdown now defaults to 1ml).',
        ],
    ],
    [
        'version' => '1.9.0',
        'date'    => '2026-05-28',
        'title'   => 'Product Profitability now breaks out fulfilled orders',
        'notes'   => [
            'Reports -> Product Profitability now shows two sets of figures side by side: "All Orders" (every paid order, as before) and "Fulfilled" (only orders that have shipped).  Both the summary pills at the top and the per-variant table now carry a matching Fulfilled column group.',
            'The difference between the two is, roughly, what has been sold but not yet decanted and shipped — useful for checking how much should still be left in a bottle against how much is already committed to pending orders.',
        ],
    ],
    [
        'version' => '1.8.0',
        'date'    => '2026-05-21',
        'title'   => 'Refunded and removed items no longer print',
        'notes'   => [
            'When an order is edited or refunded to remove line items, those items no longer appear on the order or its print labels.  Previously a line item removed shortly after an order came in could still be picked and printed days later -- the order tracked the originally ordered amounts rather than what was actually still owed.  Now the order always reflects the current quantities, and an item removed entirely drops off the order.',
            'A one-time correction was run against orders that had not yet shipped, so any already-affected pending order now shows the right items.  Orders that already shipped are left exactly as they were.',
        ],
    ],
    [
        'version' => '1.7.0',
        'date'    => '2026-05-12',
        'title'   => 'Masquerade as another user (root only)',
        'notes'   => [
            'Users page: root users now see a Masquerade button on every active user row (except their own).  Clicking it switches your session to that user — for the duration of the masquerade you have exactly that user\'s permissions, no more and no less.  Useful for reproducing what an employee is seeing without asking them to share their password.',
            'While masquerading, the header user button shows the masqueraded user\'s name followed by "(your-original-username)" so it\'s always visible at a glance.  The user menu gains a "Log out as <username>" link that reverts you to your original session.  Everything else looks completely normal.',
            'Nested masquerades are blocked — stop the current one first.  If the original (root) account is deactivated or stripped of root mid-masquerade, the whole session is torn down on the next request rather than silently dropping you into a role you didn\'t choose.',
        ],
    ],
    [
        'version' => '1.6.0',
        'date'    => '2026-05-12',
        'title'   => 'Hourly rates and payroll tracking',
        'notes'   => [
            'Users page (admin and root): the Edit user modal now has a "Paid hourly" checkbox.  When on, a new Rates button on the row opens a modal for managing that user\'s rate history.  Each rate row records a dollar amount and an effective date range; either side of the range can be left blank for "no lower bound" / "still in effect".',
            'Rate effective dates are constrained to pay-week-start days.  The date picker only allows the configured weekday (e.g. Saturday); any direct-typed date snaps back to the pay week containing it.  Overlapping ranges are rejected with a message that names the pay weeks in conflict.',
            'Time cards page: when viewing an approved week for an hourly user, a new "Pay" cell shows the dollar amount earned (total hours × the rate in effect that week) and a Mark paid button records the payment.  Marking paid stores the amount along with who marked it and when, and locks the week from being re-opened.',
            'Paid weeks render as a green approval bar with the paid-by / paid-at info instead of the amber "approved" bar.  Hourly users with no rate on file for the viewed week see a clear warning and the Mark paid button is disabled until the rate is set.',
            'Time cards employee dropdown is now display-name-only (with [inactive] suffix where applicable), and the page pane is widened to match Orders / Reports / Users.',
            'Deploy step: production needs `php scripts/migrate.php` for the new hourly_rates table and the paid_at / paid_by / amount_paid columns on timecard_approvals.',
        ],
    ],
    [
        'version' => '1.5.1',
        'date'    => '2026-05-12',
        'title'   => 'Responsive header for narrower screens',
        'notes'   => [
            'Below 1200px wide, the top-nav links (Orders, Reports, Charts, Bundles, Clock, Time cards) collapse into a hamburger menu next to the brand.  Search, the release bell, and the user menu stay where they were.',
            'Below 700px wide, the header wraps into three rows — brand + hamburger, then the search bar full-width, then the bell + user menu — instead of hiding the user menu like before.  All text and spacing also shrink slightly at this size to reduce awkward wrapping on tight layouts.',
            'The non-production environment badge (e.g. "STAGING") now floats in the lower-left corner of the page instead of sitting in the middle of the header, so it no longer competes with the nav links for space.',
        ],
    ],
    [
        'version' => '1.5.0',
        'date'    => '2026-05-11',
        'title'   => 'Time clock and weekly time cards',
        'notes'   => [
            'New Clock page (in the nav for everyone) — Clock In / Clock Out, current shift state, this-week total, and a list of shifts in the current pay-period week.  Designed to be usable from a phone.',
            'New Time cards page (admin and root) — pick an employee and a pay-period week, view their shifts, edit or delete any punch, add a missing one, and approve the week.  Approving locks the week from further edits until an admin re-opens it.',
            'Pay-period week start is configurable via PAY_WEEK_START in env.ini.  Supports "sun" (default), "mon", and "sat".',
            'Stale-shift safety: if you forgot to clock out from a previous day, the Clock page locks you out of clock-out and tells you to ask your manager — they fix the actual time on the Time cards page instead of you guessing.',
            'Header search ("Search orders…") is now only visible to Data Entry and up, since Basic Employees don\'t have any access to orders to begin with.',
            'Deploy step: production needs `php scripts/migrate.php` to add the time_punches and timecard_approvals tables, and PAY_WEEK_START set to "sat" in env.ini for the Saturday → Friday pay cadence.',
        ],
    ],
    [
        'version' => '1.4.0',
        'date'    => '2026-05-11',
        'title'   => 'Role-based access',
        'notes'   => [
            'Every account now has a role.  Four levels, lowest to highest: Basic Employee (no app access yet — placeholder for the upcoming clock-in/out feature), Data Entry (Orders + Bundles), Admin (+ Reports, Charts, and user management), Root (+ Shopify install / re-auth).',
            'Bootstrap: the existing "root" account is now Root, "admin" is Admin, and any other existing user starts as Basic Employee.  Adjust roles on the Users page as needed.',
            'Nav links hide automatically when the signed-in user can\'t use them — Basic Employees see only Profile and Sign out in the menu.',
            'On the Users page, the Edit / Reset password / Deactivate buttons are disabled for any user whose role is higher than yours, and the role selector only offers roles at or below your own.  You can edit your own name and email but not your own role.',
            'Tightened: Shopify install / re-auth is now Root-only.  If you previously visited /install.php as the admin account, sign in as root for that flow.',
        ],
    ],
    [
        'version' => '1.3.0',
        'date'    => '2026-05-11',
        'title'   => 'Real login, profile editing, and user management',
        'notes'   => [
            'Signing in now uses a proper login form at /login.php instead of the browser\'s HTTP Basic prompt.  Sessions are cookie-based and end when you close the browser; click your name in the header to sign out.',
            'Forgot your password?  Use the link on the sign-in page — if your account has an email address on file, you\'ll receive a one-hour, single-use reset link.',
            'New Profile page (header → your name → Profile) lets you update your display name and email, and change your password without going through the reset flow.',
            'New Users page (header → your name → Users) lets you create new accounts and deactivate ones that no longer need access.  Deactivated accounts are blocked from signing in immediately, not just at session expiry.',
            'Bootstrap step: existing accounts have no email yet — sign in with your current password and visit Profile to add one before the forgot-password flow can reach you.',
            'Deploy step: production needs `composer install --no-dev` once after pulling, plus SMTP credentials in env.ini for outgoing reset emails.',
        ],
    ],
    [
        'version' => '1.2.1',
        'date'    => '2026-05-11',
        'title'   => 'Fix: API endpoints failing with duplicate getDb() declaration',
        'notes'   => [
            'Fixed a fatal error on every /api/* endpoint introduced in 1.2.0, where db.php was loaded twice and PHP refused to redeclare getDb().',
            'All includes of db.php and auth.php now use require_once so the files cannot be loaded more than once per request.',
            'Routed the print and order-detail modal fetches through apiUrl() so they work for users whose Basic Auth password contains "@".',
        ],
    ],
    [
        'version' => '1.2.0',
        'date'    => '2026-05-11',
        'title'   => 'Release notifications and per-user accounts',
        'notes'   => [
            'New bell icon in the header shows a red dot when a release you haven\'t seen yet is available.',
            'Clicking the bell opens a modal with the full changelog and marks the latest version as seen for your account.',
            'Logins are now backed by a users table in the database instead of a single shared credential in env.ini.',
            'Each user has a name and a JSON preferences blob (currently tracking the last release version seen).',
            'Run scripts/add-user.php to interactively create additional accounts.',
        ],
    ],
    [
        'version' => '1.1.0',
        'date'    => '2026-05-11',
        'title'   => 'Product profitability: ml sold',
        'notes'   => [
            'Added an "ML Sold" column to the Product Profitability report so volume can be compared alongside revenue and margin.',
        ],
    ],
    [
        'version' => '1.0.0',
        'date'    => '2026-04-23',
        'title'   => 'Initial release',
        'notes'   => [
            'Shopify orders ingested in real time via signed webhooks and stored locally in SQLite.',
            'Orders page with status filters (pending, printed, fulfilled, archived), pagination, and expandable line items.',
            'Header search modal (Ctrl/Cmd+K) for fast lookup by order number, customer name, or email.',
            'Per-order detail page with print-label workflow, including one-off labels, reprints, and ML-size variants.',
            'Print jobs delivered over SSH to a configurable label-printer host, with retry and network-error handling.',
            'Bundles management: curate which products make up a bundle, mark complete/reopen, and print a simplified two-line bundle label.',
            'Local product catalog kept in sync with Shopify, including preferred title/brand overrides for label printing.',
            'Reports section with Product Profitability and revenue-by-ml breakdowns.',
            'Charts section including Orders Per Day with fulfillment overlay and toggleable burn-rate line.',
            'Storefront password-protection toggle script for putting the shop behind a password.',
            'Non-production APP_ENV banner in the header so staging/dev deployments are visually distinct.',
        ],
    ],
];
