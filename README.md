# Trackly Analytics Workspace 📊✨

Welcome to the **Trackly** workspace. This repository contains the source code for the **Trackly** WordPress plugin (formerly Gravity Analytics), a high-performance Google Analytics 4 (GA4) dashboard, page-level overlay statistics, click heatmap client, and visual custom event builder.

---

## 📂 Repository Structure

-   [**`trackly/`**](file:///Users/ridvan/Gravity/trackly/): The main WordPress plugin directory containing all business logic, admin panels, frontend tracking scripts, and translations.
    -   [`trackly.php`](file:///Users/ridvan/Gravity/trackly/trackly.php): Core plugin loader and autoloader registration.
    -   [`uninstall.php`](file:///Users/ridvan/Gravity/trackly/uninstall.php): Database option/table cleanup hook.
    -   [`includes/`](file:///Users/ridvan/Gravity/trackly/includes/): Database schema controller and GA4 Data API client engine.
    -   [`admin/`](file:///Users/ridvan/Gravity/trackly/admin/): Admin panel control view handlers and dashboard charts scripts.
    -   [`public/`](file:///Users/ridvan/Gravity/trackly/public/): Lightweight client tracking scripts and glassmorphic frontend overlay modules.
    -   [`languages/`](file:///Users/ridvan/Gravity/trackly/languages/): Translation translation catalog po/mo files.

---

## 📘 Plugin Documentation

Comprehensive installation steps, setup guides, design architecture diagrams, security encryption models, and localization instructions can be found in the official documentation files:

*   🌐 **[Plugin README (English)](file:///Users/ridvan/Gravity/trackly/README.md)**
*   🇹🇷 **[Plugin README (Türkçe/Turkish)](file:///Users/ridvan/Gravity/trackly/README.tr.md)**

---

## 🛠️ Contribution & Development

When working on this workspace, please adhere to:
1.  **WordPress Coding Standards (WPCS):** Ensure all PHP files are fully compliant.
2.  **I18n Translation:** Wrap all user-facing strings in appropriate translation functions (`__()`, `_e()`, etc.) using the `trackly` text domain.
3.  **Strict Security:** Never commit unencrypted credentials or query database directly without escaping variables using `$wpdb->prepare()`.
