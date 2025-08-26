jQuery(document).ready(function($) {
    var notificationShown = false;

    // Check for pending notifications
    function checkNotifications() {
        if (notificationShown) return;

        $.post(psych_gamification.ajax_url, {
            action: "psych_get_pending_notifications",
            nonce: psych_gamification.nonce
        })
        .done(function(response) {
            if (response.success && response.data.notifications.length > 0) {
                showNotification(response.data.notifications[0]);
            }
        });
    }

    // Show notification popup
    function showNotification(notification) {
        var content = "<h4>" + notification.title + "</h4><p>" + notification.message + "</p>";
        $("#psych-notification-content").html(content);
        $("#psych-notification-container").fadeIn();
        notificationShown = true;

        // Auto-hide after 5 seconds
        setTimeout(function() {
            hideNotification(notification.id);
        }, 5000);
    }

    // Hide notification
    function hideNotification(notificationId) {
        $("#psych-notification-container").fadeOut(function() {
            // Clear the notification from database
            if (notificationId) {
                $.post(psych_gamification.ajax_url, {
                    action: "psych_clear_notification",
                    nonce: psych_gamification.nonce,
                    notification_id: notificationId
                });
            }
            notificationShown = false;
        });
    }

    // Close notification manually
    $("#psych-notification-close").on("click", function() {
        hideNotification();
    });

    // Check for notifications every 30 seconds
    setInterval(checkNotifications, 30000);

    // Initial check
    setTimeout(checkNotifications, 2000);

    // Handle mission completion integration
    $(document).on("psych_mission_completed", function(e, missionId, buttonElement) {
        // This integrates with Interactive Content Module
        setTimeout(checkNotifications, 1000);
    });
});
