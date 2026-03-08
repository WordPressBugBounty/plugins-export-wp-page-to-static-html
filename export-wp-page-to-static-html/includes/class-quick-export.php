<?php
namespace WpToHtml;

/**
 * Quick Export — adds "Export to Static HTML" buttons throughout WP admin:
 *  1. Post/Page/CPT list table  → row action link + bulk action
 *  2. Post/Page/CPT edit screen → publish metabox button
 *  3. Admin bar                 → "Export this page" node (singular edit screens only)
 */
class Quick_Export {

    /** Post types we attach to (all public, show_ui types). */
    private array $post_types = [];

    public function __construct() {
        add_action('init', [$this, 'collect_post_types'], 20);

        // ── List-table hooks ──────────────────────────────────────
        add_filter('post_row_actions',  [$this, 'row_action'], 10, 2);
        add_filter('page_row_actions',  [$this, 'row_action'], 10, 2);
        // CPTs use the generic filter: {post_type}_row_actions → we attach after init
        add_action('init', [$this, 'attach_cpt_row_actions'], 25);

        // Bulk action dropdown
        add_action('bulk_action_forms', [$this, 'maybe_add_bulk_action_js']);   // fallback
        add_action('admin_footer',      [$this, 'inject_bulk_action_option']);   // reliable

        // Handle the bulk action
        add_action('admin_action_wp_to_html_bulk_export', [$this, 'handle_bulk_action']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action_redirect'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action_redirect'], 10, 3);

        // Bulk action notice
        add_action('admin_notices', [$this, 'bulk_action_notice']);

        // ── Edit-screen metabox ───────────────────────────────────
        add_action('add_meta_boxes', [$this, 'add_metabox']);

        // ── Admin bar ─────────────────────────────────────────────
        add_action('admin_bar_menu', [$this, 'admin_bar_node'], 100);

