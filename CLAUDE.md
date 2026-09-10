# CLAUDE.md

Guidance for future Claude sessions working on this repo.  This is the
rulebook, not just a description — read it at the start of every session.

## What this is

**Cent Notes** — an internal PHP/SQLite web app for Decantalize.  Ingests
Shopify orders via webhook, prints labels over SSH, manages bundle products
and a curated catalog, runs reports / charts, gates access by role, and
tracks employee time clock + payroll.  Single-tenant, deployed behind HTTPS
on one server.

Stack: PHP 8.4+, SQLite via PDO, vanilla JS inline in each page (no JS
framework), composer for the one vendored dep (`phpmailer`).

## Always check at session start

- **The board** — `torpedo ~/Boards/ForsakenThreads/cent-notes` tracks
  everything outstanding for this repo.  It lives outside the repo, so it
  never shows up in `git status`; consult it before suggesting new work.
  It carries only what has not shipped — `app/changelog.php` stays
  authoritative for what has.
- **`app/changelog.php`** — authoritative release history.  Every user-visible
  change is paired with an entry here at the time of version bump.
- **Auto-memory** (`~/.claude/projects/-home-keith-Projects-ForsakenThreads-cent-notes/memory/`)
  — feedback the user has given me across prior sessions.

## Database

- SQLite file at `orders.sqlite` in the project root.  Always go through
  `getDb($config)` in `app/db.php`; never instantiate PDO directly.
- **`scripts/migrate.php` is the single source of truth for schema.**  All
  DDL lives there; never `CREATE TABLE` / `ALTER TABLE` elsewhere.
- New tables: `CREATE TABLE IF NOT EXISTS` (idempotent).
- New columns: `ALTER TABLE ... ADD COLUMN` wrapped in
  `try { ... } catch (\PDOException) {}` — SQLite throws a fatal on re-adds,
  the catch keeps the migration idempotent.  Echo a one-line "Added X
  column" success message inside the try, to mirror the existing pattern.
- After any schema change, the feature commit message body must include a
  deploy line: ``Deploy step: production needs `php scripts/migrate.php`...``.
- All timestamps stored as UTC `'YYYY-MM-DD HH:MM:SS'` text.  Convert to the
  display timezone in PHP, never in SQL.

## Permissions & auth

- Source of truth: `app/permissions.php`.
- Four additive roles, low → high:
  `basic_employee` → `data_entry` → `admin` → `root`.
  Map roles → permissions in `PERMISSIONS_BY_ROLE`.  Adding a permission
  means editing that one map.
- Page gates: `requirePermission($config, 'permission_name')` at the top of
  every `public/*.php` after loading config.  Returns the user array or
  redirects to `/index.php`.
- API gates: `requireApiPermission(...)` — same shape, emits `403 JSON`.
- **Hierarchy guard**: any action that touches another user (edit role,
  reset password, manage their timecards / rates, mark paid) must check
  `roleRank($target['role']) <= userRoleRank($me)`.  Admin must never be
  able to manipulate root.  The Users and Time-cards pages already model
  this — copy the pattern.

## Time and pay weeks

- All time-clock helpers live in `app/timeclock.php`.
  Pay-week boundaries: `weekStartUtc()`, `weekStartLocalDate()`.
  Week containing a punch: `weekStartDateFor()`.
  Snap an admin-entered date to a pay-week-start: `snapToPayWeekStart()`.
  Effective rate for a user/week: `effectiveRateFor()`.
- Pay-week-start day is configured by `PAY_WEEK_START` in `env.ini`
  (`sun` / `mon` / `sat`).  Compute boundaries in the display TZ, then
  convert to UTC for queries — Sunday-in-LA must mean the same thing
  even when the server clock is UTC.
- **Approved weeks** (`timecard_approvals` row exists) are read-only: no
  new punches, no edits, no clock in/out.
- **Paid weeks** (`paid_at IS NOT NULL`) are terminal: also can't be
  unapproved.  The UI hides the Re-open button; the POST handler is the
  only real barrier — keep that guard.
- **Hourly rates** (`hourly_rates`) are keyed to pay-week-start dates on
  both `effective_from` and `effective_to`.  Either side nullable for
  open-ended.  Overlaps rejected at the application layer
  (`hourlyRateRangeOverlaps()`).

## Frontend conventions

- Every page renders through `app/partials/header.php` (set `$pageTitle`,
  `$activePage`, optionally `$hideNav = true`) and
  `app/partials/footer.php`.  Don't roll your own `<html>` skeleton.
