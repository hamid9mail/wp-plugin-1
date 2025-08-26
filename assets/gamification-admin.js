jQuery(document).ready(function($) {
    // Manual award form handling
    $("#manual-award-form").on("submit", function(e) {
        e.preventDefault();

        var formData = {
            action: "psych_manual_award",
            nonce: psych_gamification_admin.nonce,
            user_id: $("#award_user_id").val(),
            award_type: $("#award_type").val(),
            award_value: $("#award_value").val(),
            reason: $("#award_reason").val()
        };

        $.post(psych_gamification_admin.ajax_url, formData)
        .done(function(response) {
            if (response.success) {
                alert("✅ " + response.data.message);
                $("#manual-award-form")[0].reset();
            } else {
                alert("❌ " + response.data.message);
            }
        })
        .fail(function() {
            alert("❌ خطا در ارتباط با سرور");
        });
    });

    // Live search for users
    $("#award_user_search").on("input", function() {
        var query = $(this).val();
        if (query.length < 2) return;

        // Here you could implement user search functionality
        // For now, this is a placeholder
    });

    $("#add-badge").click(function() {
        var slug = prompt("نامک نشان (انگلیسی، بدون فاصله):");
        if (!slug) return;

        slug = slug.toLowerCase().replace(/[^a-z0-9_]/g, '_');

        var html = '<div class="badge-row">' +
            '<input type="text" value="' + slug + '" name="badge_slugs[]" readonly />' +
            '<input type="text" name="badges[' + slug + '][name]" placeholder="نام نشان" />' +
            '<textarea name="badges[' + slug + '][description]" placeholder="توضیحات"></textarea>' +
            '<input type="text" name="badges[' + slug + '][icon]" placeholder="کلاس آیکون" value="fa-trophy" />' +
            '<input type="color" name="badges[' + slug + '][color]" value="#FFD700" />' +
            '<button type="button" class="button remove-badge">حذف</button>' +
            '</div>';

        $("#badges-container").append(html);
    });

    $(document).on("click", ".remove-badge", function() {
        if (confirm("آیا مطمئن هستید؟")) {
            $(this).closest(".badge-row").remove();
        }
    });
});
