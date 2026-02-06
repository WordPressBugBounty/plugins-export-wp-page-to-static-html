<div class="col-3 p-10 dev_section">

    <div class="created_by py-2 mt-1 border-bottom">
        <?php esc_html_e( 'Created by', 'export-wp-page-to-static-html' ); ?>
        <a href="<?php echo esc_url( 'https://myrecorp.com' ); ?>">
            <img src="<?php echo esc_url( EWPPTSH_PLUGIN_DIR_URL . '/admin/images/recorp-logo.png' ); ?>"
                 alt="<?php esc_attr_e( 'ReCorp', 'export-wp-page-to-static-html' ); ?>"
                 width="100">
        </a>
    </div>

    <div class="documentation my-2">
        <a href="<?php echo esc_url( 'https://myrecorp.com/documentation/export-wp-page-to-html' ); ?>">
            <span><?php esc_html_e( 'Documentation', 'export-wp-page-to-static-html' ); ?></span>
        </a>
    </div>

    <div class="documentation my-2">
        <a href="<?php echo esc_url( 'https://myrecorp.com/support' ); ?>">
            <span><?php esc_html_e( 'Support', 'export-wp-page-to-static-html' ); ?></span>
        </a>
    </div>

    <div class="pro-content">
        <a href="<?php echo esc_url( 'https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=right_sidebar' ); ?>"
           class="go-pro-btn" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e( '🚀 Go to Pro', 'export-wp-page-to-static-html' ); ?>
        </a>
        <p class="pro-note">
            <?php esc_html_e( 'Unlock advanced features with Pro', 'export-wp-page-to-static-html' ); ?>
        </p>
    </div>

    <?php
    // Show version notice if needed; escape as HTML (or allow limited HTML if $versionIssue contains markup).
    if ( ! isset( $_GET['welcome'] ) && ! ( PHP_VERSION_ID >= 70205 ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        echo wp_kses_post( $versionIssue );
    }
    ?>

    <div class="right_side_notice mt-4">
        <div style="background: linear-gradient(135deg, #f0f4ff, #ffffff); padding: 25px; border-radius: 20px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1); font-family: 'Segoe UI', sans-serif; color: #333; max-width: 700px; margin: 30px auto; border: 1px solid #e0e6f0;">
            <h4 style="font-size: 24px; margin-bottom: 20px; color: #2c3e50; border-left: 5px solid #4a90e2; padding-left: 10px;">
                <?php esc_html_e( '🚀 Features of Pro Version', 'export-wp-page-to-static-html' ); ?>
            </h4>
            <ul style="list-style: none; padding-left: 0; margin: 0;">
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Export full site as HTML with related page linking', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Make full offline site', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Export any website (custom URLs) as HTML', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Export unlimited PDF files', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Export posts', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Export multiple posts or pages at the same time', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Upload exported files to FTP server', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Notification system when export completes', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 12px; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✔️</span>
                    <?php esc_html_e( 'Background task system (you don’t have to stay on settings page)', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 0; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✨</span>
                    <?php esc_html_e( 'Auto export on publish', 'export-wp-page-to-static-html' ); ?>
                </li>
                <li style="margin-bottom: 0; display: flex; align-items: start;">
                    <span style="color: #4a90e2; font-size: 18px; margin-right: 10px;">✨</span>
                    <?php esc_html_e( '...and more!', 'export-wp-page-to-static-html' ); ?>
                </li>
            </ul>
        </div>

        <div class="sidebar_notice_section">
            <div class="right_notice_title">
                <a href="<?php echo esc_url( 'https://wordpress.org/support/plugin/export-wp-page-to-static-html/reviews/?rate=5#new-post' ); ?>"
                   target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Support us with a 5-star rating »', 'export-wp-page-to-static-html' ); ?>
                </a>
            </div>
            <div class="right_notice_details"></div>
        </div>

        <div class="sidebar_notice_section">
            <div class="right_notice_title">
                <h3 style="font-weight: bold;margin-top: 20px">
                    <?php esc_html_e( "Client's Feedback", 'export-wp-page-to-static-html' ); ?>
                </h3>
            </div>
            <div class="right_notice_details">
                <figure style="align-items: flex-start;gap: 16px;padding: 20px;max-width: 600px;margin: 40px auto;border-radius: 16px;background-color: #1e293b;color: #f8fafc;font-family: 'Inter', sans-serif">
                    <a href="<?php echo esc_url( 'https://wordpress.org/support/topic/unique-in-its-kind-great/' ); ?>" target="_blank" rel="noopener noreferrer" style="flex-shrink: 0">
                        <img src="<?php echo esc_url( 'https://secure.gravatar.com/avatar/e4356bc23ca067ff6e8b56ffd279d91d?s=100&d=retro&r=g' ); ?>"
                             alt="<?php esc_attr_e( 'User Avatar', 'export-wp-page-to-static-html' ); ?>"
                             style="border-radius: 50%;width: 64px;height: 64px;border: 2px solid #334155">
                    </a>
                    <figcaption style="flex: 1">
                        <h3 style="margin: 0 0 6px;font-size: 18px">
                            <a href="<?php echo esc_url( 'https://wordpress.org/support/topic/unique-in-its-kind-great/' ); ?>" target="_blank" rel="noopener noreferrer" style="color: #38bdf8;text-decoration: none">
                                <?php echo esc_html( 'ikihinojosa' ); ?>
                            </a>
                        </h3>
                        <p style="margin: 0 0 8px;font-size: 15px;font-weight: 600;color: #facc15">
                            <?php esc_html_e( '“Unique in its kind!… great!”', 'export-wp-page-to-static-html' ); ?>
                        </p>
                        <p style="margin: 0;font-size: 14px;line-height: 1.6">
                            <?php esc_html_e( 'Is the only plugin like this that will export a single page, and not the whole website…', 'export-wp-page-to-static-html' ); ?><br><br>
                            <?php esc_html_e( 'I was really looking for something like that... and it works like a charm!', 'export-wp-page-to-static-html' ); ?><br><br>
                            <?php esc_html_e( 'It brings all the media files, including photos and videos (of course, if they are in your media gallery)', 'export-wp-page-to-static-html' ); ?><br><br>
                            <?php esc_html_e( 'Thanks guys for creating it!!', 'export-wp-page-to-static-html' ); ?>
                        </p>
                    </figcaption>
                </figure>
            </div>
        </div>

        <div class="sidebar_notice_section">
            <div class="right_notice_title">
                <?php esc_html_e( 'More plugins you may like!', 'export-wp-page-to-static-html' ); ?>
            </div>
            <div class="right_notice_details">
                <a href="<?php echo esc_url( 'https://wordpress.org/plugins/advanced-menu-icons/' ); ?>">
                    <?php esc_html_e( 'Advanced Menu Icons', 'export-wp-page-to-static-html' ); ?>
                </a>
                <br>
                <a href="<?php echo esc_url( 'https://wordpress.org/plugins/ai-content-writing-assistant' ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'AI Content Writing Assistant (Content Writer, ChatGPT, Image Generator) All in One', 'export-wp-page-to-static-html' ); ?>
                </a>.
                <br><br>
                <a href="<?php echo esc_url( 'https://wordpress.org/plugins/different-menus-in-different-pages/?r=export-html' ); ?>">
                    <?php esc_html_e( 'Different Menu in Different Pages', 'export-wp-page-to-static-html' ); ?>
                </a><br>
                <a href="<?php echo esc_url( 'https://myrecorp.com/product/menu-import-and-export-pro/?r=export-html' ); ?>">
                    <?php esc_html_e( 'Menu Import & Export Pro', 'export-wp-page-to-static-html' ); ?>
                </a><br>
                <a href="<?php echo esc_url( 'https://myrecorp.com/product/mailchimp-for-divi-contact-form/?r=export-html' ); ?>">
                    <?php esc_html_e( 'Divi Contact Form MailChimp Extension', 'export-wp-page-to-static-html' ); ?>
                </a><br>
                <a href="<?php echo esc_url( 'https://wordpress.org/plugins/pipe-recaptcha/' ); ?>">
                    <?php esc_html_e( 'Pipe ReCaptcha', 'export-wp-page-to-static-html' ); ?>
                </a>
            </div>
        </div>

        <style>.right_notice_title{font-size: 17px;font-weight: bold;margin-top: 10px;}</style>
    </div>

    <div class="right_side_notice mt-4">
        <?php
        do_action( 'wpptsh_right_side_notice' );
        ?>
    </div>
</div>
