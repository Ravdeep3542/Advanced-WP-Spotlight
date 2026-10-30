# Advanced WP Spotlight — Universal Command Palette

**Author:** [Ravdeep Singh](https://github.com/Ravdeep3542) · **License:** GPL-2.0-or-later · Copyright © 2026 Ravdeep Singh

![Version](https://img.shields.io/badge/version-1.9.0-blue) ![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777bb3) ![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759b) ![License](https://img.shields.io/badge/license-GPLv2-green)

**You can't turn back if you get used to it**😅

## Description

A keyboard-first, VS Code / Raycast-style command palette for the WordPress admin. Press a shortcut anywhere in wp-admin, type a few characters, and navigate to any screen, run admin actions, or search all your content — without touching the mouse or reloading the page.

Every command respects your WordPress role exactly: users never see or execute anything their capabilities would not natively allow, enforced both in the UI and again on the server.

## Features

- **Fuzzy command search** — typo-tolerant matching ("add usr" finds "Add New User") across navigation, actions, settings, plugins, themes and content.
- **Complete admin navigation** — curated commands with natural aliases plus automatic harvesting of the entire admin menu, so every plugin's screens are searchable too.
- **Pre-filtered list views** — "draft posts", "on hold orders", "out of stock products" jump straight to the native list screen with that filter applied.
- **Global content search** — posts, pages, custom post types, media, users (searchable by name, email and role), taxonomy terms and menus, powered by a fast custom index that builds in the background and stays in sync automatically.
- **Safe actions without page loads** — clear caches (WP Rocket / W3TC / WP Super Cache aware), flush rewrite rules, run WP-Cron, duplicate a post as draft, rebuild the search index, copy site/admin/REST URLs.
- **Settings search** — core settings and every field plugins register via the Settings API, deep-linked to their screens.
- **Plugins & themes as commands** — activate, deactivate (with confirmation), switch themes, and download any installed plugin or theme as a zip.
- **Guarded destructive actions** — trash/delete commands live in a separate "Danger Zone", always require confirmation (irreversible ones require typing "I AGREE"), offer Undo where possible, are rate-limited, and every attempt is written to an audit log you can review under **Tools → Palette Audit Log**.
- **WooCommerce integration** — type an order number to jump to the order (HPOS-compatible), order-status and stock filtered views, product catalog visibility toggle, products show prices in search results.
- **Developer tools** — PHP info, database size, object cache status, clear opcache, toggle `WP_DEBUG` / `WP_DEBUG_LOG` safely (automatic wp-config backup), download the debug log, check updates and memory usage.
- **Context-aware** — editing a post surfaces "Preview This Post", "Duplicate as Draft", "Move to Trash"; list screens surface "Create New …".
- **Personal** — per-user activation shortcut, dark/light theme toggle, pinned favorites, and recent commands.

## Requirements

- WordPress 6.0 or newer (tested up to 7.1)
- PHP 8.0 or newer
- Optional: WooCommerce (adds commerce commands), ZipArchive PHP extension (plugin/theme zip downloads), a working WP-Cron (content index building)

## Installation

1. Upload the `advanced-wp-spotlight` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate **Advanced WP Spotlight** from the Plugins screen.
3. Press `Ctrl + /` (the default shortcut) anywhere in wp-admin, or click the **Palette** button in the admin bar.

The content search index builds in the background after activation; a small notice in the palette tells you while it is still filling.

## Usage

- **Open:** your personal shortcut (default `Ctrl + /`) or the admin-bar Palette button.
- **Navigate:** `↑` `↓` to move, `Enter` to run, `Tab` to cycle a result's inline actions (Edit / View / more), `Esc` to close.
- **Search content:** just type — matching posts, users, terms and menus appear under "Content". Type an order number (e.g. `1042`) to jump to a WooCommerce order.
- **Change your shortcut:** run "Change Palette Keyboard Shortcut" from the palette, or edit it on your profile page. Combinations that usually belong to the browser/OS trigger a warning but are not blocked.
- **Switch theme:** run "Toggle Palette Dark / Light Mode".
- **Pin favorites:** use the pin action on any command; favorites appear at the top.

Destructive commands (trash, delete, deactivate, switch theme, debug toggles) never run directly: a confirmation panel opens first, with Cancel focused by default. Irreversible operations additionally require typing `I AGREE`.

## Configuration

**Settings → Command Palette** (administrators):

- **Indexed post types** — all searchable post types are indexed by default; uncheck a type and save to remove it from search (its existing index entries are cleaned up automatically).
- **Index status** — row count, pending background jobs, and last sync time. You can also run the "Rebuild Search Index" command from the palette.

**Tools → Palette Audit Log** (administrators): the most recent 200 destructive command attempts — date, user, command, targets, status and outcome, including forbidden and rate-limited attempts.

## Frequently Asked Questions

**Does it respect user roles?**
Strictly. Commands and search results are filtered by capability before they reach the browser, and every execution re-verifies `current_user_can()` on the server. A forged request is rejected the same way wp-admin would reject it.

**How are delete commands kept safe?**
They render in a separate red Danger Zone, never auto-select, require an explicit confirmation step (typed confirmation for irreversible ones), are rate-limited to 5 destructive actions per minute per user, and every attempt is audit-logged. Trash operations offer a 10-second Undo.

**Do the debug toggles really edit wp-config.php?**
Yes, carefully: a backup is saved first (`wp-config.awps-backup.php`), only the single `define()` line is changed, the result is verified, and the write is atomic — a failure at any step leaves the original file untouched.

**Why is content search empty right after activation?**
The index builds in the background via WP-Cron. Large sites take a few minutes; the palette shows a notice until the initial build finishes.

## Troubleshooting

- **Shortcut doesn't open the palette** — another extension or the browser may own that combination; pick a different one via "Change Palette Keyboard Shortcut". The admin-bar Palette button always works.
- **Content results missing or stale** — check **Settings → Command Palette** for pending background jobs; verify WP-Cron runs on your host (or trigger it with the "Run WP-Cron Now" command), then "Rebuild Search Index".
- **Plugin/theme download fails** — the server needs the ZipArchive PHP extension; the error toast will say so if it is missing.

## Uninstallation

Deactivating keeps all data. Deleting the plugin removes the search index table, the audit log table, plugin options, and per-user preferences (shortcut, theme, favorites, recents, hint state). Any `wp-config.awps-backup.php` created by the debug toggles is left in place on purpose — delete it manually once you no longer need the rollback copy.

## Privacy / Security

- No data leaves your site: no external services, telemetry or CDNs.
- All REST endpoints require authentication and a nonce; file downloads and developer tools are administrator-only.
- Download endpoints confine resolved paths to `wp-content` / plugins / themes trees (no arbitrary filesystem reads).
- The audit log stores user IDs, command IDs, targets and outcomes of destructive attempts — site-internal accountability data, kept in your own database.
