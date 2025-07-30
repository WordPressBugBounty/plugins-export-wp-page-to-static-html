jQuery(document).ready(function ($) {

    $(document).on('click', '#deactivate-export-wp-page-to-static-html', function (e) {
        e.preventDefault();

        var deactivateLink = $(this).attr('href');
        $('#wpptsh-feedback-modal').fadeIn();
        $('#wpptsh-backdrop').fadeIn();
        

        $('#wpptsh-cancel').on('click', function () {
            $('#wpptsh-feedback-modal').fadeOut();
            $('#wpptsh-backdrop').fadeOut();
        });

        $('#wpptsh-submit').on('click', function () {
            var reason = "";
            reason = $('input[name="deactivate_reason"]:checked').val();
            const message = $('#wpptsh-feedback-text').val();

            $.post(wpptshData.ajaxUrl, {
                action: 'wpptsh_save_deactivation_feedback',
                reason_key: reason,
                feedback: message
            }, function () {
                window.location.href = deactivateLink;
            });
        });
    });

    $(document).on('click', '.static_html_settings .go_pro a', function (e) {
        e.preventDefault();
        var goProLink = $(this).attr('href');
        $.post(wpptshData.ajaxUrl, {
            action: 'wpptsh_click_go_pro',
            button: 'go_pro'
        }, function () {
            window.location.href = goProLink;
        });
    });
    $(document).on('click', '.export_html.main_settings_page .go_pro2 #purchase', function (e) {
        e.preventDefault();
        $.post(wpptshData.ajaxUrl, {
            action: 'wpptsh_click_go_pro',
            button: 'go_pro2'
        });
    });
});
