<?php
namespace WpToHtml;

/**
 * Renders the "What's New" admin page shown after plugin updates.
 */ 
class WhatsNew {

    public static function render() {
        $dashboard_url = admin_url('admin.php?page=wp-to-html');
        ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

            .wth-whats-new * { box-sizing: border-box; margin: 0; padding: 0; }

            .wth-whats-new {
                --accent: #4f6ef7;
                --accent-soft: #eef1fe;
                --accent-dark: #3b5be0;
                --green: #34d399;
                --green-soft: #ecfdf5;
                --red: #f87171;
                --red-soft: #fef2f2;
                --orange: #f59e0b;
                --orange-soft: #fffbeb;
                --purple: #a78bfa;
                --purple-soft: #f5f3ff;
                --text: #111827;
                --text2: #4b5563;
                --text3: #9ca3af;
                --border: #e5e7eb;
                --bg: #f8fafc;
                --card: #ffffff;
                --r: 16px;
                --font: 'Outfit', system-ui, -apple-system, sans-serif;

                font-family: var(--font);
                background: var(--bg);
                min-height: 100vh;
                padding: 40px 20px 60px;
                -webkit-font-smoothing: antialiased;
            }

            .wth-whats-new a { text-decoration: none; }

            .wth-container {
                max-width: 720px;
                margin: 0 auto;
            }

            /* ── Header ── */
            .wth-header {
                text-align: center;
                margin-bottom: 48px;
            }

            .wth-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: var(--accent-soft);
                color: var(--accent);
                font-weight: 600;
                font-size: 13px;
                padding: 6px 14px;
                border-radius: 20px;
                margin-bottom: 20px;
                letter-spacing: 0.02em;
            }

            .wth-badge svg { flex-shrink: 0; }

            .wth-title {
                font-size: 42px;
                font-weight: 800;
                color: var(--text);
                line-height: 1.15;
                margin-bottom: 12px;
                letter-spacing: -0.03em;
            }

