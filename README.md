<div align="center">

# 🤖 Chatzio

### Intelligent AI Chatbot for WordPress & WooCommerce — grounded in *your* content.

*Automatic content sync · Retrieval-augmented answers · Lead capture · WooCommerce-aware · Beautiful Shadow-DOM widget*

<br />

![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/Version-5.5.7-4F46E5)
![Tested up to](https://img.shields.io/badge/Tested%20up%20to-WP%206.6-success)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Ready-96588A?logo=woocommerce&logoColor=white)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)
![AI](https://img.shields.io/badge/AI-OpenRouter-000000)

</div>

---

## 📖 Table of Contents

- [Overview](#-overview)
- [Key Features](#-key-features)
- [Directory & Architecture](#-directory--architecture)
- [Installation](#-installation)
- [Configuration & Usage](#-configuration--usage)
- [Developer Documentation & Hooks](#-developer-documentation--hooks)
- [Requirements](#-requirements)
- [Uninstallation & Clean-up](#-uninstallation--clean-up)
- [Contributing & License](#-contributing--license)

---

## 🚀 Overview

**Chatzio** is a self-hosted, AI-powered chatbot for WordPress that actually *knows your website*. Instead of shipping a generic assistant that hallucinates, Chatzio continuously indexes your **posts, pages, and WooCommerce products** — plus any documents you upload — and uses that knowledge base to ground every answer through a **retrieval-augmented generation (RAG)** pipeline.

> **The problem it solves:** Off-the-shelf chatbots either require expensive SaaS subscriptions, know nothing about your specific business, or bombard visitors with irrelevant answers. Chatzio keeps your data on *your* server, grounds responses in *your* content, and turns conversations into **captured leads** and **sales** — all from a single WordPress admin panel.

Chatzio connects to large language models through **[OpenRouter](https://openrouter.ai)**, giving you a single API key and access to dozens of models (GPT, Claude, Llama, and more) that you can switch between at any time — no vendor lock-in.

### Why Chatzio?

| 🎯 | Benefit |
|----|---------|
| 🧠 | **Answers from your content** — RAG over synced posts, pages, products & uploaded files |
| 🔌 | **One key, many models** — powered by OpenRouter (GPT / Claude / Llama / etc.) |
| 🛒 | **WooCommerce-native** — product cards, restock alerts, purchase-intent awareness |
| 📇 | **Turns chats into leads** — built-in lead capture form and CRM-style dashboard |
| 🎨 | **Pixel-perfect widget** — Shadow-DOM isolated so your theme can't break it |
| 🔒 | **Privacy-first** — API keys encrypted at rest, data retention controls, GDPR exporters |

---

## ✨ Key Features

### 🛠️ Admin Control
- **Full-featured dashboard** with a dedicated top-level menu and 10 sub-pages (Overview, Settings, Plans, Content, Resources, Conversations, Analytics, Leads, Logs, License).
- **No-code appearance customization** — colors (via the Coloris picker), logo, fonts (system + 5 Google Fonts), bubble size, and widget position (bottom-left/right or fully custom offsets).
- **Tabbed widget builder** — enable/label/icon Home, Chat, FAQ, Products, History, and News tabs, with a searchable icon library.
- **Conversation starters, quick replies, and proactive messages** to drive engagement.
- **Custom CSS** field for advanced admin styling.

### 🧠 AI Chat Capabilities
- **OpenRouter integration** — configurable model, temperature, and max tokens.
- **Retrieval-Augmented Generation (RAG)** — a hybrid search layer (FULLTEXT + optional vector embeddings) over a chunked knowledge base pulls the most relevant context into every prompt.
- **Multi-turn memory** — sends up to 50 prior messages for genuinely conversational, human-like replies.
- **Auto-generated system prompt** — Chatzio can read a digest of your synced content and write a tailored persona/instruction prompt for you.
- **Restricted topics & fallback responses** — keep the bot on-brand and gracefully hand off when it can't help.
- **Failed-topic tracking** — logs questions the AI couldn't answer confidently so you can fill knowledge gaps.

### 🛒 WooCommerce & Commerce
- **Rich product cards** rendered inline in chat.
- **Restock notifications** — an out-of-stock "Notify Me When Available" flow, including a `[chatzio_restock]` shortcode with variable-product support.
- **Purchase-intent awareness** and product highlighting/featuring controls.

### 📇 Leads, Analytics & Insights
- **Lead capture** — configurable pre-chat form (name / email / phone) with skip control, stored in a dedicated leads table.
- **Analytics events** — track opens, messages, and custom events.
- **Conversation history** with 👍/👎 feedback capture for quality tuning.
- **Email digests** — daily failed-topics digest and a weekly summary via WP-Cron.

### 🎨 Frontend & Templates
- **Shadow DOM isolation** — the widget's CSS and markup live in a shadow root, so your theme styles never leak in (and vice versa). No position flash on iOS Safari.
- **Two embed modes** — auto-rendered floating widget in the footer, or inline via the `[chatzio]` shortcode.
- **Template-driven markup** — the widget renders from `templates/chatbot-widget.php`.

### ⚡ Assets & Performance
- **Zero jQuery on the frontend** — vanilla JS, loaded in the footer with `<link rel="preload">` hints for JS & CSS.
- **Smart cache-busting** — assets are versioned by plugin version in production and by file `mtime` when `WP_DEBUG` is on.
- **Conditional loading** — no assets are enqueued when the widget is disabled.
- **Request caching & rate limiting** built in to keep API usage lean.

### 🔒 Security & Privacy
- **API keys encrypted at rest** — the key is stored encrypted and transparently decrypted only when read.
- **Data retention controls** — auto-purge conversations, analytics, leads, logs, and failed topics after N days.
- **GDPR-ready** — registers WordPress privacy exporters/erasers.
- **License client (PCL)** — Free tier with a daily chat quota; Pro unlocks higher quotas and advanced tooling.

---

## 🗂️ Directory & Architecture

```text
chatzio/
├── 📁 admin/                          # WordPress admin experience
│   ├── class-chatzio-admin.php        # Registers menus, pages, settings & Pro-gates
│   └── 📁 views/                      # One PHP view per admin sub-page
│       ├── overview-page.php          #   › Dashboard / setup wizard
│       ├── settings-page.php          #   › All widget & AI settings
│       ├── plans-page.php             #   › Free vs Pro comparison
│       ├── content-page.php           #   › Synced content management
│       ├── resources-page.php         #   › Uploaded knowledge files
│       ├── conversations-page.php     #   › Chat transcripts + feedback
│       ├── analytics-page.php         #   › Engagement metrics
│       ├── leads-page.php             #   › Captured leads
│       ├── logs-page.php              #   › Error & event logs
│       └── license-page.php           #   › License activation
│
├── 📁 assets/                         # Compiled, browser-facing static assets
│   ├── 📁 css/  (frontend.css, admin.css)
│   ├── 📁 js/   (frontend.js  → no jQuery, admin.js → jQuery)
│   └── 📁 images/ (chatzio-logo.svg)
│
├── 📁 includes/                       # Core business logic (autoloaded classes)
│   ├── class-chatzio-database.php     # Table creation, migrations, FULLTEXT indexes
│   ├── class-chatzio-openrouter.php   # LLM API client (OpenRouter)
│   ├── class-chatzio-embeddings.php   # Vector embeddings for semantic search
│   ├── class-chatzio-content-sync.php # Indexes posts/pages/products into the KB
│   ├── class-chatzio-resource-manager.php # PDF/doc upload & extraction
│   ├── class-chatzio-ajax.php         # Public AJAX endpoints (send/feedback/track/faq)
│   ├── class-chatzio-stream.php       # REST route for streaming responses
│   ├── class-chatzio-lead-manager.php # Lead capture & storage
│   ├── class-chatzio-woocommerce.php  # WooCommerce integration
│   ├── class-chatzio-product-cards.php# Renders product cards in chat
│   ├── class-chatzio-restock.php      # Restock subscription flow
│   ├── class-chatzio-topic-resolver.php   # Keyword/topic extraction
│   ├── class-chatzio-failed-topics.php    # Tracks unanswerable questions
│   ├── class-chatzio-rate-limiter.php # Free-tier quota / abuse protection
│   ├── class-chatzio-request-cache.php# In-flight response caching
│   ├── class-chatzio-notifications.php# Email digests (cron)
│   ├── class-chatzio-logger.php       # Structured logging to DB
│   ├── class-chatzio-privacy.php      # GDPR exporters/erasers
│   ├── class-chatzio-api-key-crypto.php   # Encrypt/decrypt the API key at rest
│   ├── class-chatzio-license.php      # PCL license client + heartbeat
│   ├── class-chatzio-activator.php    # Runs on activation (tables, defaults, cron)
│   ├── class-chatzio-deactivator.php  # Runs on deactivation (cron cleanup)
│   ├── license-config.php             # Single source of truth for license host
│   └── tab-icon-library.php           # Icon set for widget tabs
│
├── 📁 templates/                      # Overridable frontend markup
│   └── chatbot-widget.php             # The chat widget HTML shell
│
├── 📄 smartchat-ai.php                # 🚪 Main plugin bootstrap (headers, hooks, shortcodes)
├── 📄 uninstall.php                   # Full clean-up on uninstall (drops tables, files, cron)
└── 📄 .gitattributes
```

### Component Responsibilities

| Layer | Directory | Responsibility |
|-------|-----------|----------------|
| **Bootstrap** | `smartchat-ai.php` | Defines constants, wires up all hooks/shortcodes/cron, enqueues assets, and renders the widget. Singleton `Chatzio_AI`. |
| **Admin** | `admin/` | Everything behind `wp-admin` — menu registration, settings UI, and per-page views. |
| **Core** | `includes/` | The engine room: AI calls, RAG/search, sync, commerce, leads, security, licensing. |
| **Presentation** | `templates/` + `assets/` | The visible widget markup and its self-contained CSS/JS. |
| **Lifecycle** | `activator` / `deactivator` / `uninstall.php` | Install, teardown, and complete data removal. |

---

## 📦 Installation

### Method 1 — WordPress Admin Dashboard (recommended)

1. Download the latest `chatzio.zip` release.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate**.
5. Head to the new **Chatzio** menu in your admin sidebar to begin setup.

### Method 2 — Manual / FTP Upload

```bash
# 1. Unzip the plugin
unzip chatzio.zip

# 2. Upload the folder to your WordPress plugins directory
#    (via FTP/SFTP or your host's file manager)
wp-content/plugins/chatzio/

# 3. Activate via WP-CLI (optional)
wp plugin activate chatzio
```

Then activate it from **Plugins** in the dashboard if you didn't use WP-CLI.

> ✅ On activation, Chatzio automatically creates its database tables, seeds sensible defaults, and schedules its WP-Cron jobs (content sync, digests, data retention).

---

## ⚙️ Configuration & Usage

### 1. Connect your AI provider
1. Create an account at **[OpenRouter](https://openrouter.ai)** and generate an API key.
2. Go to **Chatzio → Settings** and paste your **OpenRouter API Key** (it is stored encrypted).
3. Pick a **model** (e.g. `openai/gpt-3.5-turbo`, or any model OpenRouter offers) and tune **temperature** / **max tokens**.

### 2. Feed it your knowledge
- Under **Settings**, enable **Auto-Sync** for **Posts / Pages / Products**. Chatzio indexes them daily (and you can trigger a manual sync).
- Upload PDFs or documents under **Chatzio → Resources** to expand the knowledge base.
- Optionally enable **"Generate prompt on sync"** to let Chatzio auto-write a site-aware system prompt.

### 3. Style the widget
Customize colors, logo, fonts, bubble size, position, tabs, quick replies, and welcome/proactive messages under **Settings** — all live, no code required.

### 4. Embed it

Chatzio auto-renders a floating widget in your site footer. To place it inline instead, use a shortcode:

| Shortcode | Purpose |
|-----------|---------|
| `[chatzio]` | Render the full chat widget inline in any post/page. |
| `[smartchat]` | Backward-compatible alias of `[chatzio]`. |
| `[chatzio_restock]` | WooCommerce "Notify me when available" button (auto-detects the current product; supports variable products). |

```text
[chatzio]

[chatzio_restock]
[chatzio_restock product_id="123"]
```

---

## 🧑‍💻 Developer Documentation & Hooks

Chatzio is built to be extended. All internal logic lives in cleanly separated classes under `includes/`, and the plugin exposes filters, actions, and configuration constants.

### 🔧 Filters

| Filter | Description |
|--------|-------------|
| `chatzio_upgrade_url` | Change the URL used for "Upgrade to Pro" links. |
| `option_chatzio_settings` | Standard WP option filter — the plugin uses it to transparently decrypt the stored API key. |
| `chatzio_pcl_api_base` | Override the license server (PCL) API base URL. |
| `chatzio_pcl_product_slug` | Override the product slug reported to the license server. |
| `chatzio_pcl_site_url` | Override the site URL reported to the license server. |

```php
// Example: point "Upgrade" links at your own reseller page
add_filter('chatzio_upgrade_url', function () {
    return 'https://your-agency.com/chatzio-pro';
});
```

### ⏱️ Action Hooks (WP-Cron & runtime)

| Hook | Fires | Handler |
|------|-------|---------|
| `chatzio_auto_sync` | Daily | Re-index all site content into the knowledge base. |
| `chatzio_failed_topics_digest` | Daily (9 AM) | Email digest of questions the bot couldn't answer. |
| `chatzio_weekly_summary` | Weekly (Mon 9 AM) | Email performance summary. |
| `chatzio_data_retention` | Daily | Purge records older than the configured retention window. |

You can also register your own callbacks on the public AJAX actions
(`chatzio_send_message`, `chatzio_feedback`, `chatzio_capture_lead`, `chatzio_track_event`, `chatzio_get_faq`) and the streaming **REST route** registered under `rest_api_init`.

### 🎨 Customizing the Widget Template

The widget markup lives in [`templates/chatbot-widget.php`](templates/chatbot-widget.php), rendered inside a **Shadow DOM** for full style isolation.

- Frontend styling is best done via **Settings → appearance** controls, the **Custom CSS** field, or by editing `assets/css/frontend.css`.
- For structural changes, edit `templates/chatbot-widget.php` directly.

> ⚠️ **Heads-up:** The template is included from the plugin directory — there is **no built-in child-theme override lookup**. If you edit the template or `frontend.css` in place, keep a patch or fork so your changes survive plugin updates. For durable customization, prefer the filters and the Custom CSS setting.

### ⚙️ Configuration Constants (`wp-config.php`)

For "environment-style" configuration of the license host:

```php
define('CHATZIO_PCL_API_BASE',    'https://your-store.com/wp-json/pcl/v1');
define('CHATZIO_PCL_PRODUCT_SLUG', 'chatzio');
```

### 🗄️ Database Schema

Chatzio provisions nine prefixed tables (via `dbDelta`, with idempotent migrations and FULLTEXT indexing):

```text
{prefix}_chatzio_content                # Indexed posts/pages/products
{prefix}_chatzio_chunks                 # RAG chunks (+ optional embedding vectors)
{prefix}_chatzio_resources              # Uploaded knowledge files
{prefix}_chatzio_conversations          # Chat history + feedback + topics
{prefix}_chatzio_leads                  # Captured leads
{prefix}_chatzio_analytics              # Engagement events
{prefix}_chatzio_failed_topics          # Unanswered questions
{prefix}_chatzio_logs                   # Structured logs
{prefix}_chatzio_restock_subscriptions  # WooCommerce restock alerts
```

---

## 📋 Requirements

| Requirement | Minimum | Notes |
|-------------|---------|-------|
| **WordPress** | 5.6+ | Tested up to 6.6. |
| **PHP** | 7.4+ | 8.0+ recommended. |
| **MySQL / MariaDB** | 5.6+ / 10.0+ | FULLTEXT indexes used for search (LIKE fallback if unavailable). |
| **WooCommerce** | *(optional)* | Only needed for product cards & restock features. |
| **OpenRouter API key** | Required | Get one at [openrouter.ai](https://openrouter.ai). Powers all AI responses. |
| **HTTPS + WP-Cron** | Recommended | Needed for secure API calls and scheduled sync/digests. |

> 💡 **AI provider:** Chatzio talks to LLMs exclusively through **OpenRouter**, so a single OpenRouter key gives you access to models from OpenAI, Anthropic (Claude), Meta, and others. You are billed by OpenRouter for usage.

---

## 🧹 Uninstallation & Clean-up

Chatzio believes in leaving no trace. Deleting the plugin from **Plugins → Delete** triggers [`uninstall.php`](uninstall.php), which performs a **complete** clean-up:

- 🗑️ **Deletes all options** — `chatzio_settings`, `chatzio_version`, `chatzio_last_sync`, `chatzio_last_embedding_sync`, `chatzio_setup_complete`.
- 🗑️ **Drops every plugin database table** (all nine listed above).
- 🗑️ **Clears all scheduled cron events** — sync, digests, weekly summary, and data retention.
- 🗑️ **Purges all `chatzio_` transients** from the options table.
- 🗑️ **Removes uploaded resource files** from `wp-uploads/chatzio-resources/` and deletes the directory.

> ℹ️ **Deactivating** (rather than deleting) only unschedules cron jobs and leaves your data intact — so you can safely toggle the plugin without losing conversations, leads, or synced content.

---

## 🤝 Contributing & License

### Contributing

Contributions are welcome! To propose a change:

1. **Fork** the repository and create a feature branch: `git checkout -b feature/my-improvement`.
2. Follow **WordPress coding standards** (WPCS) and match the existing class/naming conventions (`class-chatzio-*.php`, `Chatzio_*` classes).
3. Keep the frontend **jQuery-free** and respect the Shadow-DOM isolation.
4. Test against the minimum supported PHP/WP versions.
5. **Commit**, push, and open a **Pull Request** with a clear description of the change and its motivation.

> 🐛 Found a bug or have a feature request? Please open an **Issue** with steps to reproduce, your WP/PHP versions, and any relevant log output from **Chatzio → Logs**.

### License

Chatzio is free software, released under the **GNU General Public License v2.0 (or later)** — the same license as WordPress itself.

```text
This program is free software; you can redistribute it and/or modify it
under the terms of the GNU General Public License as published by the
Free Software Foundation; either version 2 of the License, or (at your
option) any later version.
```

See the [GPL v2 license text](https://www.gnu.org/licenses/gpl-2.0.html) for full details.

---

<div align="center">

**Chatzio** — built with ❤️ by **[Instaquirk](https://instaquirk.com)**

*Give your website a brain.* 🧠

</div>