- **POST → Redirect → GET** after every state-changing form.  Set
  `$_SESSION['flash_notice']`, then `header('Location: ...'); exit;`.
  Never leave the form sticky in the URL where a refresh would resubmit.
- **CSRF**: every state-changing form includes
  `<input type="hidden" name="_csrf" value="<?= h($_SESSION['csrf_token']) ?>">`.
  The POST handler `hash_equals`-checks it before doing anything.
- **Escaping**: `h($value)` (defined in the header partial) for any value
  that lands in HTML.  `escHtml(str)` is the JS-side equivalent (also
  in the header partial).  Don't reach for `htmlspecialchars()` directly
  — keep one idiom.
- **API fetches**: use `apiUrl('endpoint.php')` from the shared JS, not
  relative paths.  Relative URLs inherit any userinfo from `document.URL`
  and `fetch()` will reject those synchronously.
- **Modal idiom**: `<div class="modal-overlay" hidden>` with `[hidden] {
  display: none }` overriding the flex parent.  Open via JS from a
  trigger button's `data-*` attributes.  On server-side validation
  failure, populate a sticky-form var and a `$reopenMode` string, then
  re-open the modal at page bottom with `openEdit({...})` / `openCreate({...})`.
- **Edit-vs-create field visibility**: `.create-only` and `.edit-only`
  classes, toggled by `showCreateOnlyFields(bool)`.  Both hide
  (`hidden=true`) AND disable their inputs so the hidden form fields
  don't submit.
- **Confirmations**: `data-confirm="..."` on a submit button, with a
  delegated submit listener that `window.confirm()`s and
  `e.preventDefault()`s on cancel.
- **No JS frameworks.**  Stay in vanilla.  If you find yourself reaching
  for one, stop and ask first.

## Version bumps & releases

Semver, with this project's conventions:

- **Minor** (`x.Y.0`) — anything user-visible: new feature, page, workflow,
  or noticeable UI change.  Default to minor when in doubt — the
  changelog is the user's window into what changed.
- **Patch** (`x.y.Z`) — bug fix, small UI refinement, internal refactor
  with no behavior change.  Use sparingly.
- **Major** (`X.0.0`) — reserved for deliberate rework; not used yet.

Every version bump pairs with:

1. `app_version` updated in `app/config.php`.
2. A **new top entry** in `app/changelog.php` with `version`, `date`
   (today, YYYY-MM-DD), `title`, and a `notes` list of user-readable
   bullets.  Write the notes for the operator who sees them in the
   header release modal, not for me.
3. Any deploy step (`php scripts/migrate.php`, `composer install`,
   new `env.ini` key, etc.) goes as the last bullet of the notes.

**Two-commit pattern**: feature lands in one commit, version bump +
changelog in the next.  Never combine them.  Commit messages:

- Feature: ``topic: short subject`` (lowercase, imperative).  Body wraps
  ~72 chars, bullets describe what / why.  End with a
  `Co-Authored-By:` trailer naming the model that co-authored it.
- Version-bump: ``bump version to X.Y.Z with <topic> changelog entry``,
  touching only `app/config.php` and `app/changelog.php`.  Same trailer.

**No model version here, deliberately.**  Every session is told its own
identity by the harness and is the only thing that knows it; a version
written into this file is accurate the day it lands and wrong from the
next model on, with nothing to announce the change.  Don't re-pin it.

## Workflow rules

- **Pause for browser verification before committing.**  After any
  user-visible change, stop and let the user check it in the browser
  before `git commit`.  This is non-negotiable.
- **Don't mutate persistent state for testing.**  No overwriting password
  hashes, flipping rows, or truncating tables to make a curl test work.
  If a test forces it, restore the prior state.
- **The local `orders.sqlite` is a manual copy of production** and drifts
  from the day it was taken.  So before verifying anything windowed — a
  trailing period, a date range — confirm the window actually contains
  rows.  An empty window and a correct answer look identical in the
  output, and a check that cannot fail reads exactly like one that passed.
- **Default to editing existing files.**  Don't create README / notes /
  scratch files unless asked.  CLAUDE.md is the only meta-doc that
  belongs in the repo root.
- **No emojis.**  Not in code, commits, or UI.
- **Comments**: state the non-obvious *why*, not the *what*.  Multi-line
  comment blocks and rotting "added for issue #X" notes don't belong.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **No premature abstractions.**  Three similar lines beats a helper
  invented to dedupe them.  No fallbacks for cases that can't happen.
