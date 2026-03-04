<?php
namespace WpToHtml;

class Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'scripts']);
    }

    public function menu() {

        // Primary (Top-Level) Menu
        add_menu_page(
            __('Export WP Pages to Static HTML', 'wp-to-html'),
            __('Export WP Pages to Static HTML', 'wp-to-html'),
            'manage_options',
            'wp-to-html',
            [$this, 'page'],
            'dashicons-media-code', // HTML-related icon
            58 // Position (optional)
        );

        // Submenu: System Status
        add_submenu_page(
            'wp-to-html',
            __('WP to HTML System Status', 'wp-to-html'),
            __('System Status', 'wp-to-html'),
            'manage_options',
            'wp-to-html-system-status',
            [$this, 'system_status_page']
        );

        // Hidden page: What's New (no menu entry)
        add_submenu_page(
            null,
            __("What's New — Export WP Pages to Static HTML", 'wp-to-html'),
            '',
            'manage_options',
            'wp-to-html-whats-new',
            [$this, 'whats_new_page']
        );
    }

    public function scripts($hook) {
        if (
            $hook !== 'toplevel_page_wp-to-html' &&
            $hook !== 'wp-to-html_page_wp-to-html-system-status'
        ) {
            return;
        }
        // Cache-bust admin assets automatically on update.
        // Using filemtime avoids situations where the browser keeps an old admin.js/admin.css.
        $js_ver  = @filemtime(WP_TO_HTML_PATH . 'assets/admin.js') ?: WP_TO_HTML_VERSION;
        $css_ver = @filemtime(WP_TO_HTML_PATH . 'assets/admin.css') ?: WP_TO_HTML_VERSION;

        $admin_js = WP_TO_HTML_PATH . 'assets/admin.js';
        wp_enqueue_script(
            'wp-to-html-admin',
            WP_TO_HTML_URL . 'assets/admin.js',
            ['jquery'],
            file_exists($admin_js) ? filemtime($admin_js) : WP_TO_HTML_VERSION,
            true
        );


        $css_path = WP_TO_HTML_PATH . 'assets/admin.css';
        $css_ver = file_exists($css_path)
            ? filemtime($css_path)
            : WP_TO_HTML_VERSION;

        wp_enqueue_style(
            'wp-to-html-admin-css',
            WP_TO_HTML_URL . 'assets/admin.css',
            [],
            $css_ver
        );
      

        wp_localize_script('wp-to-html-admin', 'wpToHtmlData', [
            'rest_url'   => rest_url('wp_to_html/v1/export'),
            'status_url' => rest_url('wp_to_html/v1/status'),
            'poll_url'   => rest_url('wp_to_html/v1/poll'),
            'log_url'    => rest_url('wp_to_html/v1/log'),
            'pause_url'  => rest_url('wp_to_html/v1/pause'),
            'resume_url' => rest_url('wp_to_html/v1/resume'),
            'stop_url'   => rest_url('wp_to_html/v1/stop'),
            'content_url'=> rest_url('wp_to_html/v1/content'),
            'exports_url'=> rest_url('wp_to_html/v1/exports'),
            'download_url'=> rest_url('wp_to_html/v1/download'),
            'ftp_settings_url' => rest_url('wp_to_html/v1/ftp-settings'),
            'ftp_test_url'     => rest_url('wp_to_html/v1/ftp-test'),
            'ftp_list_url'     => rest_url('wp_to_html/v1/ftp-list'),
            // Pro integrations (routes are registered by the Pro add-on when active).
            's3_settings_url'  => rest_url('wp_to_html/v1/s3-settings'),
            's3_test_url'      => rest_url('wp_to_html/v1/s3-test'),
            'system_status_url' => rest_url('wp_to_html/v1/system-status'),
            'check_can_run_url' => rest_url('wp_to_html/v1/check-can-run'),
            'reset_diagnostics_url' => rest_url('wp_to_html/v1/reset-diagnostics'),
            'queue_reset_url'       => rest_url('wp_to_html/v1/queue-reset'),
            'clear_temp_url'        => rest_url('wp_to_html/v1/clear-temp'),
            'failed_urls_url'       => rest_url('wp_to_html/v1/failed-urls'),
            'rerun_failed_url'      => rest_url('wp_to_html/v1/rerun-failed'),
            'nonce'      => wp_create_nonce('wp_rest'),
            'pro_active' => (function_exists('wp_to_html_is_pro_active') && wp_to_html_is_pro_active()) ? 1 : 0,
            'post_types' => $this->get_public_post_types_for_picker(),
            // For All Posts scope: include core "post" + public CPTs that have at least one non-private item.
            'all_posts_post_types' => $this->get_post_types_for_all_posts_scope(),
        ]);


    }

    /**
     * Public post types (CPTs) for the Custom selector.
     * We exclude core post/page (handled by dedicated tabs) and attachment.
     */
    private function get_public_post_types_for_picker(): array {
        $objs = get_post_types([
            'public'  => true,
            'show_ui' => true,
        ], 'objects');

        $out = [];
        foreach ($objs as $name => $obj) {
            if ($name === 'post' || $name === 'page' || $name === 'attachment') continue;
            $out[] = [
                'name'  => $name,
                'label' => isset($obj->labels->singular_name) ? (string) $obj->labels->singular_name : (string) $name,
            ];
        }

        // Stable ordering for UI.
        usort($out, function($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return $out;
    }

    /**
     * Post types for the "All posts" scope UI.
     * Includes core "post" + public CPTs (excluding page/attachment) that have
     * at least one non-private item (publish/draft/pending/future).
     *
     * NOTE: This is UI-only metadata; the export payload decides which statuses to export.
     */
    private function get_post_types_for_all_posts_scope(): array {
        $objs = get_post_types([
            'public'  => true,
            'show_ui' => true,
        ], 'objects');

        $eligible_statuses = ['publish', 'draft', 'pending', 'future']; // explicitly excludes 'private'
        $out = [];

        foreach ($objs as $name => $obj) {
            if ($name === 'page' || $name === 'attachment') continue;

            // Count any non-private items; keep query light.
            $q = new \WP_Query([
                'post_type'      => $name,
                'post_status'    => $eligible_statuses,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $has_any = !empty($q->posts);
            if (!$has_any) continue;

            $label = isset($obj->labels->singular_name) ? (string) $obj->labels->singular_name : (string) $name;
            if ($name === 'post') {
                // Make it unambiguous in UI.
                $label = isset($obj->labels->name) ? (string) $obj->labels->name : 'Posts';
            }

            $out[] = [
                'name'  => $name,
                'label' => $label,
            ];
        }

        usort($out, function($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return $out;
    }
    private function admin_css() {
        return <<<CSS
/* WP to HTML glossy admin UI */

CSS
;
    }

    public function page() {
        $pro_active = (function_exists('wp_to_html_is_pro_active') && wp_to_html_is_pro_active());
        ?>

        <div class="wrap" id="wp-to-html-app">

            <!-- ══════ TOP BAR ══════ -->
            <header class="eh-topbar">
                <div class="eh-topbar-brand">
                    <div class="eh-topbar-icon"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
                    <h1><?php esc_html_e('Export WP Pages to Static HTML', 'wp-to-html'); ?></h1>
                    <small>v<?php echo esc_html(defined('WP_TO_HTML_VERSION') ? WP_TO_HTML_VERSION : '1.0.0'); ?></small>
                </div>
                <div class="eh-topbar-actions">
                    <a href="https://myrecorp.com/documentation/export-wp-page-to-static-html-documentation.html" target="_blank" rel="noopener noreferrer" class="eh-topbar-utility-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        <?php esc_html_e('Documentation', 'wp-to-html'); ?>
                    </a>
                    <a href="https://myrecorp.com/wp/contact-us/" target="_blank" rel="noopener noreferrer" class="eh-topbar-utility-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <?php esc_html_e('Support', 'wp-to-html'); ?>
                    </a>
                    <?php if (!$pro_active): ?>
                    <a href="https://myrecorp.com/export-wp-page-to-static-html-pro.html" target="_blank" rel="noopener noreferrer" class="eh-topbar-upgrade-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <?php esc_html_e('Upgrade to Pro', 'wp-to-html'); ?>
                    </a>
                    <?php endif; ?>
                    <button type="button" class="eh-topbar-review-btn" id="eh-review-btn">
                        <span class="eh-review-stars-static">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                        <?php esc_html_e('Rate us', 'wp-to-html'); ?>
                    </button>
                </div>
                <nav class="eh-topbar-nav" role="tablist">
                    <button type="button" id="eh-tab-export" role="tab" aria-pressed="true"><?php esc_html_e('Export', 'wp-to-html'); ?></button>
                    <button type="button" id="eh-tab-settings" role="tab" aria-pressed="false"><?php esc_html_e('Settings', 'wp-to-html'); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-to-html-system-status' ) ); ?>" class="eh-tab-link"><?php esc_html_e('System Status', 'wp-to-html'); ?></a>
                </nav>
            </header>

            <!-- ══════ 3-COLUMN GRID ══════ -->
            <div class="eh-grid">

                <!-- ── COL 1: Sidebar ── -->
                <aside class="eh-sidebar eh-overlay" id="eh-scope-card">

                    <div id="eh-panel-export">

                    <!-- Sticky scope bar -->
                    <div class="eh-scope-head">
                        <span class="eh-scope-label"><?php esc_html_e('Export Scope', 'wp-to-html'); ?></span>
                        <div class="eh-seg" role="tablist" aria-label="<?php esc_attr_e('Export scope', 'wp-to-html'); ?>">
                            <button type="button" id="eh-scope-custom" role="tab" aria-pressed="true"><?php esc_html_e('Custom', 'wp-to-html'); ?></button>
                            <button type="button" id="eh-scope-all-pages" role="tab" aria-pressed="false"><?php esc_html_e('All pages', 'wp-to-html'); ?></button>
                            <?php if ($pro_active): ?>
                            <button type="button" id="eh-scope-all-posts" role="tab" aria-pressed="false"><?php esc_html_e('All posts', 'wp-to-html'); ?></button>
                            <button type="button" id="eh-scope-full" role="tab" aria-pressed="false"><?php esc_html_e('Full site', 'wp-to-html'); ?></button>
                            <?php endif; ?>
                        </div>
                        <?php if (!$pro_active): ?>
                        <div class="eh-seg-pro" role="tablist" aria-label="<?php esc_attr_e('Pro export scopes', 'wp-to-html'); ?>">
                            <button type="button" id="eh-scope-all-posts" role="tab" aria-pressed="false" data-pro="1"><?php esc_html_e('All posts', 'wp-to-html'); ?> <svg class="eh-scope-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button>
                            <button type="button" id="eh-scope-full" role="tab" aria-pressed="false" data-pro="1"><?php esc_html_e('Full site', 'wp-to-html'); ?> <svg class="eh-scope-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- All posts scope: post type filter (hidden by default) -->
                    <div id="eh-all-posts-types" style="display:none;">
                        <details class="eh-acc" open>
                            <summary><span class="eh-acc-dot b"></span><?php esc_html_e('Post types (non-private)', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                            <div class="eh-acc-body">
                                <p class="eh-muted"><?php echo wp_kses(__('Choose which post types to include when exporting <strong>All posts</strong>.', 'wp-to-html'), ['strong' => []]); ?></p>
                                <div class="eh-checks" id="eh-all-posts-types-list"></div>
                                <p class="eh-hint"><?php echo wp_kses(__('If none are selected, WP to HTML defaults to <code>post</code>.', 'wp-to-html'), ['code' => []]); ?></p>
                            </div>
                        </details>
                    </div>

                    <!-- Post Type & Scope -->
                    <details class="eh-acc" open id="eh-acc-post-type-scope">
                        <summary><span class="eh-acc-dot g"></span><?php esc_html_e('Post Type & Scope', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <div id="eh-selector">
                                <div class="eh-sel-bar">
                                    <div class="eh-seg" role="tablist" aria-label="<?php esc_attr_e('Content type', 'wp-to-html'); ?>">
                                        <button type="button" id="eh-tab-posts" role="tab" aria-pressed="true"><?php esc_html_e('Posts', 'wp-to-html'); ?></button>
                                        <button type="button" id="eh-tab-pages" role="tab" aria-pressed="false"><?php esc_html_e('Pages', 'wp-to-html'); ?></button>
                                        <button type="button" id="eh-tab-types" role="tab" aria-pressed="false" style="display:none;"><?php esc_html_e('Post types', 'wp-to-html'); ?></button>
                                    </div>
                                    <button type="button" class="eh-btn-s" id="eh-select-all"><?php esc_html_e('Select all', 'wp-to-html'); ?></button>
                                    <button type="button" class="eh-btn-s" id="eh-clear"><?php esc_html_e('Clear', 'wp-to-html'); ?></button>
                                    <span class="eh-badge"><?php esc_html_e('Selected:', 'wp-to-html'); ?> <strong id="eh-selected-count">0</strong></span>
                                    <span class="spinner eh-inline-spinner" id="eh-content-spinner"></span>
                                </div>
                                <div class="eh-row" id="eh-post-type-row" style="margin-top:8px;display:none;align-items:center;gap:8px;">
                                    <select id="eh-post-type-select" style="max-width:220px;"></select>
                                    <span class="eh-hint"><?php esc_html_e('Select a custom post type.', 'wp-to-html'); ?></span>
                                </div>
                                <div class="eh-search">
                                    <input type="text" id="eh-search" placeholder="<?php esc_attr_e('Search by title…', 'wp-to-html'); ?>">
                                </div>
                                <div class="eh-list" id="eh-content-list" aria-live="polite"></div>
                                <p class="eh-hint" style="margin-top:4px;"><?php esc_html_e('Scroll to load more. Search filters the list.', 'wp-to-html'); ?></p>
                            </div>
                        </div>
                    </details>

                    <!-- Post Status -->
                    <details class="eh-acc" id="eh-acc-post-status">
                        <summary><span class="eh-acc-dot b"></span><?php esc_html_e('Post Status', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <div class="eh-checks">
                                <label><input type="checkbox" class="eh-status" value="publish" checked> <?php esc_html_e('Publish', 'wp-to-html'); ?></label>
                                <label><input type="checkbox" class="eh-status" value="draft"> <?php esc_html_e('Draft', 'wp-to-html'); ?></label>
                                <label><input type="checkbox" class="eh-status" value="private"> <?php esc_html_e('Private', 'wp-to-html'); ?></label>
                                <label><input type="checkbox" class="eh-status" value="pending"> <?php esc_html_e('Pending', 'wp-to-html'); ?></label>
                                <label><input type="checkbox" class="eh-status" value="future"> <?php esc_html_e('Schedule', 'wp-to-html'); ?></label>
                            </div>
                            <p class="eh-hint"><?php esc_html_e('Non-public statuses may fail if URL is not publicly accessible.', 'wp-to-html'); ?></p>
                        </div>
                    </details>

                    <?php $roles_obj = function_exists('wp_roles') ? wp_roles() : null; $roles = ($roles_obj && !empty($roles_obj->roles)) ? $roles_obj->roles : []; ?>

                    <!-- Login Role -->
                    <details class="eh-acc">
                        <summary><span class="eh-acc-dot"></span><?php esc_html_e('Login Role', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <select id="wp-to-html-export-as">
                                <?php echo '<option value="" selected>' . esc_html__('Select a user role', 'wp-to-html') . '</option>';
                                foreach ($roles as $key => $r) { $name = isset($r['name']) ? $r['name'] : $key; printf('<option value="%s">%s</option>', esc_attr($key), esc_html($name)); } ?>
                            </select>
                            <p class="eh-hint"><?php esc_html_e('Exports pages as they appear to the selected role. A temporary user is created and deleted after export.', 'wp-to-html'); ?></p>
                        </div>
                    </details>

                    <!-- Asset Options -->
                    <details class="eh-acc">
                        <summary><span class="eh-acc-dot o"></span><?php esc_html_e('Asset Options', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <span class="eh-field-label"><?php esc_html_e('Collection mode', 'wp-to-html'); ?></span>
                            <select id="wp-to-html-asset-collection-mode">
                                <option value="strict"><?php esc_html_e('Strict (referenced only)', 'wp-to-html'); ?></option>
                                <option value="hybrid" selected><?php esc_html_e('Hybrid (referenced + media)', 'wp-to-html'); ?></option>
                                <option value="full"><?php esc_html_e('Full (all uploads + theme assets)', 'wp-to-html'); ?></option>
                            </select>
                            <label class="eh-toggle"><input type="checkbox" id="save_assets_grouped" value="1" <?php echo $pro_active ? 'checked' : 'disabled data-pro="1"'; ?>><span><?php esc_html_e('Group assets by type', 'wp-to-html'); ?><?php if (!$pro_active): ?> 🔒<?php endif; ?></span></label>
                            <p class="eh-hint">
                                <?php echo wp_kses(__('When enabled, all exported assets are automatically organized into clean subdirectories: <code>/images</code>, <code>/css</code>, <code>/js</code>. The result is a well-structured, developer-friendly HTML package that is easy to hand off or deploy.', 'wp-to-html'), array('code' => array())); ?>
                                <?php if (!$pro_active): ?> 
                                <span class="eh-pro-badge">PRO</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </details>

                    <!-- Homepage & Structure -->
                    <details class="eh-acc">
                        <summary><span class="eh-acc-dot"></span><?php esc_html_e('Homepage & Structure', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <label class="eh-toggle"><input type="checkbox" id="wp-to-html-include-home"><span><?php esc_html_e('Include homepage', 'wp-to-html'); ?></span></label>
                            <label class="eh-toggle"><input type="checkbox" id="wp-to-html-root-parent-html"><span><?php esc_html_e('Parent posts in root dir', 'wp-to-html'); ?></span></label>
                            <p class="eh-hint"><?php echo wp_kses(__('Saves <code>/postname/</code> as <code>/postname.html</code> in export root.', 'wp-to-html'), ['code' => []]); ?></p>
                        </div>
                    </details>

                    <!-- Delivery & Notifications -->
                    <details class="eh-acc">
                        <summary><span class="eh-acc-dot"></span><?php esc_html_e('Delivery & Notifications', 'wp-to-html'); ?><svg class="eh-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></summary>
                        <div class="eh-acc-body">
                            <label class="eh-toggle"><input type="checkbox" id="wp-to-html-upload-ftp"><span><?php esc_html_e('Upload to FTP', 'wp-to-html'); ?></span></label>
                            <div id="wp-to-html-ftp-remote-wrap" style="display:none;">
                                <span class="eh-field-label"><?php esc_html_e('Remote path', 'wp-to-html'); ?></span>
                                <div class="eh-input-row"><input type="text" id="wp-to-html-ftp-remote-path" placeholder="<?php esc_attr_e('/public_html/exports', 'wp-to-html'); ?>"><button type="button" class="eh-btn-s" id="wp-to-html-ftp-remote-browse"><?php esc_html_e('Browse', 'wp-to-html'); ?></button></div>
                            </div>
                            <label class="eh-toggle"><input type="checkbox" id="wp-to-html-upload-s3" <?php echo $pro_active ? '' : 'disabled data-pro="1" title="Requires Export WP Pages to Static HTML Pro"'; ?>><span><?php esc_html_e('Upload to AWS S3', 'wp-to-html'); ?><?php echo $pro_active ? '' : ' 🔒'; ?></span></label>
                            <div id="wp-to-html-s3-prefix-wrap" style="display:none;">
                                <span class="eh-field-label"><?php esc_html_e('S3 key prefix', 'wp-to-html'); ?></span>
                                <input type="text" id="wp-to-html-s3-prefix" placeholder="<?php esc_attr_e('exports/', 'wp-to-html'); ?>">
                            </div>
                            <label class="eh-toggle"><input type="checkbox" id="wp-to-html-notify-complete"><span><?php esc_html_e('Notify on complete', 'wp-to-html'); ?></span></label>
                            <div id="wp-to-html-notify-emails-wrap" style="display:none;">
                                <span class="eh-field-label"><?php esc_html_e('Additional emails', 'wp-to-html'); ?></span>
                                <textarea id="wp-to-html-notify-emails" rows="2" placeholder="<?php esc_attr_e('you@example.com, teammate@example.com', 'wp-to-html'); ?>"></textarea>
                            </div>
                        </div>
                    </details>

                    </div><!-- /#eh-panel-export -->

                </aside>

                <!-- ── COL 2: Center (Progress) ── -->
                <main class="eh-center">
                    <div class="eh-center-inner">
                        <div class="eh-ring-wrap">
                            <svg class="eh-ring-svg" viewBox="0 0 160 160">
                                <circle class="eh-ring-bg" cx="80" cy="80" r="70"/>
                                <circle class="eh-ring-fg" id="eh-ring-fg" cx="80" cy="80" r="70"/>
                                <text x="80" y="73" class="eh-ring-pct" id="eh-ring-pct" text-anchor="middle" dominant-baseline="central">0%</text>
                                <text x="80" y="97" class="eh-ring-sub" text-anchor="middle" dominant-baseline="central"><?php esc_html_e('progress', 'wp-to-html'); ?></text>
                            </svg>
                        </div>
                        <button id="wp-to-html-start" type="button">
                            <span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7L8 5z"/></svg></span>
                            <?php esc_html_e('Start Export', 'wp-to-html'); ?>
                        </button>
                        <span class="spinner eh-inline-spinner" id="eh-start-spinner"></span>
                        <div class="eh-run-controls">
                            <button id="wp-to-html-pause" class="button eh-has-icon" style="display:none;"><span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M8 5h3v14H8zM13 5h3v14h-3z"/></svg></span><?php esc_html_e('Pause', 'wp-to-html'); ?></button>
                            <button id="wp-to-html-resume" class="button eh-has-icon" style="display:none;"><span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7L8 5z"/></svg></span><?php esc_html_e('Resume', 'wp-to-html'); ?></button>
                            <button id="wp-to-html-stop" class="button eh-danger eh-has-icon" style="display:none;"><span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M7 7h10v10H7z"/></svg></span><?php esc_html_e('Stop', 'wp-to-html'); ?></button>
                            <button id="eh-preview" class="button eh-has-icon" style="display:none;" disabled><span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span><?php esc_html_e('Preview', 'wp-to-html'); ?></button>
                            <a id="eh-download-zip" class="button button-primary eh-has-icon" href="#" style="display:none;" download><span class="eh-icon"><svg viewBox="0 0 24 24"><path d="M12 3v10"/><path d="M8 9l4 4 4-4"/><path d="M5 19h14"/></svg></span><?php esc_html_e('Download ZIP', 'wp-to-html'); ?></a>
                        </div>
                        <div class="eh-status-bar">
                            <div class="eh-big" id="wp-to-html-result"><?php esc_html_e('Idle', 'wp-to-html'); ?></div>
                            <div class="eh-hint" id="eh-scope-hint"></div>
                        </div>
                        <div id="wp-to-html-result-extra" class="eh-result-extra"></div>
                    </div>
                </main>

                <!-- ── COL 3: Log Panel ── -->
                <section class="eh-logpanel">
                    <div class="eh-logpanel-head">
                        <span class="eh-logpanel-title"><?php esc_html_e('Live Log', 'wp-to-html'); ?></span>
                        <button type="button" class="eh-btn-s" id="eh-copy-log"><?php esc_html_e('Copy', 'wp-to-html'); ?></button>
                    </div>
                    <pre id="wp-to-html-log"></pre>
                </section>

            </div><!-- /.eh-grid -->

            <!-- ══════ FULL-WIDTH SETTINGS PAGE ══════ -->
            <div id="eh-panel-settings" class="eh-settings-page" style="display:none;">

                <?php
                $missing_exts = [];
                if ( ! class_exists('ZipArchive') )     $missing_exts[] = 'zip (ZipArchive)';
                if ( ! function_exists('ftp_connect') )  $missing_exts[] = 'ftp';
                if ( $missing_exts ) :
                ?>
                <div class="eh-ext-notice">
                    <svg class="eh-ext-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <div>
                        <strong><?php esc_html_e('Required PHP extension(s) missing:', 'wp-to-html'); ?></strong>
                        <code><?php echo esc_html( implode( ', ', $missing_exts ) ); ?></code>
                        &mdash;
                        <?php esc_html_e('Some features may not work correctly. Please ask your hosting provider to enable the missing extension(s) or check the System Status page for details.', 'wp-to-html'); ?>
                        <a href="<?php echo esc_url( admin_url('admin.php?page=wp-to-html-system-status') ); ?>"><?php esc_html_e('View System Status →', 'wp-to-html'); ?></a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="eh-settings-hero">
                    <div class="eh-settings-hero-inner">
                        <div class="eh-settings-hero-icon">
                            <svg viewBox="0 0 24 24"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </div>
                        <div>
                            <h2><?php esc_html_e('Settings', 'wp-to-html'); ?></h2>
                            <p><?php esc_html_e('Configure your deployment destinations and connection details.', 'wp-to-html'); ?></p>
                        </div>
                    </div>
                    <div class="eh-settings-tabs-bar">
                        <button type="button" id="eh-settings-tab-ftp" class="eh-settings-tab is-active" role="tab" aria-pressed="true">
                            <svg viewBox="0 0 24 24"><path d="M22 12H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><path d="M6 16h.01M10 16h.01"/></svg>
                            <?php esc_html_e('FTP / SFTP', 'wp-to-html'); ?>
                        </button>
                        <button type="button" id="eh-settings-tab-s3" class="eh-settings-tab" role="tab" aria-pressed="false" <?php echo $pro_active ? '' : 'disabled data-pro="1"'; ?>>
                            <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                            <?php esc_html_e('AWS S3', 'wp-to-html'); ?>
                            <?php echo $pro_active ? '' : '<span class="eh-pro-badge">PRO</span>'; ?>
                        </button>
                    </div>
                </div>

                <div class="eh-settings-body">

                    <!-- FTP Panel -->
                    <div id="eh-settings-panel-ftp" class="eh-settings-section">
                        <div class="eh-settings-section-grid">

                            <div class="eh-settings-block">
                                <div class="eh-settings-block-head">
                                    <div class="eh-settings-block-icon" style="background:linear-gradient(135deg,#6366f1,#818cf8)">
                                        <svg viewBox="0 0 24 24"><path d="M22 12H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                                    </div>
                                    <div>
                                        <h3><?php esc_html_e('Server Connection', 'wp-to-html'); ?></h3>
                                        <p><?php esc_html_e('Enter your FTP server details below.', 'wp-to-html'); ?></p>
                                    </div>
                                </div>
                                <div class="eh-settings-block-body">
                                    <div class="eh-fs-row">
                                        <div class="eh-fs-field eh-fs-grow">
                                            <label class="eh-fs-label"><?php esc_html_e('Hostname', 'wp-to-html'); ?></label>
                                            <input type="text" id="wp-to-html-ftp-host" placeholder="ftp.example.com">
                                        </div>
                                        <div class="eh-fs-field" style="max-width:110px">
                                            <label class="eh-fs-label"><?php esc_html_e('Port', 'wp-to-html'); ?></label>
                                            <input type="number" id="wp-to-html-ftp-port" value="21" min="1" max="65535">
                                        </div>
                                    </div>
                                    <div class="eh-fs-row">
                                        <div class="eh-fs-field eh-fs-grow">
                                            <label class="eh-fs-label"><?php esc_html_e('Username', 'wp-to-html'); ?></label>
                                            <input type="text" id="wp-to-html-ftp-user" autocomplete="username">
                                        </div>
                                        <div class="eh-fs-field eh-fs-grow">
                                            <label class="eh-fs-label"><?php esc_html_e('Password', 'wp-to-html'); ?></label>
                                            <input type="password" id="wp-to-html-ftp-pass" autocomplete="new-password">
                                        </div>
                                    </div>
                                    <div class="eh-fs-options">
                                        <label class="eh-fs-check"><input type="checkbox" id="wp-to-html-ftp-ssl"> <span><?php esc_html_e('Use FTPS (SSL)', 'wp-to-html'); ?></span></label>
                                        <label class="eh-fs-check"><input type="checkbox" id="wp-to-html-ftp-passive" checked> <span><?php esc_html_e('Passive mode', 'wp-to-html'); ?></span></label>
                                        <div class="eh-fs-inline">
                                            <span><?php esc_html_e('Timeout', 'wp-to-html'); ?></span>
                                            <input type="number" id="wp-to-html-ftp-timeout" value="20" min="5" max="120" style="width:64px">
                                            <span><?php esc_html_e('seconds', 'wp-to-html'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="eh-settings-block">
                                <div class="eh-settings-block-head">
                                    <div class="eh-settings-block-icon" style="background:linear-gradient(135deg,#10b981,#34d399)">
                                        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                    </div>
                                    <div>
                                        <h3><?php esc_html_e('Remote Paths', 'wp-to-html'); ?></h3>
                                        <p><?php esc_html_e('Set where your exported files will be uploaded.', 'wp-to-html'); ?></p>
                                    </div>
                                </div>
                                <div class="eh-settings-block-body">
                                    <div class="eh-fs-field">
                                        <label class="eh-fs-label"><?php esc_html_e('Base path', 'wp-to-html'); ?></label>
                                        <input type="text" id="wp-to-html-ftp-base" placeholder="/public_html">
                                        <span class="eh-fs-hint"><?php esc_html_e('Root directory of your FTP server.', 'wp-to-html'); ?></span>
                                    </div>
                                    <div class="eh-fs-field">
                                        <label class="eh-fs-label"><?php esc_html_e('Default remote path', 'wp-to-html'); ?></label>
                                        <div class="eh-fs-input-row">
                                            <input type="text" id="wp-to-html-ftp-default-path" placeholder="/public_html/exports">
                                            <button type="button" class="eh-fs-browse-btn" id="wp-to-html-ftp-default-browse">
                                                <svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                                <?php esc_html_e('Browse', 'wp-to-html'); ?>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="eh-settings-footer">
                            <div class="eh-settings-footer-left">
                                <div id="wp-to-html-ftp-msg" class="eh-fs-msg"></div>
                            </div>
                            <div class="eh-settings-footer-right">
                                <button type="button" class="eh-fs-btn eh-fs-btn-ghost" id="wp-to-html-ftp-test">
                                    <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                    <?php esc_html_e('Test Connection', 'wp-to-html'); ?>
                                    <span class="spinner eh-inline-spinner" id="wp-to-html-ftp-spinner"></span>
                                </button>
                                <button type="button" class="eh-fs-btn eh-fs-btn-primary" id="wp-to-html-ftp-save">
                                    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('Save Settings', 'wp-to-html'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- S3 Panel -->
                    <div id="eh-settings-panel-s3" class="eh-settings-section" style="display:none;">
                        <div class="eh-settings-section-grid">

                            <div class="eh-settings-block">
                                <div class="eh-settings-block-head">
                                    <div class="eh-settings-block-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">
                                        <svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                                    </div>
                                    <div>
                                        <h3><?php esc_html_e('Bucket &amp; Region', 'wp-to-html'); ?></h3>
                                        <p><?php esc_html_e('Your AWS S3 bucket name and region.', 'wp-to-html'); ?></p>
                                    </div>
                                </div>
                                <div class="eh-settings-block-body">
                                    <div class="eh-fs-row">
                                        <div class="eh-fs-field eh-fs-grow">
                                            <label class="eh-fs-label"><?php esc_html_e('Bucket name', 'wp-to-html'); ?></label>
                                            <input type="text" id="wp-to-html-s3-bucket" placeholder="my-bucket" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="eh-fs-field eh-fs-grow">
                                            <label class="eh-fs-label"><?php esc_html_e('Region', 'wp-to-html'); ?></label>
                                            <input type="text" id="wp-to-html-s3-region" placeholder="ap-south-1" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                        </div>
                                    </div>
                                    <div class="eh-fs-field">
                                        <label class="eh-fs-label"><?php esc_html_e('Default prefix', 'wp-to-html'); ?></label>
                                        <input type="text" id="wp-to-html-s3-prefix-default" placeholder="exports/" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                        <span class="eh-fs-hint"><?php esc_html_e('Optional folder prefix inside the bucket.', 'wp-to-html'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="eh-settings-block">
                                <div class="eh-settings-block-head">
                                    <div class="eh-settings-block-icon" style="background:linear-gradient(135deg,#ef4444,#f87171)">
                                        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </div>
                                    <div>
                                        <h3><?php esc_html_e('IAM Credentials', 'wp-to-html'); ?></h3>
                                        <p><?php esc_html_e('Your AWS access key and secret. Stored encrypted.', 'wp-to-html'); ?></p>
                                    </div>
                                </div>
                                <div class="eh-settings-block-body">
                                    <div class="eh-fs-field">
                                        <label class="eh-fs-label"><?php esc_html_e('Access key ID', 'wp-to-html'); ?></label>
                                        <input type="text" id="wp-to-html-s3-access" autocomplete="off" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                    </div>
                                    <div class="eh-fs-field">
                                        <label class="eh-fs-label"><?php esc_html_e('Secret access key', 'wp-to-html'); ?></label>
                                        <input type="password" id="wp-to-html-s3-secret" autocomplete="new-password" placeholder="<?php esc_attr_e('(leave blank to keep current)', 'wp-to-html'); ?>" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                    </div>
                                    <?php if (!$pro_active): ?>
                                    <div class="eh-fs-pro-notice">
                                        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                        <?php echo wp_kses(__('<strong>Export WP Pages to Static HTML Pro</strong> is required to use AWS S3 integration.', 'wp-to-html'), ['strong' => []]); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                        <div class="eh-settings-footer">
                            <div class="eh-settings-footer-left">
                                <div id="wp-to-html-s3-msg" class="eh-fs-msg"></div>
                            </div>
                            <div class="eh-settings-footer-right">
                                <button type="button" class="eh-fs-btn eh-fs-btn-ghost" id="wp-to-html-s3-test" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                    <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                    <?php esc_html_e('Test Connection', 'wp-to-html'); ?>
                                    <span class="spinner eh-inline-spinner" id="wp-to-html-s3-spinner"></span>
                                </button>
                                <button type="button" class="eh-fs-btn eh-fs-btn-primary" id="wp-to-html-s3-save" <?php echo $pro_active ? '' : 'disabled'; ?>>
                                    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php esc_html_e('Save Settings', 'wp-to-html'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div><!-- /#eh-panel-settings -->

        </div><!-- /#wp-to-html-app -->

        <!-- Preview Modal -->
        <div class="eh-modal" id="eh-preview-modal" style="display:none;">
            <div class="eh-modal-backdrop" id="eh-preview-close"></div>
            <div class="eh-modal-card">
                <div class="eh-row" style="justify-content:space-between;align-items:center;">
                    <div style="font-weight:800;font-size:16px;"><?php esc_html_e('Preview exported files', 'wp-to-html'); ?></div>
                    <button type="button" class="button" id="eh-preview-close-btn"><?php esc_html_e('Close', 'wp-to-html'); ?></button>
                </div>
                <div class="eh-muted" style="margin-top:6px;"><?php esc_html_e('Click a file to open preview in a new tab.', 'wp-to-html'); ?></div>
                <div class="eh-row" style="justify-content:space-between;align-items:center;margin-top:10px;gap:10px;flex-wrap:wrap;">
                    <div class="eh-tabs" id="eh-preview-tabs"></div>
                    <a id="eh-preview-download-group" class="button button-primary" href="#" style="display:none;" download><?php esc_html_e('Download this group as ZIP', 'wp-to-html'); ?></a>
                </div>
                <div class="eh-preview-list-wrap">
                    <div class="eh-list" id="eh-preview-list"></div>
                    <div class="eh-preview-pagination" id="eh-preview-pagination" style="display:none;">
                        <button type="button" class="eh-page-btn" id="eh-page-first" title="<?php esc_attr_e('First page','wp-to-html'); ?>"><svg viewBox="0 0 24 24"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
                        <button type="button" class="eh-page-btn" id="eh-page-prev"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                        <div class="eh-page-numbers" id="eh-page-numbers"></div>
                        <button type="button" class="eh-page-btn" id="eh-page-next"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
                        <button type="button" class="eh-page-btn" id="eh-page-last" title="<?php esc_attr_e('Last page','wp-to-html'); ?>"><svg viewBox="0 0 24 24"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>
                        <span class="eh-page-info" id="eh-page-info"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- FTP Browser Modal -->
        <div id="wp-to-html-ftp-browser-modal" class="eh-modal" style="display:none;">
            <div class="eh-modal-inner">
                <div class="eh-modal-head">
                    <div style="font-weight:800;"><?php esc_html_e('Select remote folder', 'wp-to-html'); ?></div>
                    <button type="button" class="button" id="wp-to-html-ftp-browser-close">✕</button>
                </div>
                <div class="eh-modal-body">
                    <div class="eh-row" style="gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="wp-to-html-ftp-browser-path" style="min-width:260px;flex:1;" placeholder="/">
                        <button type="button" class="button" id="wp-to-html-ftp-browser-up"><?php esc_html_e('Up', 'wp-to-html'); ?></button>
                        <button type="button" class="button" id="wp-to-html-ftp-browser-refresh"><?php esc_html_e('Refresh', 'wp-to-html'); ?></button>
                    </div>
                    <div id="wp-to-html-ftp-browser-msg" class="eh-hint" style="margin-top:8px;"></div>
                    <div id="wp-to-html-ftp-browser-list" class="eh-browser-list" style="margin-top:10px;"></div>
                </div>
                <div class="eh-modal-foot">
                    <button type="button" class="button" id="wp-to-html-ftp-browser-cancel"><?php esc_html_e('Cancel', 'wp-to-html'); ?></button>
                    <button type="button" class="button button-primary" id="wp-to-html-ftp-browser-select"><?php esc_html_e('Use this folder', 'wp-to-html'); ?></button>
                </div>
            </div>
        </div>

        <!-- Review Modal -->
        <div id="eh-review-modal" class="eh-modal" style="display:none;">
            <div class="eh-modal-backdrop" id="eh-review-modal-backdrop"></div>
            <div class="eh-pro-modal-card" style="max-width:400px;">
                <div class="eh-pro-modal-head">
                    <div class="eh-pro-modal-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </div>
                    <div class="eh-pro-modal-title"><?php esc_html_e('Enjoying the plugin?', 'wp-to-html'); ?></div>
                    <button type="button" class="eh-pro-modal-x" id="eh-review-modal-close" aria-label="<?php esc_attr_e('Close', 'wp-to-html'); ?>">&#x2715;</button>
                </div>
                <div class="eh-pro-modal-body">
                    <p class="eh-pro-modal-lead"><?php esc_html_e('How many stars would you give us?', 'wp-to-html'); ?></p>
                    <div class="eh-review-stars" id="eh-review-stars" role="group" aria-label="<?php esc_attr_e('Star rating', 'wp-to-html'); ?>">
                        <button type="button" data-star="1" aria-label="<?php esc_attr_e('1 star', 'wp-to-html'); ?>">&#9733;</button>
                        <button type="button" data-star="2" aria-label="<?php esc_attr_e('2 stars', 'wp-to-html'); ?>">&#9733;</button>
                        <button type="button" data-star="3" aria-label="<?php esc_attr_e('3 stars', 'wp-to-html'); ?>">&#9733;</button>
                        <button type="button" data-star="4" aria-label="<?php esc_attr_e('4 stars', 'wp-to-html'); ?>">&#9733;</button>
                        <button type="button" data-star="5" aria-label="<?php esc_attr_e('5 stars', 'wp-to-html'); ?>">&#9733;</button>
                    </div>
                    <div id="eh-review-feedback" style="display:none;">
                        <label class="eh-fs-label" for="eh-review-feedback-text"><?php esc_html_e('Why don\'t you like the plugin?', 'wp-to-html'); ?></label>
                        <textarea id="eh-review-feedback-text" rows="4" placeholder="<?php esc_attr_e('Tell us how we can improve...', 'wp-to-html'); ?>"></textarea>
                        <div id="eh-review-feedback-msg" class="eh-fs-msg" style="margin-top:6px;"></div>
                    </div>
                </div>
                <div class="eh-pro-modal-foot">
                    <button type="button" class="eh-pro-modal-dismiss" id="eh-review-modal-dismiss"><?php esc_html_e('Maybe later', 'wp-to-html'); ?></button>
                    <button type="button" class="eh-fs-btn eh-fs-btn-primary" id="eh-review-submit" style="display:none;"><?php esc_html_e('Submit Feedback', 'wp-to-html'); ?></button>
                </div>
            </div>
        </div>

        <!-- Pro Upsell Modal -->
        <div id="eh-pro-modal" class="eh-modal" style="display:none;">
            <div class="eh-modal-backdrop" id="eh-pro-modal-backdrop"></div>
            <div class="eh-pro-modal-card">
                <div class="eh-pro-modal-head">
                    <div class="eh-pro-modal-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div class="eh-pro-modal-title"><?php esc_html_e('Unlock Pro Features', 'wp-to-html'); ?></div>
                    <button type="button" class="eh-pro-modal-x" id="eh-pro-modal-close-btn" aria-label="<?php esc_attr_e('Close', 'wp-to-html'); ?>">&#x2715;</button>
                </div>
                <div class="eh-pro-modal-body">
                    <p class="eh-pro-modal-lead"><?php esc_html_e('Export your entire WordPress site in bulk — upgrade to Pro.', 'wp-to-html'); ?></p>
                    <ul class="eh-pro-feat-list">
                        <li><span class="eh-pro-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span><div><strong><?php esc_html_e('All Posts', 'wp-to-html'); ?></strong> &mdash; <?php esc_html_e('export every post type in one click', 'wp-to-html'); ?></div></li>
                        <li><span class="eh-pro-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span><div><strong><?php esc_html_e('Full Site', 'wp-to-html'); ?></strong> &mdash; <?php esc_html_e('complete static html of your entire site', 'wp-to-html'); ?></div></li>
                        <li><span class="eh-pro-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></span><div><strong><?php esc_html_e('AWS S3 Upload', 'wp-to-html'); ?></strong> &mdash; <?php esc_html_e('push exports directly to your S3 bucket', 'wp-to-html'); ?></div></li>
                        <li><span class="eh-pro-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span><div><strong><?php esc_html_e('FTP / SFTP Upload', 'wp-to-html'); ?></strong> &mdash; <?php esc_html_e('deploy to any server automatically', 'wp-to-html'); ?></div></li>
                        <li><span class="eh-pro-feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span><div><strong><?php esc_html_e('Grouped Assets', 'wp-to-html'); ?></strong> &mdash; <?php esc_html_e('organize exports into /images, /css, /js folders', 'wp-to-html'); ?></div></li>
                    </ul>
                </div>
                <div class="eh-pro-modal-foot">
                    <button type="button" class="eh-pro-modal-dismiss" id="eh-pro-modal-dismiss"><?php esc_html_e('Maybe later', 'wp-to-html'); ?></button>
                    <a href="https://myrecorp.com/export-wp-page-to-static-html-pro.html" target="_blank" rel="noopener noreferrer" class="eh-pro-modal-cta">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        <?php esc_html_e('Upgrade to Pro', 'wp-to-html'); ?>
                    </a>
                </div>
            </div>
        </div>

    <?php
    }

    public function system_status_page() {
        // Render a lightweight diagnostics UI (server-rendered), with buttons that call REST.
        $diag = new \WpToHtml\Diagnostic();
        $report = $diag->get_report(false);

        $gen = isset($report['generated_at']) ? (int) $report['generated_at'] : time();
        $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : ['can_run'=>0,'fails'=>0,'warns'=>0,'ok'=>0];
        $checks  = isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : [];

        $can_run = !empty($summary['can_run']);
        $badge = $can_run ? '✅ ' . __('Ready', 'wp-to-html') : '❌ ' . __('Needs fixes', 'wp-to-html');

        $fmt_time = function($ts) {
            // WP timezone
            return function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s', $ts);
        };

        ?>
        <div class="wrap">
            <h1 style="margin-bottom:8px;"><?php esc_html_e('WP to HTML System Status', 'wp-to-html'); ?></h1>
            <p style="margin-top:0;"><?php esc_html_e('Pre-flight checks to confirm your environment can run exports reliably.', 'wp-to-html'); ?></p>

            <div class="notice" style="padding:12px 14px; border-left:4px solid #2271b1; background:#fff;">
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <div style="font-size:16px;font-weight:700;"><?php echo esc_html(sprintf(__("Status: %s", "wp-to-html"), $badge)); ?></div>
                    <div style="color:#646970;"><?php esc_html_e('Last run:', 'wp-to-html'); ?> <strong><?php echo esc_html($fmt_time($gen)); ?></strong></div>
                    <div style="color:#646970;"><?php esc_html_e('OK:', 'wp-to-html'); ?> <strong><?php echo (int)($summary['ok'] ?? 0); ?></strong> · <?php esc_html_e('Warnings:', 'wp-to-html'); ?> <strong><?php echo (int)($summary['warns'] ?? 0); ?></strong> · <?php esc_html_e('Fails:', 'wp-to-html'); ?> <strong><?php echo (int)($summary['fails'] ?? 0); ?></strong></div>
                </div>
                <div style="margin-top:10px; display:flex; gap:10px;">
                    <button class="button button-primary" id="wp-to-html-run-diagnostics"><?php esc_html_e('Run checks now', 'wp-to-html'); ?></button>
                    <button class="button" id="wp-to-html-reset-diagnostics"><?php esc_html_e('Reset cache', 'wp-to-html'); ?></button>
                    <button class="button" id="wp-to-html-clear-temp"><?php esc_html_e('Clear temp/export files', 'wp-to-html'); ?></button>
                    <button class="button" id="wp-to-html-reset-queue"><?php esc_html_e('Reset queue/DB', 'wp-to-html'); ?></button>
                    <button class="button" id="wp-to-html-rerun-failed"><?php esc_html_e('Re-run failed URLs', 'wp-to-html'); ?></button>
                </div>
            </div>

            <h2 style="margin-top:18px;"><?php esc_html_e('Checks', 'wp-to-html'); ?></h2>
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e('Result', 'wp-to-html'); ?></th>
                        <th style="width:320px;"><?php esc_html_e('Check', 'wp-to-html'); ?></th>
                        <th><?php esc_html_e('Details', 'wp-to-html'); ?></th>
                        <th style="width:360px;"><?php esc_html_e('Fix tip', 'wp-to-html'); ?></th>
                    </tr>
                </thead>
                <tbody id="wp-to-html-diagnostics-body">
                    <?php foreach ($checks as $c):
                        $st = isset($c['status']) ? (string)$c['status'] : 'fail';
                        $icon = ($st === 'pass') ? '✅ Pass' : (($st === 'warn') ? '⚠️ Warn' : '❌ Fail');
                        $label = isset($c['label']) ? (string)$c['label'] : (string)($c['id'] ?? 'check');
                        $details = isset($c['details']) ? $c['details'] : [];
                        $tip = isset($c['tip']) ? (string)$c['tip'] : '';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($icon); ?></strong></td>
                            <td><?php echo esc_html($label); ?></td>
                            <td><pre style="white-space:pre-wrap;margin:0;max-height:140px;overflow:auto;"><?php echo esc_html(wp_json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></td>
                            <td><?php echo $tip !== '' ? esc_html($tip) : '<span style="color:#646970;">—</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="max-width:1100px;color:#646970;margin-top:12px;">
                <?php esc_html_e('Notes: Warnings don\'t always block exports, but may cause stalls on some hosts (especially loopback). Fails usually prevent reliable exports.', 'wp-to-html'); ?>
            </p>
        </div>

        <script>
        (function(){
            // IMPORTANT:
            // wpToHtmlData is localized onto the enqueued admin.js (loaded in the footer).
            // This page outputs HTML earlier, so we must wait until footer scripts load.
            const boot = () => {
                if (!window.wpToHtmlData) {
                    // Retry briefly; avoids "buttons not working" when this inline script runs
                    // before the localized object is available.
                    setTimeout(boot, 60);
                    return;
                }

                const bodyEl = document.getElementById('wp-to-html-diagnostics-body');
                const runBtn = document.getElementById('wp-to-html-run-diagnostics');
                const resetBtn = document.getElementById('wp-to-html-reset-diagnostics');
                const clearBtn = document.getElementById('wp-to-html-clear-temp');
                const resetQueueBtn = document.getElementById('wp-to-html-reset-queue');
                const rerunFailedBtn = document.getElementById('wp-to-html-rerun-failed');

            const req = async (url, method) => {
                const res = await fetch(url, {
                    method: method || 'GET',
                    headers: {
                        'X-WP-Nonce': wpToHtmlData.nonce,
                        'Content-Type': 'application/json'
                    },
                    body: (method && method !== 'GET') ? '{}' : undefined,
                    credentials: 'same-origin'
                });
                const json = await res.json().catch(()=>null);
                if (!res.ok) {
                    const msg = (json && (json.message || json.data)) ? JSON.stringify(json) : ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                return json;
            };

            const render = (report) => {
                if (!report || !report.checks) return;
                bodyEl.innerHTML = '';
                report.checks.forEach(c => {
                    const st = (c.status || 'fail');
                    const icon = (st === 'pass') ? '✅ Pass' : ((st === 'warn') ? '⚠️ Warn' : '❌ Fail');
                    const tr = document.createElement('tr');
                    const details = c.details ? JSON.stringify(c.details, null, 2) : '{}';
                    const tip = c.tip ? c.tip : '—';
                    tr.innerHTML = `
                        <td><strong>${icon}</strong></td>
                        <td>${(c.label || c.id || 'check')}</td>
                        <td><pre style="white-space:pre-wrap;margin:0;max-height:140px;overflow:auto;">${details.replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]))}</pre></td>
                        <td>${tip.replace(/[&<>]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[s]))}</td>
                    `;
                    bodyEl.appendChild(tr);
                });
            };

            if (runBtn) {
                runBtn.addEventListener('click', async () => {
                    runBtn.disabled = true;
                    runBtn.textContent = wpToHtmlData.i18n.running;
                    try {
                        const report = await req(wpToHtmlData.system_status_url + '?force=1', 'GET');
                        render(report);
                        // Refresh page badge/summary in the simplest way.
                        location.reload();
                    } catch (e) {
                        alert(wpToHtmlData.i18n.diagnostics_failed + ' ' + (e && e.message ? e.message : e));
                    } finally {
                        runBtn.disabled = false;
                        runBtn.textContent = wpToHtmlData.i18n.run_checks_now;
                    }
                });
            }
            if (resetBtn) {
                resetBtn.addEventListener('click', async () => {
                    resetBtn.disabled = true;
                    resetBtn.textContent = wpToHtmlData.i18n.resetting;
                    try {
                        await req(wpToHtmlData.reset_diagnostics_url, 'POST');
                        location.reload();
                    } catch (e) {
                        alert(wpToHtmlData.i18n.reset_failed + ' ' + (e && e.message ? e.message : e));
                    } finally {
                        resetBtn.disabled = false;
                        resetBtn.textContent = wpToHtmlData.i18n.reset_cache;
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', async () => {
                    if (!confirm(wpToHtmlData.i18n.confirm_clear_exports)) return;
                    clearBtn.disabled = true;
                    clearBtn.textContent = wpToHtmlData.i18n.clearing;
                    try {
                        await req(wpToHtmlData.clear_temp_url, 'POST');
                        alert(wpToHtmlData.i18n.temp_files_cleared);
                    } catch (e) {
                        alert(wpToHtmlData.i18n.clear_temp_failed + ' ' + (e && e.message ? e.message : e));
                    } finally {
                        clearBtn.disabled = false;
                        clearBtn.textContent = wpToHtmlData.i18n.clear_temp_export;
                    }
                });
            }

            if (resetQueueBtn) {
                resetQueueBtn.addEventListener('click', async () => {
                    if (!confirm(wpToHtmlData.i18n.confirm_reset_queue)) return;
                    resetQueueBtn.disabled = true;
                    resetQueueBtn.textContent = wpToHtmlData.i18n.resetting;
                    try {
                        await req(wpToHtmlData.queue_reset_url, 'POST');
                        alert(wpToHtmlData.i18n.queue_reset_done);
                        location.reload();
                    } catch (e) {
                        alert(wpToHtmlData.i18n.queue_reset_failed + ' ' + (e && e.message ? e.message : e));
                    } finally {
                        resetQueueBtn.disabled = false;
                        resetQueueBtn.textContent = wpToHtmlData.i18n.reset_queue_db;
                    }
                });
            }

            if (rerunFailedBtn) {
                rerunFailedBtn.addEventListener('click', async () => {
                    try {
                        const list = await req(wpToHtmlData.failed_urls_url + '?limit=1', 'GET');
                        const cnt = (list && typeof list.count === 'number') ? list.count : 0;
                        if (cnt <= 0) {
                            alert(wpToHtmlData.i18n.no_failed_urls);
                            return;
                        }
                        if (!confirm(wpToHtmlData.i18n.confirm_rerun_count.replace('%d', cnt))) return;
                    } catch (e) {
                        // If list fetch fails, still allow rerun.
                        if (!confirm(wpToHtmlData.i18n.confirm_rerun)) return;
                    }

                    rerunFailedBtn.disabled = true;
                    rerunFailedBtn.textContent = wpToHtmlData.i18n.requeuing;
                    try {
                        await req(wpToHtmlData.rerun_failed_url, 'POST');
                        alert(wpToHtmlData.i18n.failed_requeued);
                    } catch (e) {
                        alert(wpToHtmlData.i18n.rerun_failed + ' ' + (e && e.message ? e.message : e));
                    } finally {
                        rerunFailedBtn.disabled = false;
                        rerunFailedBtn.textContent = wpToHtmlData.i18n.rerun_failed_urls;
                    }
                });
            }
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
        </script>
        <?php
    }

    /**
     * "What's New" page shown after plugin update.
     */
    public function whats_new_page() {
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
                        <?php esc_html_e('A major update to the export engine with improved reliability, smarter retries, and a refreshed interface.', 'wp-to-html'); ?>
                    </p>

                    <div class="wth-version">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/><line x1="17.5" y1="15" x2="9" y2="15"/></svg>
                        <?php esc_html_e('Version', 'wp-to-html'); ?> <strong>6.0.0</strong>
                    </div>
                </div>

                <!-- Changelog Cards -->
                <div class="wth-cards">

                    <!-- Core -->
                    <div class="wth-card">
                        <div class="wth-card-icon core">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label core"><?php esc_html_e('Core', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Refactored the core export engine for improved stability and performance.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: Watchdog -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Watchdog now automatically detects and repairs stalled export processes.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: Failed URL tracking -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Enhanced failed URL tracking with per-URL retry counts and detailed error reporting.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: Re-run failed -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Re-run only failed URLs without restarting the entire export process.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: Exponential backoff -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Implemented exponential backoff for asset retries to reduce server load.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: Asset collection mode -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Asset collection mode (Strict / Hybrid / Full) is now saved and respected across cron runs.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Fixed: Export context -->
                    <div class="wth-card">
                        <div class="wth-card-icon fixed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label fixed"><?php esc_html_e('Fixed', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('Export context is now correctly propagated to background workers during server cron execution.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Added: Options persisted -->
                    <div class="wth-card">
                        <div class="wth-card-icon added">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label added"><?php esc_html_e('Added', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('single_root_index and root_parent_html options are now persisted within the export context.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Improved: UX -->
                    <div class="wth-card">
                        <div class="wth-card-icon improved">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label improved"><?php esc_html_e('Improved', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('More user-friendly interface and overall UX enhancements.', 'wp-to-html'); ?></div>
                        </div>
                    </div>

                    <!-- Removed: PDF -->
                    <div class="wth-card">
                        <div class="wth-card-icon removed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </div>
                        <div class="wth-card-body">
                            <div class="wth-card-label removed"><?php esc_html_e('Removed', 'wp-to-html'); ?></div>
                            <div class="wth-card-text"><?php esc_html_e('PDF Exporting option removed temporarily.', 'wp-to-html'); ?></div>
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
