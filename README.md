# Chatzio

Secure AI chat and WooCommerce order tracking for WordPress.

[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
![Version](https://img.shields.io/badge/version-5.4.2-4F46E5)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-ready-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

## Overview

Chatzio is a self-hosted WordPress chatbot powered through OpenRouter. It can index site content, answer knowledge-base questions, render WooCommerce product cards, collect leads, manage restock notifications, and perform authorization-gated order-status lookups.

The order-tracking feature uses a strict server-side security boundary: the AI may request an approved tool, but WordPress and WooCommerce validate every argument, authorize the customer, fetch order data, and render the final HTML.

## Main features

- OpenRouter model integration with multi-turn chat.
- Retrieval-augmented answers from posts, pages, products, and uploaded resources.
- WooCommerce product cards and restock notifications.
- Lead capture, conversation history, analytics, feedback, and failed-topic reporting.
- Shadow DOM widget with configurable appearance and tabs.
- Encrypted OpenRouter API key storage.
- WordPress privacy exporters/erasers and configurable general data retention.
- Secure guest and logged-in WooCommerce order tracking.
- AST/AST Pro shipment enrichment with multiple-shipment support.
- Dedicated order-verification throttling, temporary lockout, and masked audit logging.

## Secure order tracking

### Security rules

The following rules are mandatory and are enforced server-side:

1. The AI never receives direct database access or a complete `WC_Order` object.
2. Guest customers must provide an order number and the exact billing email.
3. Logged-in customers are authorized by comparing the order customer ID with the current WordPress user ID.
4. Unknown orders and billing-email mismatches use the same generic public failure message.
5. Order numbers are numeric-only after an optional leading `#` is removed.
6. Billing emails are normalized before a constant-time comparison.
7. Only allowlisted order and shipment fields may leave the order service.
8. Tracking URLs must be absolute HTTPS URLs. `javascript:`, `data:`, credential-bearing, and malformed URLs are rejected.
9. Result HTML is rendered by PHP. Text is escaped and external links use `target="_blank" rel="noopener noreferrer nofollow"`.
10. Three failed verification attempts trigger a 15-minute lockout.
11. Verified order context expires after 600 seconds and does not contain the billing email.

### Guest flow

```text
Customer asks for an order status
    -> Chatzio collects the order number and billing email
    -> WordPress validates the inputs
    -> WooCommerce verifies the exact billing email
    -> AST/AST Pro shipment data is normalized
    -> An allowlisted result is created
    -> PHP renders the secure order card
    -> Verified follow-up context remains available for up to 10 minutes
```

### Logged-in flow

Logged-in customers provide an order number. The server reads the current authenticated WordPress user ID and verifies that it matches the order customer ID. A user ID supplied by the browser or AI is never trusted.

### Allowlisted result fields

Order data:

- Order number
- Creation date
- Public status code
- Approved public status message

Shipment data:

- Carrier
- Tracking number
- Validated HTTPS tracking URL
- Shipped date

The result excludes billing and shipping addresses, billing email, phone number, payment data, transaction IDs, user IDs, private notes, customer IP addresses, and unrestricted order metadata.

### AST/AST Pro behavior

`Chatzio_AST_Adapter` prefers AST Pro's public methods and falls back to `_wc_shipment_tracking_items` metadata when appropriate. It supports multiple shipments, removes exact duplicates, and omits unsafe tracking links. A tracking number may still be shown as text when a safe URL is unavailable.

AST is optional. When it is unavailable, an authorized customer can still receive the order status and a safe "tracking not available" message.

## Order-tool architecture

Production classes are in `includes/order-tools/`:

| Class | Responsibility |
|---|---|
| `Chatzio_Order_Input_Validator` | Strict order-number and email normalization. |
| `Chatzio_Order_Authorization` | Guest email and logged-in ownership verification. |
| `Chatzio_Order_Result` | Exact non-PII order allowlist. |
| `Chatzio_AST_Adapter` | AST/AST Pro shipment normalization and HTTPS URL validation. |
| `Chatzio_Order_Response_Renderer` | Escaped, deterministic order-card HTML. |
| `Chatzio_Order_Tool` | Main validation, authorization, mapping, and shipment service. |
| `Chatzio_Order_Verification_Tool` | Verification-oriented service wrapper used by the chat flow. |
| `Chatzio_Order_Conversation_State` | 600-second verified order context. |
| `Chatzio_AI_Tool_Orchestrator` | Strict `get_order_status` schema and native tool-call parsing. |
| `Chatzio_Order_Rate_Limiter` | Lookup quota, three-failure lockout, and retry response. |
| `Chatzio_Order_Audit_Logger` | Privacy-conscious audit events with masked emails. |

`includes/class-chatzio-order-tracking.php` provides the deterministic conversational gate and fallback. AJAX nonce validation happens before order handling or AI orchestration.

## Installation

1. Upload the `chatzio` directory to `wp-content/plugins/`.
2. Activate **Chatzio** in WordPress Admin.
3. Open **Chatzio -> Settings**.
4. Add an OpenRouter API key and choose a model.
5. Configure content sync, widget appearance, lead capture, and retention as required.
6. Ensure WooCommerce is active before enabling order-support workflows.
7. Optionally activate AST/AST Pro and configure shipment carriers.

Chatzio creates its normal plugin tables and schedules its cron events during activation.

## Configuration and assumptions

- WooCommerce is required for order tracking.
- AST/AST Pro is optional and is required only for enriched shipment information.
- Native AI tool calling requires a compatible OpenRouter model; the deterministic order gate remains the security boundary and fallback.
- The guest verification window is exactly 600 seconds.
- The failure lockout is exactly 900 seconds after three failed verifications.
- WordPress transients are used for temporary order state and lockouts.
- The order-tracking services do not add a custom database table or schema migration.
- Never put a real billing email, customer address, or live tracking number in documentation, fixtures, screenshots, or Git history.

## Local testing

### Prerequisites

1. Start Apache and MySQL.
2. Log in as a WordPress administrator.
3. Activate Chatzio and WooCommerce on the target Multisite blog.
4. Ensure local test order `59681` exists, or update the ignored runners to use another disposable local order.
5. Obtain its billing email from WooCommerce Admin without copying it into documentation.

### Automated and visual runners

Temporary runners live in `dev-tools/` and are excluded by `.gitignore`. They must never be committed or included in a production package.

For the local installation used during development, open:

| Sprint | URL | Expected result |
|---|---|---|
| 1 | `http://localhost/wp/wp-content/plugins/chatzio/dev-tools/test-sprint1-runner.php` | `7 passed, 0 failed` |
| 2 | `http://localhost/wp/wp-content/plugins/chatzio/dev-tools/test-sprint2-runner.php` | Visual order/shipment card; no numeric PASS counter |
| 3 | `http://localhost/wp/wp-content/plugins/chatzio/dev-tools/test-sprint3-runner.php` | `6 passed, 0 failed` |
| 4 | `http://localhost/wp/wp-content/plugins/chatzio/dev-tools/test-sprint4-runner.php` | `9 passed, 0 failed` |
| 5 | `http://localhost/wp/wp-content/plugins/chatzio/dev-tools/test-sprint5-runner.php` | `8 passed, 0 failed` |

Do not use `/wp/puratek/dev-tools/...`; that URL does not point to the plugin files and normally returns 404.

### Manual guest test

1. Open the storefront in an Incognito window.
2. Send `Track order #<TEST_ORDER_NUMBER>`.
3. Confirm that no order details appear before verification.
4. Enter the test order's correct billing email when requested.
5. Confirm that the card contains only the allowlisted order and shipment fields.
6. Ask a follow-up within 10 minutes and confirm that the email is not requested again.
7. Ask for a different order and confirm that new verification is required.
8. Compare a wrong-email attempt with a nonexistent-order attempt; both must show the same generic failure.
9. Run the three-failure lockout test last because it blocks further lookups for 15 minutes.

### Manual AST test

Use a disposable local order. Do not modify a real customer order.

1. Add tracking information through AST/AST Pro.
2. Select a carrier and add a dummy local-test tracking number.
3. Save the order. Changing the order to `Completed` is optional unless status rendering is also being tested.
4. Complete the guest verification flow in Chatzio.
5. Confirm that every shipment is rendered separately.
6. Confirm that **Track shipment** opens an HTTPS carrier URL in a new tab.
7. Inspect the link and confirm `target="_blank"` and `rel="noopener noreferrer nofollow"`.
8. Remove dummy tracking data and restore the original status after the test.

### Current verified baseline

The current `5.4.2` working baseline was locally verified as follows:

- Sprint 1: 7 passed, 0 failed.
- Sprint 2: visual runner rendered successfully.
- Sprint 3: 6 passed, 0 failed.
- Sprint 4: 9 passed, 0 failed.
- Sprint 5: 8 passed, 0 failed.
- Manual guest authorization, multiple AST shipments, tracking buttons, and short-lived follow-up context were verified.

## Known hardening backlog

The ignored `dev-tools/test-sprint6-runner.php` describes a later privacy and production-hardening phase. It is **not part of the completed `5.4.2` baseline** and currently expects services that are not present in this build, including `Chatzio_Order_Privacy`, `Chatzio_Order_Request_Lock`, and `Chatzio_Order_Diagnostics`.

Do not report Sprint 6 as passing until its production services are implemented and reviewed. Planned items include:

- Credential redaction at transcript, browser-storage, and AI-provider boundaries.
- Remediation of previously stored order-tracking transcripts.
- Server-side request idempotency for duplicate frontend submissions.
- Admin feature flag and WooCommerce/AST/model diagnostics.
- Configurable order-audit retention.

## Production and Git safety

- Keep temporary scripts only in `dev-tools/` or `tests/`.
- Never commit `test-*.php`, diagnostic scripts, debug logs, real customer emails, or live tracking values.
- Check `git status`, staged files, and `.gitignore` before every commit.
- Test order changes on disposable local/staging orders only.
- Run desktop, mobile, keyboard, normal-chat, product-card, lead-capture, WooCommerce-disabled, and AST-disabled regression tests before release.
- Deploy behind an operational rollback plan and monitor order-tool failures after release.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[chatzio]` | Render the Chatzio widget inline. |
| `[smartchat]` | Backward-compatible alias of `[chatzio]`. |
| `[chatzio_restock]` | Render the WooCommerce restock subscription control. |

## Requirements

| Requirement | Minimum/Notes |
|---|---|
| WordPress | 5.6 or later |
| PHP | 7.4 or later; PHP 8.x recommended |
| MySQL/MariaDB | MySQL 5.6+ or MariaDB 10.0+ |
| OpenRouter API key | Required for AI responses |
| WooCommerce | Required for order tracking and commerce features |
| AST/AST Pro | Optional shipment enrichment |
| HTTPS and WP-Cron | Recommended |

## Uninstallation

Deleting the plugin through WordPress runs `uninstall.php`, removes Chatzio tables/options/transients, clears scheduled events, and deletes uploaded Chatzio resource files. Deactivation preserves site data while unscheduling runtime cron jobs.

Back up the database before uninstalling or testing destructive lifecycle behavior.

## License

Chatzio is licensed under the GNU General Public License v2.0 or later.