        // ── Assets ───────────────────────────────────────────────
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // ── REST shortcut (AJAX proxy) ────────────────────────────
        add_action('wp_ajax_wp_to_html_quick_export', [$this, 'ajax_quick_export']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    public function collect_post_types(): void {
        $objs = get_post_types(['public' => true, 'show_ui' => true], 'objects');
        foreach ($objs as $name => $obj) {
            if ($name === 'attachment') continue;
            $this->post_types[] = $name;
        }
    }

    public function attach_cpt_row_actions(): void {
        foreach ($this->post_types as $pt) {
            if ($pt === 'post' || $pt === 'page') continue; // already covered
            add_filter("{$pt}_row_actions", [$this, 'row_action'], 10, 2);
        }
    }

    /** Build the URL that triggers a quick export for a single post. */
    private function export_url(int $post_id): string {
        return admin_url(
            'admin-ajax.php?action=wp_to_html_quick_export'
            . '&post_id=' . $post_id
            . '&_wpnonce=' . wp_create_nonce('wp_to_html_quick_export_' . $post_id)
        );
    }

    /** Build the plugin main-page URL pre-selecting a post in Custom scope. */
    private function plugin_page_url(int $post_id, string $post_type): string {
        return admin_url(
            'admin.php?page=wp-to-html'
            . '&quick_export_id='   . $post_id
            . '&quick_export_type=' . urlencode($post_type)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Row action (list table)
    // ─────────────────────────────────────────────────────────────────────

    public function row_action(array $actions, \WP_Post $post): array {
        if (!current_user_can('manage_options')) return $actions;
        if (!in_array($post->post_type, $this->post_types, true)) return $actions;

        $url = $this->plugin_page_url($post->ID, $post->post_type);

        $actions['wp_to_html_export'] = sprintf(
            '<a href="%s" class="wp-to-html-quick-row-btn" data-post-id="%d" data-post-type="%s" title="%s">%s</a>',
            esc_url($url),
            $post->ID,
            esc_attr($post->post_type),
            esc_attr__('Export this item to Static HTML', 'wp-to-html'),
            '<span style="display:inline-flex;align-items:center;gap:4px;">'
                . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
                . esc_html__('Export HTML', 'wp-to-html')
            . '</span>'
        );

        return $actions;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Bulk action — inject option via admin_footer JS
    // ─────────────────────────────────────────────────────────────────────

    public function inject_bulk_action_option(): void {
        $screen = get_current_screen();
        if (!$screen) return;
        // Only on list-table screens for our post types
        if ($screen->base !== 'edit') return;
        if (!in_array($screen->post_type, $this->post_types, true)) return;
        if (!current_user_can('manage_options')) return;

        $label = esc_js(__('Export to Static HTML', 'wp-to-html'));
        $nonce = wp_create_nonce('bulk-wp_to_html-export');
        ?>
        <script>
        (function($){
            var opt = $('<option>').val('wp_to_html_bulk_export').text('<?php echo $label; ?>');
            $('select[name="action"], select[name="action2"]').append(opt.clone());

            // Intercept form submit for our action
            $('#posts-filter').on('submit', function(e){
                var action = $('select[name="action"]').val();
                var action2 = $('select[name="action2"]').val();
                if (action !== 'wp_to_html_bulk_export' && action2 !== 'wp_to_html_bulk_export') return;
                e.preventDefault();
                var ids = [];
                $('input[name="post[]"]:checked, input[name="post[]"]').each(function(){
                    if($(this).is(':checked')) ids.push($(this).val());
                });
                if (!ids.length) { alert('<?php echo esc_js(__('Please select at least one item.', 'wp-to-html')); ?>'); return; }
                if (ids.length > 5 && !<?php echo (function_exists('wp_to_html_is_pro_active') && wp_to_html_is_pro_active()) ? 'false' : 'true'; ?>) {
                    if (!confirm('<?php echo esc_js(__('Free plan allows up to 5 items. Only the first 5 will be exported. Continue?', 'wp-to-html')); ?>')) return;
                    ids = ids.slice(0, 5);
                }
                // Build the plugin URL with selected IDs
                var base = '<?php echo esc_js(admin_url('admin.php?page=wp-to-html')); ?>';
                var params = ids.map(function(id){ return 'bulk_export_ids[]=' + encodeURIComponent(id); }).join('&');
                var pt = '<?php echo esc_js($screen->post_type ?? 'post'); ?>';
                window.location.href = base + '&' + params + '&quick_export_type=' + encodeURIComponent(pt) + '&_wpnonce=<?php echo $nonce; ?>';
            });
        })(jQuery);
        </script>
        <?php
    }

    public function handle_bulk_action_redirect(string $redirect_to, string $action, array $post_ids): string {
        return $redirect_to; // handled client-side
    }

    public function handle_bulk_action(): void {
        // Fallback server-side handler (not normally triggered, handled by JS)
        wp_safe_redirect(admin_url('admin.php?page=wp-to-html'));
        exit;
    }

    public function bulk_action_notice(): void {
        // Nothing needed; we redirect to the plugin page
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Edit-screen metabox
    // ─────────────────────────────────────────────────────────────────────

    public function add_metabox(): void {
        if (!current_user_can('manage_options')) return;

        foreach ($this->post_types as $pt) {
            add_meta_box(
                'wp_to_html_quick_export',
                '<span style="display:inline-flex;align-items:center;gap:6px;">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
                    . esc_html__('Export to Static HTML', 'wp-to-html')
                    . '</span>',
                [$this, 'render_metabox'],
                $pt,
                'side',
                'high'
            );
        }
    }

    public function render_metabox(\WP_Post $post): void {
        $url  = $this->plugin_page_url($post->ID, $post->post_type);
        $ajax = wp_nonce_url(
            admin_url('admin-ajax.php?action=wp_to_html_quick_export&post_id=' . $post->ID),
            'wp_to_html_quick_export_' . $post->ID
        );
        ?>
        <div id="wp-to-html-metabox-<?php echo $post->ID; ?>" style="padding:2px 0 6px;">
            <p style="margin:0 0 10px;font-size:12px;color:#6b7280;line-height:1.5;">
                <?php esc_html_e('Export this item as a standalone static HTML file.', 'wp-to-html'); ?>
            </p>
            <button
                type="button"
                class="button button-primary wp-to-html-metabox-export-btn"
                style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;background:#4f6ef7;border-color:#3b5be0;font-weight:600;"
                data-post-id="<?php echo esc_attr($post->ID); ?>"
                data-post-type="<?php echo esc_attr($post->post_type); ?>"
                data-plugin-url="<?php echo esc_url($url); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('wp_to_html_quick_export_' . $post->ID)); ?>"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e('Export to Static HTML', 'wp-to-html'); ?>
            </button>
            <div id="wp-to-html-metabox-status-<?php echo $post->ID; ?>" style="margin-top:8px;display:none;font-size:12px;"></div>
            <p style="margin:8px 0 0;font-size:11px;text-align:center;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-to-html')); ?>" style="color:#6b7280;">
                    <?php esc_html_e('Open full Export Manager →', 'wp-to-html'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Admin bar node
    // ─────────────────────────────────────────────────────────────────────

    public function admin_bar_node(\WP_Admin_Bar $bar): void {
        if (!current_user_can('manage_options')) return;
        if (!is_admin()) return;

        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post') return;

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$post_id) return;

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, $this->post_types, true)) return;

        $url = $this->plugin_page_url($post_id, $post->post_type);

        $bar->add_node([
            'id'    => 'wp-to-html-quick-export',
            'title' => '<span class="ab-icon dashicons dashicons-media-code" style="top:2px;margin-right:4px;color:#4f6ef7;"></span>'
                     . esc_html__('Export to HTML', 'wp-to-html'),
            'href'  => esc_url($url),
            'meta'  => [
                'title' => __('Export this post/page to Static HTML', 'wp-to-html'),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Assets
    // ─────────────────────────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        // Only on edit screens and list tables for our post types
        $screen = get_current_screen();
        if (!$screen) return;
        if (!in_array($screen->base, ['post', 'edit'], true)) return;
        if (!in_array($screen->post_type, $this->post_types, true)) return;
        if (!current_user_can('manage_options')) return;

        // Inline CSS
        wp_add_inline_style('wp-admin', $this->inline_css());

        // Inline JS (metabox handler — opens plugin page with post pre-selected)
        wp_add_inline_script('jquery', $this->inline_js());
    }

    private function inline_css(): string {
        return '
        .wp-to-html-quick-row-btn {
            color: #4f6ef7 !important;
            font-weight: 600;
        }
        .wp-to-html-quick-row-btn:hover {
            color: #3b5be0 !important;
        }
        .wp-to-html-metabox-export-btn:hover {
            background: #3b5be0 !important;
            border-color: #3055cc !important;
        }
        .wp-to-html-metabox-export-btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }
        #wp-to-html-export-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99999;
            background: #111827;
            color: #fff;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 4px 24px rgba(0,0,0,.18);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: wpToHtmlToastIn .2s ease;
            max-width: 340px;
        }
        #wp-to-html-export-toast.is-success { border-left: 4px solid #34d399; }
        #wp-to-html-export-toast.is-error   { border-left: 4px solid #f87171; }
        #wp-to-html-export-toast.is-info    { border-left: 4px solid #4f6ef7; }
        @keyframes wpToHtmlToastIn {
            from { opacity:0; transform: translateY(10px); }
            to   { opacity:1; transform: translateY(0); }
        }
        ';
    }

    private function inline_js(): string {
        $plugin_url  = esc_js(admin_url('admin.php?page=wp-to-html'));
        $export_url  = esc_js(admin_url('admin-ajax.php'));
        $nonce_action = 'wp_to_html_quick_export_';

        return <<<JS
        (function($){
            function wpToHtmlToast(msg, type) {
                $('#wp-to-html-export-toast').remove();
                var t = $('<div id="wp-to-html-export-toast">').addClass('is-' + (type||'info')).text(msg);
                $('body').append(t);
                setTimeout(function(){ t.fadeOut(300, function(){ t.remove(); }); }, 4000);
            }

            $(document).on('click', '.wp-to-html-metabox-export-btn', function(e){
                e.preventDefault();
                var btn      = $(this);
                var postId   = btn.data('post-id');
                var postType = btn.data('post-type');
                var pluginUrl = btn.data('plugin-url');

                // Navigate to plugin page with post pre-selected
                wpToHtmlToast('Opening Export Manager…', 'info');
                setTimeout(function(){ window.location.href = pluginUrl; }, 400);
            });

            // Pre-select post on plugin page if quick_export_id param present
            if (typeof wpToHtmlData !== 'undefined') {
                var params = new URLSearchParams(window.location.search);
                var qId   = params.get('quick_export_id');
                var qType = params.get('quick_export_type');
                var bulkIds = params.getAll('bulk_export_ids[]');

                if ((qId || bulkIds.length) && typeof ehState !== 'undefined') {
                    $(document).ready(function(){
                        // Wait briefly for plugin UI to initialise
                        setTimeout(function(){
                            if (typeof setScope === 'function') setScope('custom');

                            function selectItems(ids, type) {
                                ids.forEach(function(id){
                                    var key = (type||'post') + ':' + id;
                                    if (typeof ehState !== 'undefined') {
                                        ehState.selected.set(key, { id: parseInt(id), type: type||'post', title: 'Item #'+id });
                                    }
                                    // Also tick DOM checkbox if visible
                                    var cb = $('#eh-content-list .wp-to-html-select-item[data-id="'+id+'"]');
                                    if (cb.length) cb.prop('checked', true).trigger('change');
                                });
                                if (typeof updateSelectedCount === 'function') updateSelectedCount();
                                if (typeof updateScopeUI === 'function') updateScopeUI();
                            }

                            if (qId) selectItems([qId], qType);
                            if (bulkIds.length) selectItems(bulkIds, qType);

                        }, 800);
                    });
                }
            }
        })(jQuery);
        JS;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. AJAX handler — fire export via REST API and redirect
    // ─────────────────────────────────────────────────────────────────────

    public function ajax_quick_export(): void {
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorised.', 'wp-to-html'), 403);
        }
        check_ajax_referer('wp_to_html_quick_export_' . $post_id, '_wpnonce');

        $post = get_post($post_id);
        if (!$post) wp_die(esc_html__('Post not found.', 'wp-to-html'), 404);

        // Redirect to the plugin page with the post pre-selected
        wp_safe_redirect(esc_url_raw($this->plugin_page_url($post_id, $post->post_type)));
        exit;
    }
}