- **Syntax check** PHP edits with `php -l <file>` before reporting done.
  No formal test suite — manual browser testing is the bar.

## Deployment

This app runs in two different containers, and only one of them is built
from this repo.

- **Local development** — `.deployment/Dockerfile`, built through
  `.local-development/docker-compose.yml` by `./do build` / `up` / `down`.
  The container is `${NICKNAME}-web-1`; `NICKNAME` still defaults to
  `shopify-orders`.  Everything under `.deployment/` describes this image
  and nothing else — its Dockerfile says so in its own first line.
- **Production** — `php84-web-1` on the carmarthen VPS: a shared php84 stack
  serving other applications too.  **It is not built from `.deployment/`.**
  Its image comes from the stack's own
  `/home/redrover/environments/php84/.deployment/Dockerfile`, and `do-prod`
  only `docker exec`s into the running container by name.  Nothing in this
  repo builds or configures it; the stack is a host object and Keith's.

Its three binds, from that stack's
`.local-development/web/docker-compose.yml` and confirmed with
`docker inspect -f '{{json .Mounts}}' php84-web-1`:

```
/home/redrover/environments/php84/src      → /var/www
/home/redrover/environments/php84/servers  → /etc/nginx/servers
/home/redrover/.ssh                        → /citadel/.ssh
```

So the code is bind-mounted rather than baked in — a change to it needs no
rebuild — and this checkout sits one level down, at
`/home/redrover/environments/php84/src/utility` on the host and
`/var/www/utility` inside.  That is the workdir `do-prod` hard-codes, the
root `artifacts/nginx.conf` serves, and the path `artifacts/cron.tab` and
`artifacts/logrotate.conf` spell out in full.

Production config that *is* in this repo, installed by
`sudo scripts/publish-artifacts.sh` on carmarthen:

- `artifacts/nginx.conf` → the php84 stack's
  `servers/utility.decantalize.com.conf`, picked up by that image's
  `include /etc/nginx/servers/*.conf`.  This is the vhost that actually
  serves the site.  `.deployment/config/nginx.conf` is a different thing —
  the dev image's whole `http {}` block, rooted at `/var/www/public` where
  production's docroot is `/var/www/utility/public`.
- `artifacts/cron.tab` → `/etc/cron.d/utility-app`, running the sync scripts
  as `redrover` through `docker exec`.
- `artifacts/logrotate.conf` → `/etc/logrotate.d/utility-app`.

**Production's php-fpm pool is the stock one, and nothing in this repo can
change it.**  The php84 image installs a php.ini and an nginx.conf and no
pool config at all, so `pm.max_children` is whatever `php:8.4-fpm-alpine`
ships — read as 5 from that container's `/usr/local/etc/php-fpm.d/www.conf`
on 2026-09-10.  This repo's `.deployment/config/fpm.conf` sets 15 and
installs as `php-fpm.d/zzzz.conf`, a file `php84-web-1` does not have, which
is also the shortest proof that the two images are not the same one.
Raising the pool is a change to the php84 stack, not to this repo.

Reconciling the two images, or giving production a versioned pool config,
is a host-write on a stack other applications share — Keith's call.

## Layout quick map

```
app/
  changelog.php       release notes (paired with each version bump)
  config.php          env.ini → config array
  db.php              getDb()
  permissions.php     ROLES, PERMISSIONS_BY_ROLE, gates
  password-reset.php  reset-email generation + token consumption
  timeclock.php       pay-week helpers, rate lookup, snap helpers
  shopify.php         Shopify admin API client
  webhook.php         order/product webhook handlers
  mailer.php          PHPMailer wrapper
  partials/
    header.php        <head>, navbar, search modal, release modal
    footer.php        closes <body>
    print-modals.php  label print modal markup
public/                every URL maps to a file here
  auth.php            requireLogin / findUserByCredentials
  api/                JSON endpoints
  webhooks/           Shopify webhook receivers
scripts/
  migrate.php         schema (idempotent — re-runnable in prod)
  add-user.php        interactive user-creation CLI
  sync-products.php   refresh local product cache from Shopify
  sync-paid-orders.php  backfill paid orders missed by webhook
  publish-artifacts.sh  install cron / logrotate / vhost on carmarthen
.deployment/          local-development image only — not production
.local-development/   docker-compose.yml + .env.defaults for ./do
artifacts/            production config; see Deployment above
do                    local-dev compose wrapper (build/up/down)
do-prod               docker exec into php84-web-1, which this repo does
                      not build; see Deployment above
env.ini               local config (gitignored; see env.ini.example)
```
