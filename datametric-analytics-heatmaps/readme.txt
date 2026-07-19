=== DataMetric Analytics Dashboard and Heatmaps ===
Contributors: datametric
Tags: analytics, ga4, heatmaps, dashboard, statistics
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GDPR-conscious Google Analytics 4 dashboard, local click heatmaps, and statistical anomaly insights — with a dependency-free front-end tracker.

== Description ==

DataMetric Analytics Dashboard and Heatmaps brings the analytics that matter into your WordPress admin, without sacrificing performance or privacy. Standard visitors download only a lightweight, dependency-free (vanilla JavaScript) tracker under 5 KB, while the heavier dashboard interface is loaded exclusively for logged-in administrators. All Google Analytics 4 (GA4) requests are batched, cached, and quota-aware.

**Zero-setup Demo Mode:** the moment you activate the plugin it runs in Demo Mode, so you can explore the *entire* experience — every GA4 report **and** the click heatmap — with realistic sample data and no configuration at all. Demo Mode stays on until you connect your own GA4 property.

= Key features =

* **GA4 dashboard** — Users, sessions, pageviews, engagement rate and average session duration KPIs, plus a multi-series 7 or 30-day traffic trend.
* **Traffic acquisition** — Source / medium (including referrers) breakdown with sessions, users, engagement and key events (conversions), so you can see which channel brings quality traffic.
* **Local click heatmap** — See where visitors click, rendered as an overlay on your own pages. Stored in your own database, contains no personal data, and is purged automatically after 30 days.
* **Realtime** — A live active-visitor count and a "last 30 minutes" sparkline.
* **Audience & geography** — Top pages, top landing pages, top countries, top events, device distribution, and a new-vs-returning visitor split.
* **Visual GA4 Event Builder** — Define custom GA4 events by clicking any button or link on the page — no code required.
* **Statistical anomaly insights** — Traffic and bounce-rate values that deviate significantly from the recent average are flagged using classic mean + standard deviation math (not machine learning).
* **Privacy-first** — Strict opt-in tracking that integrates with Complianz, Borlabs, CookieLawInfo, and Google Consent Mode v2.

= A note on "insights" =

The anomaly insights are produced by classic descriptive statistics (mean and standard deviation over the reporting window), not machine learning. They flag traffic and bounce-rate values that deviate significantly from the recent average.

= External Services =

This plugin connects to one external service, and only when you configure it:

1. **Google Analytics 4 (GA4) Data API** (https://analyticsdata.googleapis.com)
   - **What it is and why it is used**: Retrieves your own GA4 property's reporting statistics (views, users, bounce rate, sessions, traffic sources, and devices) to display them in interactive charts on the admin dashboard. Requests are made only from wp-admin, only after you enter your GA4 Property ID and Service Account credentials, and only with your own Google credentials.
   - **Data sent**: your GA4 Property ID and report queries, authenticated with the Service Account credentials you provide. No visitor data from your site is sent.
   - **Terms**: https://marketingplatform.google.com/about/analytics/terms/us/
   - **Privacy Policy**: https://policies.google.com/privacy

The local click heatmap and anomaly insights run entirely on your own server and database; no visitor tracking data ever leaves your site.

= Bundled Third-Party Libraries =

* **ApexCharts.js** (v4.7.0, MIT License) — used to render the admin dashboard charts. Source and documentation: https://github.com/apexcharts/apexcharts.js . The library is bundled locally (Admin/js/vendor/apexcharts.min.js); no chart data leaves your server through it.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/datametric-analytics-heatmaps` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Open **DataMetric** in the admin menu. It starts in **Demo Mode**, so every report and the click heatmap work immediately with sample data — no setup required.
4. When you are ready for live data, add your GA4 Property ID and Service Account JSON credentials under the DataMetric Settings screen and turn Demo Mode off.

== Usage ==

**Dashboard (wp-admin):** Open **DataMetric** in the admin menu to view your KPIs, traffic trend, acquisition, device and country breakdowns, realtime activity, and anomaly insights. In Demo Mode these show sample data; once a GA4 property is connected they show live data.

**Click heatmap (front-end):** The heatmap is drawn on your actual pages, not inside wp-admin. While logged in as an administrator, open any page on the front end of your site, click the floating DataMetric button in the bottom-right corner, switch to the **Click Heatmap** tab and press **Show Heatmap**. Click-density dots are overlaid on the page (a sample heatmap in Demo Mode, or your real recorded clicks once live).

**GA4 Event Builder:** In the same front-end panel, open the **Event Builder** tab, click **Start Element Selection**, choose a button or link, give your GA4 event a name and save it. Clicks on that element are then reported to GA4 through your existing gtag setup.

== Frequently Asked Questions ==

= Do I need a Google Analytics account to try it? =
No. On activation the plugin runs in Demo Mode, which previews every report and the click heatmap with realistic sample data — no GA4 connection required. To display your own live data you need a GA4 property and a Google Cloud Service Account.

= Does the click heatmap show up without any setup? =
Yes. In Demo Mode the heatmap displays a representative sample so you can experience the feature immediately. Once you connect GA4 and turn Demo Mode off, the heatmap shows the real clicks recorded for each page.

= Where does the heatmap appear? =
On the front end of your site, as an overlay on the real page — not inside wp-admin. Open the floating DataMetric panel while logged in as an administrator, go to the Click Heatmap tab and press Show Heatmap.

= Does this plugin support IPv6? =
Yes. The Cloudflare and reverse-proxy ranges used for accurate client IP detection support both IPv4 and IPv6 natively. These ranges are bundled with the plugin; no external request is made to obtain them.

= Is the click tracking GDPR-compliant? =
The click telemetry contains no personal data (no IP address, no user identifiers) — only the page path, the clicked element, and normalized click coordinates. It is stored in your own local database and automatically deleted after 30 days. By default the tracker starts in strict opt-in mode and waits for consent from a supported consent plugin (Complianz, Borlabs, CookieLawInfo, or Google Consent Mode v2); you can relax this in Settings.

= Will it slow down my site? =
The goal is the opposite. Visitors download only an under-5 KB dependency-free script; the heavy dashboard assets load solely for logged-in administrators, and no external front-end requests (such as Google Fonts) are made.

= What happens to my data when I uninstall? =
By default nothing is deleted, so you can safely reinstall. If you enable "Delete all data on uninstall" in Settings, deleting the plugin will permanently drop the click table and remove all options. Multisite installs are handled per-site.

= Do I need Google Analytics installed for the Event Builder? =
Yes. The Event Builder maps clicks to GA4 events via gtag. Your site must already load the GA4 / gtag snippet (for example via Site Kit or a manual tag) for these events to be sent.

== Screenshots ==

1. The main administrative analytics dashboard displaying GA4 report statistics and interactive charts.
2. Glassmorphic front-end client overlay displaying page-level analytics and click heatmap overlays.

== Privacy Policy ==

DataMetric Analytics Dashboard and Heatmaps respects user privacy and complies with GDPR. Telemetry data is recorded locally on custom database tables, contains no personally identifiable information (PII), and is automatically purged after 30 days.

== Upgrade Notice ==

= 1.0.0 =
Initial Release.

== Changelog ==

= 1.0.0 =
* Initial Release.
