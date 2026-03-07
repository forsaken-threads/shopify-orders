# CLAUDE.md

This file provides guidance for AI assistants (Claude and others) working in this repository.

## Project Overview

`shopify-orders` is a project for integrating with the Shopify Orders API. The purpose, tech stack, and architecture will be documented here as the project evolves.

> **Note:** This repository is currently empty/bootstrapped. Update this file as structure and conventions are established.

## Repository Status

- **Current state:** Initial setup — no source files yet
- **Branch convention:** Feature branches use `claude/<description>-<id>` format

## Development Workflow

### Branching

- Main branch: `main` (or `master` — confirm once established)
- Feature branches: `feature/<short-description>`
- Claude-driven branches: `claude/<task-description>-<session-id>`
- Always push to the branch you're working on; never push to `main` directly without a PR

### Commits

- Write clear, imperative commit messages: `Add order sync webhook handler`
- Keep commits focused and atomic
- Reference issue numbers when applicable: `Fix order status mapping (#42)`

### Pull Requests

- Open PRs against `main`
- Include a summary of changes and a test plan
- Do not merge your own PRs without review

## Code Conventions

These will be filled in once the language/framework is chosen. Common defaults to follow until then:

- **Formatting:** Use the formatter standard for the chosen language (e.g., Prettier for JS/TS, Black for Python, `gofmt` for Go)
- **Linting:** Run the project linter before committing; fix all warnings
- **Naming:** Use descriptive names; avoid abbreviations except for well-known acronyms (e.g., `API`, `URL`, `ID`)
- **Error handling:** Handle errors explicitly; never silently swallow exceptions
- **Secrets:** Never commit API keys, tokens, or credentials — use environment variables or a secrets manager

## Shopify Integration Notes

When working with the Shopify API:

- **Authentication:** Use OAuth 2.0 for public apps; use Admin API access tokens for private/custom apps
- **API version:** Pin to a specific Shopify API version and document upgrade steps
- **Rate limits:** Shopify enforces REST API rate limits (2 req/s for standard, leaky-bucket) and GraphQL cost limits — implement retry with exponential backoff
- **Webhooks:** Verify the `X-Shopify-Hmac-SHA256` header on every incoming webhook before processing
- **Order data:** Treat `order.id` as the stable identifier; `order.name` (e.g., `#1001`) is display-only and can be customized
- **Pagination:** Use cursor-based pagination (`page_info`) for listing orders; avoid offset pagination on large datasets

## Environment Variables

Document required environment variables here as they are introduced. Example structure:

```
SHOPIFY_API_KEY=         # Shopify app API key
SHOPIFY_API_SECRET=      # Shopify app API secret
SHOPIFY_ACCESS_TOKEN=    # Admin API access token (private/custom apps)
SHOPIFY_SHOP_DOMAIN=     # e.g., your-store.myshopify.com
SHOPIFY_WEBHOOK_SECRET=  # Secret for verifying webhook signatures
```

Copy `.env.example` to `.env` and fill in values. Never commit `.env`.

## Testing

- Write tests for all business logic, especially order processing and webhook handling
- Run the full test suite before pushing: `<test command TBD>`
- Aim for meaningful coverage on critical paths (order ingestion, status updates, error flows)

## Getting Started (to be updated)

```bash
# Clone the repo
git clone <repo-url>
cd shopify-orders

# Install dependencies (command TBD based on stack)
# ...

# Copy environment config
cp .env.example .env
# Fill in .env values

# Run the project
# ...
```

## Key Files (to be updated)

| Path | Purpose |
|------|---------|
| *(none yet)* | *(add entries as files are created)* |