            .wth-title span {
                background: linear-gradient(135deg, var(--accent), #8b5cf6);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }

            .wth-subtitle {
                font-size: 17px;
                color: var(--text2);
                font-weight: 400;
                line-height: 1.6;
                max-width: 520px;
                margin: 0 auto;
            }

            /* ── Version pill ── */
            .wth-version {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin-top: 16px;
                padding: 8px 16px;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: 10px;
                font-size: 14px;
                color: var(--text2);
                font-weight: 500;
            }
            .wth-version strong {
                color: var(--text);
                font-weight: 700;
            }

            /* ── Card list ── */
            .wth-cards {
                display: flex;
                flex-direction: column;
                gap: 12px;
                margin-bottom: 40px;
            }

            .wth-card {
                display: flex;
                gap: 16px;
                align-items: flex-start;
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: var(--r);
                padding: 20px 22px;
                transition: box-shadow 0.2s, border-color 0.2s;
            }

            .wth-card:hover {
                border-color: #d1d5db;
                box-shadow: 0 4px 24px rgba(0,0,0,0.04);
            }

            .wth-card-icon {
                flex-shrink: 0;
                width: 40px;
                height: 40px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
            }

            .wth-card-icon.improved  { background: var(--accent-soft); color: var(--accent); }
            .wth-card-icon.fixed     { background: var(--green-soft);  color: #059669; }
            .wth-card-icon.added     { background: var(--purple-soft); color: #7c3aed; }
            .wth-card-icon.removed   { background: var(--red-soft);    color: var(--red); }
            .wth-card-icon.core      { background: var(--orange-soft); color: var(--orange); }

            .wth-card-body {
                flex: 1;
                min-width: 0;
            }

            .wth-card-label {
                display: inline-block;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 4px;
            }

            .wth-card-label.improved  { color: var(--accent); }
            .wth-card-label.fixed     { color: #059669; }
            .wth-card-label.added     { color: #7c3aed; }
            .wth-card-label.removed   { color: var(--red); }
            .wth-card-label.core      { color: var(--orange); }

            .wth-card-text {
                font-size: 14.5px;
                color: var(--text);
                line-height: 1.55;
                font-weight: 400;
            }

            /* ── Previous releases section ── */
            .wth-prev-release {
                margin-bottom: 40px;
            }

            .wth-prev-release-heading {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 16px;
            }

            .wth-prev-release-heading hr {
                flex: 1;
                border: none;
                border-top: 1px solid var(--border);
            }

            .wth-prev-release-label {
                font-size: 12px;
                font-weight: 600;
                color: var(--text3);
                text-transform: uppercase;
                letter-spacing: 0.08em;
                white-space: nowrap;
            }

            .wth-prev-version-pill {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                font-size: 12px;
                font-weight: 600;
                color: var(--text3);
                background: var(--bg);
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 4px 10px;
                margin-bottom: 12px;
            }

            /* ── CTA ── */
            .wth-cta {
                text-align: center;
                margin-bottom: 24px;
            }

            .wth-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 14px 32px;
                background: var(--accent);
                color: #fff;
                font-family: var(--font);
                font-size: 15px;
                font-weight: 600;
                border: none;
                border-radius: 12px;
                cursor: pointer;
                transition: background 0.2s, transform 0.15s;
                letter-spacing: 0.01em;
            }

            .wth-btn:hover {
                background: var(--accent-dark);
                color: #fff;
                transform: translateY(-1px);
            }

            .wth-btn:active { transform: translateY(0); }

            .wth-dismiss {
                text-align: center;
            }

            .wth-dismiss a {
                font-size: 13px;
                color: var(--text3);
                transition: color 0.2s;
            }

            .wth-dismiss a:hover { color: var(--text2); }

            /* ── Responsive ── */
            @media (max-width: 600px) {
                .wth-whats-new { padding: 24px 12px 40px; }
                .wth-title { font-size: 30px; }
                .wth-subtitle { font-size: 15px; }
                .wth-card { padding: 16px; gap: 12px; }
                .wth-card-icon { width: 36px; height: 36px; font-size: 16px; }
            }
        </style>

        <div class="wth-whats-new">
            <div class="wth-container">

                <!-- Header -->
                <div class="wth-header">
                    <div class="wth-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Export WP Pages to Static HTML
                    </div>

                    <h1 class="wth-title"><?php esc_html_e("What's", 'wp-to-html'); ?> <span><?php esc_html_e('New', 'wp-to-html'); ?></span></h1>

                    <p class="wth-subtitle">
                        <?php esc_html_e('PDF exporting is back, the export experience is smoother, and the interface is cleaner and easier to understand.', 'wp-to-html'); ?>
                    </p>

                    <div class="wth-version">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/><line x1="17.5" y1="15" x2="9" y2="15"/></svg>
                        <?php esc_html_e('Version', 'wp-to-html'); ?> <strong>6.0.8.0</strong>
                    </div>
                </div>

                <!-- 6.0.8.0 Changelog Cards -->
                <div class="wth-cards">

                    <!-- Improved: Group assets by type now free -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('"Group assets by type" is now available to all users — no Pro required. Assets are automatically organised into /images, /css, and /js subdirectories. The option is now enabled by default for cleaner, more portable export packages.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Fixed: Parent posts in root dir for subdirectory installs -->
                    <div class="wth-card">
                        <div class="wth-card-icon fixed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label fixed"><?php esc_html_e('Fixed', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('"Parent posts in root dir" now works correctly on all WordPress installations, including sites hosted in a subdirectory. Top-level pages and posts are correctly saved as postname.html at the export root.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                </div>

                <!-- Previous Release: 6.0.7.0 -->
                <div class="wth-prev-release">
                    <div class="wth-prev-release-heading">
                        <hr>
                        <span class="wth-prev-release-label"><?php esc_html_e('Previous Release', 'wp-to-html'); ?></span>
                        <hr>
                    </div>

                    <div style="margin-bottom:16px;">
                        <span class="wth-prev-version-pill">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/></svg>
                            <?php esc_html_e('Version', 'wp-to-html'); ?> 6.0.7.0
                        </span>
                    </div>

                    <div class="wth-cards">

                        <div class="wth-card">
                            <div class="wth-card-icon fixed">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label fixed"><?php esc_html_e('Fixed', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Clicking Stop now immediately halts background export processing. Previously, an active background tick could continue running for several seconds after stopping.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Export activity log now records when an export is paused, resumed, or stopped by the user, making it easier to track what happened during an export session.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon core">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label core"><?php esc_html_e('Core', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Internal code improvements for better reliability and stability across different server environments.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Previous Release: 6.0.6.0 -->
                <div class="wth-prev-release">
                    <div class="wth-prev-release-heading">
                        <hr>
                        <span class="wth-prev-release-label"><?php esc_html_e('Previous Release', 'wp-to-html'); ?></span>
                        <hr>
                    </div>

                    <div style="margin-bottom:16px;">
                        <span class="wth-prev-version-pill">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/></svg>
                            <?php esc_html_e('Version', 'wp-to-html'); ?> 6.0.6.0
                        </span>
                    </div>

                    <div class="wth-cards">

                        <div class="wth-card">
                            <div class="wth-card-icon added">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label added"><?php esc_html_e('Added', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('PDF exporting functionality — generate a PDF of any page directly from the frontend with a single click.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Enhanced exporting experience — faster, more reliable, with better progress feedback throughout the process.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Redesigned layout that is easier to understand — cleaner sections, clearer labels, and a more intuitive flow.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Previous Release: 6.0.0 -->
                <div class="wth-prev-release">
                    <div class="wth-prev-release-heading">
                        <hr>
                        <span class="wth-prev-release-label"><?php esc_html_e('Previous Release', 'wp-to-html'); ?></span>
                        <hr>
                    </div>

                    <div style="margin-bottom:16px;">
                        <span class="wth-prev-version-pill">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/></svg>
                            <?php esc_html_e('Version', 'wp-to-html'); ?> 6.0.0
                        </span>
                    </div>

                    <div class="wth-cards">

                        <div class="wth-card">
                            <div class="wth-card-icon core">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label core"><?php esc_html_e('Core', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Refactored the core export engine for improved stability and performance.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Watchdog now automatically detects and repairs stalled export processes.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Enhanced failed URL tracking with per-URL retry counts and detailed error reporting.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Re-run only failed URLs without restarting the entire export process.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Implemented exponential backoff for asset retries to reduce server load.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Asset collection mode (Strict / Hybrid / Full) is now saved and respected across cron runs.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon fixed">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label fixed"><?php esc_html_e('Fixed', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('Export context is now correctly propagated to background workers during server cron execution.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon added">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label added"><?php esc_html_e('Added', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('single_root_index and root_parent_html options are now persisted within the export context.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                        <div class="wth-card">
                            <div class="wth-card-icon improved">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                            </div>
                            <div class="wth-card-body">
                                <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                                <div class="wth-card-text"><?php esc_html_e('More user-friendly interface and overall UX enhancements.', 'wp-to-html'); ?></div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- CTA -->
                <div class="wth-cta">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="wth-btn">
                        <?php esc_html_e('Go to Export Dashboard', 'wp-to-html'); ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>

                <div class="wth-dismiss">
                    <a href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('Skip and go to dashboard', 'wp-to-html'); ?></a>
                </div>

            </div>
        </div>
        <?php
    }
}
