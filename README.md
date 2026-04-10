# Xophz Your Moving Castle

> **Category:** Castle Walls · **Version:** 0.0.1

Open your door to many ventures, markets, and brands without moving your site.

## Description

**Moving Castle** is a planned environment and multisite management plugin for the COMPASS ecosystem. Inspired by the magical, dial-turning door in *Howl's Moving Castle*, it is designed to manage WordPress Multisite networks, staging environments, and on-the-fly theme swapping without affecting live users.

### Planned Capabilities

- **Theme Routing (The "Reflection" Mode)** – Session-based theme previewing without touching the live public site, generating secure "Castle Links" for clients.
- **Per-Page Theme Enchants** – Meta boxes allowing individual pages to utilize completely different themes.
- **Multisite Dial** – A central, graphical UI to switch between and manage sites in a WordPress Multisite network.
- **The Escape Hatch** – 1-click jumps between Production and Staging views.
- **Content Synchronization** – Sync posts, pages, and media between network sites.

## Requirements

- **Xophz COMPASS** parent plugin (active)
- WordPress 5.8+, PHP 7.4+

## Status

🔴 **In Development** - Currently scaffolded with base WordPress plugin Architecture. Core mechanics (theme interception, multisite dial) are defined but not yet implemented.

## Installation

1. Ensure **Xophz COMPASS** is installed and active.
2. Upload `xophz-compass-moving-castle` to `/wp-content/plugins/`.
3. Activate through the Plugins menu.
4. Access via the COMPASS dashboard → **Moving Castle**.

## Frontend Routes

| Route | View | Description |
|---|---|---|
| `/moving-castle` | Dashboard | Portal Dial and Wardrobe (theme previewing) interface |

## Changelog

### 0.0.1

- Initial scaffolding with plugin bootstrap and COMPASS integration
