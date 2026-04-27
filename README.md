# Xophz Your Moving Castle

> **Category:** Castle Walls · **Version:** 26.4.27.875

Open your door to many ventures, markets, and brands without moving your site.

## Description

**Moving Castle** is a powerful environment and site migration plugin for the COMPASS ecosystem. Inspired by the magical, dial-turning door in *Howl's Moving Castle*, it is designed to seamlessly migrate WordPress sites, databases, media, themes, and plugins from one environment to another using a robust SQL dump-and-import architecture.

### Capabilities

- **Site-to-Site Migration** – Secure, direct server-to-server transfers via tokenized REST APIs.
- **Bulk SQL Dump Architecture** – Bypasses traditional row-by-row timeouts by generating and compressing full SQL dumps server-side.
- **On-the-Fly Domain Replacement** – Transparently handles `unserialize_replace` logic during the migration process for flawless URL handovers.
- **Asset Synchronization** – Selectively migrate media libraries, active themes, and plugins, complete with automated activation on the target site.
- **Visual Terminal** – A high-tech, gamified Overseer Migration Terminal UI providing real-time logging, metrics, and progress visualization.

## Requirements

- **Xophz COMPASS** parent plugin (active)
- WordPress 5.8+, PHP 7.4+

## Status

🟢 **Stable** - Fully implements the server-side dump/import pipeline and the multi-view Moving Castle sub-app UI.

## Installation

1. Ensure **Xophz COMPASS** is installed and active.
2. Upload `xophz-compass-moving-castle` to `/wp-content/plugins/`.
3. Activate through the Plugins menu.
4. Access via the My Compass dashboard → **Moving Castle**.

## Frontend Routes

| Route | View | Description |
|---|---|---|
| `/moving-castle` | Subsites | View discovered multisite instances and generate connection links. |
| `/moving-castle/import` | Import | The migration wizard and Overseer Terminal for executing imports. |
| `/moving-castle/history` | History | Audit trail of previous migration tasks. |
| `/moving-castle/settings` | Settings | Token TTL and data scope configurations. |
