# TODO.md

Project backlog, organized like an agile board.  Move items between columns
as priorities shift and as work ships.  Keep entries terse — link to context
rather than restate it here.  **Cap "Now Have" at the most recent 10 entries**;
older shipped work lives in `app/changelog.php`, which is definitive.

See `CLAUDE.md` for project conventions.  See `app/changelog.php` for the
authoritative log of shipped releases.

## Must Have

- **Payroll report.**  Surface totals across employees and pay weeks using
  `timecard_approvals.amount_paid` (and the punch hours behind it).  Most
  likely lives under Reports.  Charts may come as a follow-up.

## Nice To Have

- **"New since you last looked" indicators on the changelog modal.**  Today
  only the very latest release shows the unseen-dot on the bell.  Extend
  to highlight every release entry newer than the signed-in user's
  `preferences.last_version_seen` in the modal body itself.
- **Paginate the changelog modal.**  It currently renders every release
  in a single scroll.  Will grow unwieldy as the project matures; page
  size of ~10 entries with a "Show older" affordance is probably enough.

## Now Have

Items move here after they ship; keep only the most recent 10.
`app/changelog.php` is the authoritative log of everything older.

- **1.6.0** — Hourly rates with effective date ranges, plus a Mark-paid
  workflow on the Time cards page that records `paid_at` / `paid_by` /
  `amount_paid`.
- **1.5.1** — Responsive header: hamburger drawer at <1200px, three-row
  stacked navbar at ≤700px, env badge floats lower-left.
- **1.5.0** — Time clock and weekly time cards.
