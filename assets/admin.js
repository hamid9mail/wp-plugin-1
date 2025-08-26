jQuery(document).ready(function($) {
    function psychClearCache() {
        if (confirm('آیا مطمئن هستید که می‌خواهید کش را پاک کنید؟')) {
            jQuery.post(ajaxurl, {
                action: 'psych_clear_cache',
                nonce: psych_system_admin.nonces.clear_cache
            }, function(response) {
                if (response.success) {
                    alert('کش با موفقیت پاک شد.');
                    location.reload();
                } else {
                    alert('خطا: ' + response.data.message);
                }
            });
        }
    }

    function psychTestSystem() {
        jQuery.post(ajaxurl, {
            action: 'psych_system_status',
            nonce: psych_system_admin.nonces.system_status
        }, function(response) {
            if (response.success) {
                alert('سیستم عملکرد مناسبی دارد!');
            } else {
                alert('خطا در سیستم: ' + response.data.message);
            }
        });
    }

    // Making functions global to be accessible by inline onclick attributes
    window.psychClearCache = psychClearCache;
    window.psychTestSystem = psychTestSystem;
});
