# Trackly 📊✨

[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-8892bf.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2%2B-red.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Translation Status](https://img.shields.io/badge/Translations-Turkish%20(Ready)-brightgreen.svg)](#languages)

> **Trackly** is a high-performance, GDPR-compliant Google Analytics 4 (GA4) dashboard, responsive click heatmap client, and visual custom event builder for WordPress. It brings advanced analytics, user behavior flow, and AI-powered recommendations directly to your WordPress backend.

🌐 **[Türkçe Versiyonu (Turkish Version)](README.tr.md)**

---

## 📌 Table of Contents
1. [Core Features](#-core-features)
2. [Architecture & Directory Structure](#-architecture--directory-structure)
3. [Enterprise Security & Cryptography](#-enterprise-security--cryptography)
4. [Performance & Asset Optimization](#-performance--asset-optimization)
5. [Database Schema & Session-Based Sampling](#-database-schema--session-based-sampling)
6. [Detailed Setup Guide (Google Cloud Console)](#-detailed-setup-guide-google-cloud-console)
7. [Internationalization & Translation (i18n)](#-internationalization--translation-i18n)
8. [License](#-license)

---

## 🚀 Core Features

*   **Premium GA4 Analytics Dashboard:** View pageviews, unique visitors, bounce rates, and average session duration with beautiful interactive ApexCharts.
*   **Active Traffic & Device Category Metrics:** Segment traffic based on referral channels (Organic, Direct, Referral, Social) and devices (Desktop, Mobile, Tablet).
*   **Frontend Overlay Admin Statistics Bar:** A premium glassmorphism bar rendered directly on the frontend for logged-in administrators, displaying page-level performance metrics.
*   **Responsive Local Click Heatmaps:** Capture and visualize click hotspots normalized to viewport percentages ($X\%$ and $Y\%$). Works on all screen sizes.
*   **Visual GA4 Event Builder:** Point-and-click interface to generate selector-based custom tracking events without touching code.
*   **AI-Powered Insights:** Automatically assesses page statistics (bounce rates, retention, pageviews) to deliver actionable suggestions for boosting conversion rates.

---

## 📂 Architecture & Directory Structure

Trackly is built following modern WordPress plugin design guidelines, implementing a class autoloader, separating concerns, and lazy-loading heavy components.

```text
trackly/
├── trackly.php                 # Core entry point (Autoloader, hooks activation)
├── uninstall.php               # Clean removal template (options, transients, DB tables)
├── admin/                      # Backend Admin components
│   ├── class-trackly-admin.php # Dashboard controls, REST API callback endpoints
│   ├── css/
│   │   └── trackly-admin.css   # Premium Outfit font styling for Admin Panel
│   └── js/
│       ├── trackly-admin.js    # Ajax charts dashboard, ApexCharts integration
│       └── vendor/
│           └── apexcharts.min.js # Localised charts vendor asset
├── includes/                   # Core business logic layer
│   ├── class-trackly.php       # Core loader (instantiates sub-modules)
│   ├── class-trackly-api.php   # Google OAuth 2.0 JWT engine & GA4 API client
│   └── class-trackly-db.php    # DB Schema creation & raw telemetry logging
├── public/                     # Frontend tracking & overlay components
│   ├── class-trackly-public.php # Front-end hooks, overlay markup injector
│   ├── css/
│   │   └── trackly-public.css  # Frontend overlay glassmorphic styling
│   └── js/
│       ├── trackly-public.js   # Front-end overlay UI logic, selector engine, heatmap dots
│       └── trackly-tracker.js  # GDPR-compliant, lightweight 5KB click tracker
└── languages/                  # i18n Translation files (.po, .mo templates)
    ├── trackly-tr_TR.po        # Turkish translation source template
    └── trackly-tr_TR.mo        # Compiled Turkish localization binary
```

### Module Responsibilities

| Class / File | Responsibility | Loading Phase |
| :--- | :--- | :--- |
| `Trackly` | Bootstraps public/admin layers and databases | Loaded during `plugins_loaded` |
| `Trackly_DB` | Handlers for DB schema migrations and click inserts | Run on plugin activation / cleanup cron |
| `Trackly_API` | Handles JWT generation, OAuth caching, and GA4 batch queries | Lazy-loaded on-demand |
| `Trackly_Admin` | Registers admin submenus, settings schemas, and REST endpoints | Loaded on `is_admin()` |
| `Trackly_Public` | Serves tracking scripts and injects admin overlay panel | Loaded on frontend |

---

## 🔒 Enterprise Security & Cryptography

Trackly is designed to protect your Google Analytics secrets using security best practices:

1.  **AES-256-CBC Secret Encryption:** Your Google Service Account private credentials are encrypted prior to being stored in the database.
2.  **Dynamic Salt Generation:** Uses a composite key containing your server's security salts (`SECURE_AUTH_KEY`, `NONCE_KEY`) combined with a dynamic 64-character unique key generated during activation (`trackly_secure_salt`).
3.  **Restricted REST API Rate Limiting:** Telemetry logging endpoint is throttled to a maximum of 10 requests per minute per IP address, preventing database spam and DDoS attempts.
4.  **XSS & Payload Protection:** Custom events generated via the Event Builder undergo strict regex parsing to prevent HTML/script injection in selector fields.

---

## ⚡ Performance & Asset Optimization

*   **Conditional Loading:** The heavy overlay JS and CSS assets are loaded **exclusively** for logged-in administrators.
*   **Zero-Footprint Tracker:** Standard visitors only download a lightweight, vanilla JavaScript tracker (`trackly-tracker.js`) of under 5KB, which has no jQuery or external dependencies.
*   **Shortened Transients:** Remote API requests and OAuth tokens are cached using transients (e.g. `g_token` and `g_b_`) to minimize API quotas and speed up load times.

---

## 💾 Database Schema & Session-Based Sampling

Trackly records click coordinates locally in a custom database table `wp_trackly_clicks`.

### DB Schema

```sql
CREATE TABLE wp_trackly_clicks (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    page_url varchar(255) NOT NULL,
    element_tag varchar(50) NOT NULL,
    element_selector varchar(255) NOT NULL,
    click_x_pct float NOT NULL, -- normalized X percentage of screen width
    click_y_pct float NOT NULL, -- normalized Y percentage of screen height
    created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    KEY page_url (page_url(191))
);
```

### Telemetry Sampling & Cleanup

*   **Sampling Rate Option:** In **Trackly > Settings**, choose from `100%`, `50%`, `25%`, or `10%` tracking. Setting a lower rate helps prevent DB bloating on high-traffic websites.
*   **Auto-Cleanup Cron:** A daily cron job (`trackly_daily_cleanup`) automatically purges click records older than 30 days.

---

## ⚙️ Detailed Setup Guide (Google Cloud Console)

Follow these steps to connect Trackly to Google Analytics 4 (GA4):

### Step 1: Create a Google Service Account
1.  Go to the [Google Cloud Console](https://console.cloud.google.com/).
2.  Create a new project or select an existing one.
3.  Navigate to **APIs & Services > Library**.
4.  Search for **Google Analytics Data API** and click **Enable**.
5.  Go to **IAM & Admin > Service Accounts**.
6.  Click **Create Service Account**, fill in details, and click **Done**.

### Step 2: Generate JSON Key
1.  Select your new Service Account from the list.
2.  Go to the **Keys** tab.
3.  Click **Add Key > Create New Key**.
4.  Select **JSON** format and click **Create**.
5.  A JSON key file will be downloaded. Keep it safe.

### Step 3: Grant Access in GA4
1.  Copy the `client_email` value from the downloaded JSON file (e.g., `my-service-account@my-project.iam.gserviceaccount.com`).
2.  Go to your **Google Analytics 4** dashboard.
3.  Navigate to **Admin > Property Access Management**.
4.  Click the blue "+" icon to add a new user.
5.  Paste the service account email and grant it the **Viewer** role.

### Step 4: Configure Trackly
1.  Copy your **GA4 Property ID** from Property Settings in Google Analytics.
2.  Paste it into the **GA4 Property ID** field in **Trackly > Settings**.
3.  Paste the complete contents of the downloaded **JSON key file** into the **Service Account JSON Key** textarea.
4.  Click **Save Settings**.
5.  Disable **Demo Mode** to start pulling live data from Google Analytics.

---

## 🌐 Internationalization & Translation (i18n)

Trackly is translation-ready. We provide Turkish translation templates as a sample.

### Localizing using Loco Translate
1.  Install the **Loco Translate** plugin from the WordPress repository.
2.  Go to **Loco Translate > Plugins > Trackly**.
3.  Click **New Language**.
4.  Choose your language and click **Start translating**.
5.  Loco Translate automatically generates and compiles `.po` and `.mo` files in the `languages` folder.

### Localizing using Poedit
1.  Open the [trackly-tr_TR.po](languages/trackly-tr_TR.po) template file in **Poedit**.
2.  Translate the strings into your language.
3.  Save the file as `trackly-[locale].po` (e.g., `trackly-fr_FR.po`).
4.  Poedit will compile a binary `trackly-[locale].mo` file. Place both files in the `languages/` folder.

---

## 📄 License

GPLv2 or later. See file headers for specific licenses.
