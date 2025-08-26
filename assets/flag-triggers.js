jQuery(document).ready(function($) {
    // Use event delegation to handle buttons that might be loaded via AJAX
    $(document.body).on('click', '.psych-flag-button', function(e) {
        e.preventDefault();

        const button = $(this);
        const flag = button.data('flag');

        if (!flag) {
            console.error('Psych Button Error: No flag specified.');
            return;
        }

        button.prop('disabled', true).text('در حال پردازش...');

        $.ajax({
            url: psych_flag_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'psych_set_flag_from_button',
                flag: flag,
                nonce: psych_flag_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Provide visual feedback to the user
                    button.text('انجام شد!');
                    button.css('background-color', '#28a745');

                    // Optionally, trigger a custom event that other scripts can listen to
                    $(document.body).trigger('psych_flag_set', [flag]);

                    // You might want to reload the page if the flag reveals new content
                    // setTimeout(() => location.reload(), 1000);

                } else {
                    button.prop('disabled', false).text('خطا! دوباره تلاش کنید.');
                    button.css('background-color', '#dc3545');
                    console.error('Psych Button Error:', response.data.message);
                }
            },
            error: function() {
                button.prop('disabled', false).text('خطای ارتباط.');
                button.css('background-color', '#dc3545');
            }
        });
    });
});
