=== Trackly ===
Contributors: ridvan
Tags: analytics, ga4, heatmaps, dashboard, visitor-tracking
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A modern Google Analytics 4 dashboard and click heatmap tracker client for WordPress with predictive statistical insights.

== Description ==

Trackly is a GDPR-compliant, high-performance plugin that brings Google Analytics 4 reports and click heatmaps directly to your WordPress website. It features a premium administrative analytics dashboard, active traffic and device category metrics, responsive predictive local click heatmaps, machine learning-powered anomaly insights, and a visual GA4 event builder.

This plugin is designed from the ground up for strict performance and security. Standard visitors only download a lightweight, vanilla JavaScript tracker under 5KB (with zero jQuery or external dependencies), while heavy stats overlay interfaces are loaded exclusively for logged-in administrators.

= External Services Integration =

In compliance with WordPress.org Guidelines, please note that Trackly connects to the following external services to deliver its core functionality:

1. **Google Analytics 4 (GA4) API** (https://analyticsdata.googleapis.com)
   - **Purpose**: Retrieves your property's reporting statistics (views, users, bounce rate, sessions, traffic sources, and devices) to display them in interactive charts on the admin dashboard.
   - **Privacy Policy**: Google Privacy Policy can be found at https://policies.google.com/privacy

2. **Cloudflare APIs** (https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6)
   - **Purpose**: Dynamically fetches and validates Cloudflare's public IPv4/IPv6 ranges once a week via cron to ensure secure client IP detection and prevent request spoofing.
   - **Privacy Policy**: Cloudflare Privacy Policy can be found at https://www.cloudflare.com/privacypolicy

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/trackly` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure your GA4 Property ID and Service Account JSON credentials under the Settings menu.

== Frequently Asked Questions ==

= Does this plugin support IPv6? =
Yes! Cloudflare and reverse proxy whitelists support both IPv4 and IPv6 subnet ranges natively.

= Is the click tracking GDPR-compliant? =
Absolutely. The click telemetry data is completely anonymized, stored in your own local database, and automatically deleted after 30 days.

== Screenshots ==

1. The main administrative analytics dashboard displaying GA4 report statistics and interactive charts.
2. Glassmorphic front-end client overlay displaying page-level analytics and click heatmap overlays.

== Privacy Policy ==

Trackly respects user privacy and complies with GDPR. Telemetry data is recorded locally on custom database tables, contains no personally identifiable information (PII), and is automatically purged after 30 days.

== Upgrade Notice ==

= 1.0.0 =
Initial Release.

== Changelog ==

= 1.0.0 =
* Initial Release.
