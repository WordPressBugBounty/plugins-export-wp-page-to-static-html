=== Export WP Pages to Static HTML – Simply Create a Static Website ===
Contributors:       recorp
Tags:               static html export, static site generator, html export, export posts, export pages
Requires at least:  5.8
Tested up to:       6.7
Requires PHP:       7.4
Stable tag:         6.0.5.2
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Export any WordPress post, page, or custom post type to clean static HTML — one at a time or in bulk. Grouped assets, role-based export, FTP upload & more.

== Description ==

**Export WP Pages to Static HTML** is the most flexible static HTML export plugin for WordPress. Unlike full-site generators, Export WP Pages to Static HTML gives you surgical control — export exactly the posts, pages, or custom post types you need, in the status you want, as the user role you choose.

Whether you're archiving a campaign landing page, delivering client work as a self-contained HTML package, or building a lightning-fast static copy of your content, Export WP Pages to Static HTML makes it effortless.

> 🔒 **[Export WP Pages to Static HTML Pro Available](https://myrecorp.com/export-wp-page-to-static-html-pro/)** — Unlock All Posts, Full Site exports, AWS S3 deployment & more!

---

### 🎯 Why Export WP Pages to Static HTML Is Different

Most static site plugins convert your *entire* WordPress site in one go. Export WP Pages to Static HTML lets you target exactly what you need:

✅ Export a **single post** or **hand-pick multiple posts, pages, or CPT items** in one run
✅ Export **across all post statuses** — Published, Draft, Private, Pending, Scheduled
✅ Export content exactly as it appears to a **specific user role** (subscriber, editor, etc.)
✅ **Group assets cleanly** into `/images`, `/css`, `/js` — developer-ready output
✅ Save parent posts as **clean root-level `.html` files** — no nested folders
✅ **Preview** exported files right inside WordPress before downloading
✅ **Download assets as ZIP** — all images, CSS, JS packaged in one click
✅ **FTP / SFTP upload** directly from the export panel
✅ **Email notification** when your export completes
✅ Built-in **System Status** diagnostics page

---

### ⚡ Core Features (Free)

**All Pages Export**

Export all your WordPress pages in one click — no need to select them one by one. Perfect for exporting your entire page-based site (landing pages, portfolios, business sites) as clean static HTML.

**Granular Export Control**

Pick exactly what to export — no need to regenerate your entire site every time. Select one post, a handful of pages, or choose from any custom post type. Use the built-in search to find content instantly and the "Select All" button for quick bulk selection.

**All Post Statuses Supported**

Export content regardless of its WordPress status. Publish, Draft, Private, Pending, and Scheduled posts are all supported. Perfect for previewing unpublished pages as static HTML before they go live.

**Role-Based Export**

Export pages exactly as they appear to a specific WordPress user role. Export WP Pages to Static HTML temporarily creates a user of the chosen role, renders the pages through their eyes, then cleans up — no permanent users left behind. Essential for membership sites, gated content previews, and client deliveries.

**Grouped Asset Organization**

Turn on "Group assets by type" and Export WP Pages to Static HTML automatically sorts all exported assets into clean subdirectories: `/images`, `/css`, `/js`. The result is a well-structured, developer-friendly HTML package that's easy to hand off or deploy.

**Parent Posts in Root Directory**

Enable "Parent posts in root dir" and Export WP Pages to Static HTML flattens your URL structure — `/postname/index.html` becomes `/postname.html` at the export root. Ideal for clean, flat static site structures.

**Live Export Preview**

After every export, a built-in file browser lets you preview exactly what was generated — HTML files, images, scripts, and stylesheets — right inside your WordPress dashboard.

**Download Assets as ZIP**

Download all exported images or other asset types as a single ZIP archive in one click. No need to manually browse folders or FTP into your server.

**FTP / SFTP Upload**

Push exports directly to a remote server over FTP or SFTP without leaving WordPress. Set your host, port, credentials, and remote path once — then upload with a button click. Supports FTPS (SSL) and passive mode.

**Email Notification on Complete**

Running a large export in the background? Enable "Notify on complete" and Export WP Pages to Static HTML emails you (and optional additional addresses) the moment the export finishes.

**Smart Asset Collection Modes**

Three modes give you precise control over which assets are bundled: Strict (only assets directly referenced by exported pages), Hybrid (referenced assets + media library, recommended), and Full (everything: uploads, theme assets, and plugin assets).

**Intelligent URL Discovery and Crawling**

Export WP Pages to Static HTML's built-in crawlers automatically discover all URLs needed for your selected content, including pagination, taxonomy archives, author pages, date archives, RSS feeds, post-type archives, sitemap URLs, and REST API endpoints — so no linked assets or pages are ever missed.

**Fault-Tolerant Export Engine**

Exports don't break on bad URLs. Export WP Pages to Static HTML automatically retries failed URLs with exponential backoff, tracks every failure with its last error message, and lets you re-run only the failed URLs without restarting the whole export. A background watchdog monitors stuck processes and repairs them automatically.

**Pause, Resume, and Cancel**

Long exports? Pause mid-run, pick up later, or cancel entirely without corrupting your export directory. Full export lifecycle control from the admin panel.

**System Status and Diagnostics**

A dedicated System Status page checks your PHP version, WordPress environment, file permissions, REST API availability, and more — so you can diagnose issues before they stop an export.

**Translation Ready**

Export WP Pages to Static HTML is fully internationalized and ready for translation via the WordPress translation system.

---

### 🚀 Export WP Pages to Static HTML Pro Features

* **All Posts export** — export every post (or selected custom post types) in one run
* **Full Site export** — complete WordPress-to-static-HTML conversion with URL discovery
* **AWS S3 deployment** — push exports directly to an S3 bucket
* Email support and priority bug fixes

[Upgrade to Export WP Pages to Static HTML Pro →](https://myrecorp.com/export-wp-page-to-static-html-pro/)

---

### 🛠️ Perfect For

* **Developers and agencies** delivering static HTML proofs or archives to clients
* **Content teams** exporting specific posts for offline review or archiving
* **Marketing teams** saving landing pages and campaign pages as standalone HTML
* **Site owners** creating lightweight static mirrors of posts or pages
* **Freelancers** handing off finished pages as a self-contained HTML package

### ❌ Not Suitable For

* Sites that require real-time dynamic content (live chat, WooCommerce checkout, membership portals)
* Sites where the goal is replacing WordPress with a fully automated static deployment pipeline — consider Export WP Pages to Static HTML Pro for bulk and full-site generation

---

### 🔌 Compatibility

* Works with **all WordPress themes** including block themes and classic themes
* **Page builders:** Elementor, Divi, Beaver Builder, Bricks, Gutenberg
* **SEO plugins:** Yoast SEO, Rank Math, AIOSEO, SEOPress
* **Custom post types:** auto-detected, available in the export scope selector
* PHP 7.4 – 8.3 | WordPress 5.8 – 6.7

---

### 📖 Documentation and Support

* 📖 [Documentation](https://myrecorp.com/documentation/export-wp-page-to-static-html-documentation)
* 💬 [Support Forum](https://wordpress.org/support/plugin/export-wp-page-to-static-html/)

== Installation ==

= Automatic Installation (Recommended) =

1. In your WordPress dashboard, go to **Plugins → Add New**
2. Search for **"Export WP Pages to Static HTML"**
3. Click **Install Now**, then **Activate**
4. Navigate to **Tools → Export WP Pages to Static HTML**

= Manual Installation =

1. Download the plugin `.zip` file from WordPress.org
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**, then **Activate**
4. Navigate to **Tools → Export WP Pages to Static HTML**

= Your First Export =

1. Go to **Tools → Export WP Pages to Static HTML**
2. Choose your **Export Scope** (Custom, All Pages, or Pro: All Posts / Full Site)
3. Select the posts or pages you want to export
4. (Optional) Choose a **Post Status**, **Login Role**, and **Asset Options**
5. Click **Start Export**
6. When complete, click **Preview** to browse the files or **Download ZIP** to save them

== Frequently Asked Questions ==

= Is Export WP Pages to Static HTML free? =

Yes! The core plugin is completely free and includes Custom and All Pages export scopes. Export WP Pages to Static HTML Pro is an optional add-on that unlocks All Posts, Full Site exports, and AWS S3 deployment.

= How is Export WP Pages to Static HTML different from Simply Static or other full-site generators? =

Export WP Pages to Static HTML is built for precision over full-site generation. Instead of converting your entire WordPress installation, you choose exactly which posts, pages, or custom post type items to export — and in what status and user-role context. This makes it far more useful for client deliveries, content archiving, and partial static exports.

= Can I export draft or private posts as static HTML? =

Yes. Export WP Pages to Static HTML supports all five WordPress post statuses: Publish, Draft, Private, Pending, and Scheduled. This is rare in static export plugins and a key differentiator of Export WP Pages to Static HTML.

= What does "role-based export" mean? =

You can choose a WordPress user role (e.g., Subscriber, Editor) and Export WP Pages to Static HTML will render the exported pages exactly as that role would see them. It temporarily creates a user of that role, renders the content, then deletes the user — nothing is left behind.

= What does "Group assets by type" do? =

When enabled, Export WP Pages to Static HTML sorts all exported assets into subdirectories: images go into `/images`, stylesheets into `/css`, and scripts into `/js`. This produces clean, organized output that is immediately ready for handoff or deployment.

= What does "Parent posts in root dir" do? =

It flattens the URL structure of parent posts. Instead of `/postname/index.html`, the file is saved as `/postname.html` directly in the export root — ideal for hosting on simple static servers.

= Can I re-run only the failed URLs? =

Yes. Export WP Pages to Static HTML tracks every failed URL with its error message and retry count. A dedicated "Re-run failed" button retries only the failed items without restarting the entire export.

= Can I pause and resume an export? =

Yes. Use the Pause and Resume buttons in the export panel at any time. Exports can also be cancelled without corrupting already-exported files.

= Can I upload exports directly to my FTP/SFTP server? =

Yes. Configure your FTP/SFTP credentials in **Settings → FTP/SFTP** and enable "Upload to FTP" before starting an export. Supports passive mode and FTPS (SSL). You can also browse remote directories directly from the settings panel.

= How does email notification work? =

Enable "Notify on complete" in the Delivery and Notifications panel. You can optionally add extra email addresses for teammates or clients. A notification email is sent automatically when the export finishes.

= What is the Preview feature? =

After an export completes, the built-in file browser lets you browse all generated files — HTML pages, images, CSS, JS — directly in your WordPress admin. You can also download groups of assets (like all images) as ZIP archives from the preview panel.

= Does Export WP Pages to Static HTML work with Elementor, Divi, and other page builders? =

Yes. Export WP Pages to Static HTML works with all major page builders and has been tested with Elementor, Divi, Beaver Builder, Bricks Builder, and the native Gutenberg editor.

= Does it work with custom post types? =

Yes. All public, registered custom post types are automatically detected and appear in the Export Scope selector under the "Post types" tab.

= Does it work on WordPress Multisite? =

The free plugin works on individual sites in a multisite network.

= What are the asset collection modes? =

Strict exports only assets directly referenced by the exported pages. Hybrid (the recommended default) adds your media library on top of referenced assets. Full includes everything — theme and plugin asset directories included.

= Will this affect my live WordPress site? =

No. Exports are written to a separate directory (`/wp-content/wp-to-html-exports/`). Your live WordPress site remains fully intact and unchanged.

= Where can I get help? =

Post in the [WordPress.org support forum](https://wordpress.org/support/plugin/export-wp-page-to-static-html/). Export WP Pages to Static HTML Pro customers receive priority email support.

== Screenshots ==
1. **Export Panel** — Select posts, pages, or CPT items, choose scope, and start your export


== Changelog ==

= 6.0.5.2 =
* Fixed: Tables creating error on plugin update.

= 6.0.0 =
* Refactored the core export engine for improved stability and performance.
* Improved: Watchdog now automatically detects and repairs stalled export processes.
* Improved: Enhanced failed URL tracking with per-URL retry counts and detailed error reporting.
* Improved: Re-run only failed URLs without restarting the entire export process.
* Improved: Implemented exponential backoff for asset retries to reduce server load.
* Improved: Asset collection mode (Strict / Hybrid / Full) is now saved and respected across cron runs.
* Fixed: Export context is now correctly propagated to background workers during server cron execution.
* Added: `single_root_index` and `root_parent_html` options are now persisted within the export context.
* Improved: More user-friendly interface and overall UX enhancements.
* Removed: PDF Exporting option removed temporarily.


= 5.0.1 - 2 February 2026 =
* FIXED - A minor issue.

= 5.0.0 - 1 November 2025 =
* FIXED - A critical issue has been fixed.

= 4.3.4 - 20 October 2025 =
* ADDED: Email notification system when export completed.

= 4.3.3 - 8 September 2025 =
* FIXED: Skip assets not working issue.
* FIXED: Some other issues.
* ADDED: Increase 3 pages limitation to 6 pages.

= 4.3.2 - 8 September 2025 =
* UPDATED: little thing.

= 4.3.1 - 8 September 2025 =
* UPDATED: little thing.

= 4.2.9 - 7 September 2025 =
* ADDED: info icons and tooltip on each settings label.

= 4.2.8 - 7 September 2025 =
* Updated little thing.


= 4.2.7 - 6 September 2025 =
* Updated the export page interface.


= 4.2.3 - 31 August 2025=
* Test

= 4.2.2 - 30 August 2025=
* FIXED: Fixed table column issue.

= 4.2.1 - 29 August 2025=
* FIXED: Fixed little issue;

= 4.2.0 - 26 August 2025=
* UPDATED: Whole exporting system. Now export wp pages to static html and css plugin can export almost every site.

= 4.1.0 - 29 July 2025=
* ADDED: Review section.
 
= 4.0.1 - 30 April 2025=
* UPDATED: Some PDF making js codes to generate pdf file smoothly.
 
= 4.0.0 =
* New: PDF export feature with `[generate_pdf_button]` shortcode  
* New: Role-Based Access Control for export buttons  
* New: Daily PDF export limits (2/day) with notifications  
* New: Background/asynchronous export jobs with progress bar  
* FIXED: Table creating issue while plugin activate.

= 3.0.0 - 9 July 2024  =
* Fixed lots of tweaks.

= 2.2.3 - 1 July 2024  =
* Fixed safe redirection

= 2.2.2 - 16 March 2024  =
* Fixed assets naming issues.
* Added php zip extension not installed notice.

= 2.2.1 - 30 November 2023 =
* Added webp image extension. Now this extension images will export also.

= 2.2.0 - 28 November 2023 =
* Made compatible with php version 8.2.
* Added "User roles can access" settings.
* Fixed very little security issue.
* Made some polishing.
* Fixed post searching issue.
* Fixed some more minor issues.

= 2.1.8 - 31 July 2023 =
* Fixed some minor issues.

= 2.1.7 - 28 June 2023 =
* Added review notice with "having problem" button.
* Added "Successfully exported" toast notification.

= 2.1.6 - 28 May 2023 =
* Fixed main site address still appearing issue in everywhere.

= 2.1.5 - 21 May 2023 =
* Fixed pro version direct installing issue.

= 2.1.4 - 23 December 2022 =
* Fixed a minor issue.

= 2.1.3 - 1 November 2022 =
* Fixed a major issue.

= 2.1.2 - 1 November 2022 =
* Fixed a minor issue.

= 2.1.1 - 12 August 2022 =
* Fixed some major issues.

= 2.1.0 - 23 June 2022 =
* Added html icon to the menu.
* Added documents exporting system.
* Added audios exporting system.
* Added linked videos exporting system.
* Fast exporting technique has been utilized.
* Fixed images, audios and documents url not exporting issue.

= 2.0.3 - 30 Septembar 2021 =
* Fixed one little issue.

= 2.0.2 - 14 Septembar 2021 =
* Make plugin compatible with PHP 7.3
* Reduce minimumInputLength to 1 for posts search

= 2.0.1 - 10 Septembar 2021 =
* Fixed little issues.

= 2.0.0 - 9 September 2021 =
* Added "Advanced Settings" Tab.
* Added checkbox "Create index.html on single page exporting".
* Added checkbox "Save all assets files to the specific directory (css, js, images, fonts)".
* Added textarea "Add contents to the header".
* Added textarea "Add contents to the footer".
* Added button "View Last Exported File".
* Added logs percentage system.
* Hide details logs system by default.
* Added skip assets functionalities.
* Fixed unlimited loading issue.
* Fixed minor issues.

= 1.0.3 - 22 April 2021 =
* Fixed little issues.

= 1.0.2 - 13 March 2021 =
* Fixed some major issues.

= 1.0.1 - 28 Jan 2021 =
* Fixed http site data getting issue.
* Fixed same filename conflict issue.
* Fixed single quotation in filename issue.
* increase posts per page to infinite.
* Added homepage option in the page select box.

= 1.0.0 - 22 May 2020 =
* Initialize the plugin


== Upgrade Notice ==

= 6.1.0 =
This release improves export reliability with enhanced retry logic, watchdog repair, and better background processing. Recommended update for all users.
