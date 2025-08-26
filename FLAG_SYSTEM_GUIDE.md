# راهنمای جامع و پیشرفته سیستم فلگ (Flag System)

## ۱. مقدمه: فلگ، آداپتور جهانی شما
سیستم فلگ (`mission_type="flag"`) قدرتمندترین و انعطاف‌پذیرترین نوع ماموریت در این افزونه است. این سیستم به شما اجازه می‌دهد **هر رویداد یا فعالیتی** را، چه در داخل وردپرس و چه از سرویس‌های خارجی، به یک ماموریت قابل تکمیل در مسیر رشد تبدیل کنید.

فکر کنید فلگ یک کلید برق است. ماموریت منتظر می‌ماند تا کسی (یک قطعه کد، یک افزونه دیگر، یا یک سرویس خارجی) این کلید را بزند. به محض زده شدن کلید، ماموریت تکمیل می‌شود.

**تابع کلیدی:** `psych_complete_mission_by_flag(string $flag_name, int $user_id)`

این تابع PHP قلب سیستم فلگ است. شما باید این تابع را در جایی که رویداد مورد نظر شما اتفاق می‌افتد، فراخوانی کنید.

---

## ۲. ترکیب فلگ با بازیگران (Actors)
نکته بسیار مهمی که این سیستم را بی‌نهایت قدرتمند می‌کند، امکان ترکیب `mission_type="flag"` با ویژگی `actors` است.

-   **`actors="self"` (پیش‌فرض):** دانشجو خودش باید فعالیتی را انجام دهد که فلگ را فعال کند.
-   **`actors="coach"`:** مربی یک فعالیت را برای دانشجو تایید (و فلگ را فعال) می‌کند. این برای ماموریت‌های آفلاین عالی است.
-   **`actors="guest_link"`:** یک کاربر مهمان (بدون نیاز به لاگین) می‌تواند فلگی را برای دانشجو فعال کند. (این سناریو کمتر رایج است اما برای موارد خاصی ممکن است).

---

## ۳. کتابخانه سناریوهای آماده (قابل توسعه)
در این بخش، مجموعه‌ای از سناریوهای کاربردی و خلاقانه برای استفاده از سیستم فلگ ارائه شده است. این بخش به گونه‌ای طراحی شده که شما به راحتی بتوانید سناریوهای خود را در آینده به آن اضافه کنید.

---

### **سناریو ۱: ثبت مود روزانه (Mood Tracker)**

-   **هدف:** افزایش تعامل روزانه کاربر و جمع‌آوری داده‌های احساسی برای تحلیل‌های بعدی.
-   **بازیگران ممکن:** `self`
-   **نمونه شورت‌کد:**
    ```html
    [station station_node_id="daily_mood_tracker" title="ثبت حال و هوای امروز" mission_type="flag" mission_target="mood_logged_today" rewards="add_points:5"]
      [mission_content]
        <h4>حالت امروز چطور است؟</h4>
        <p>با انتخاب یکی از گزینه‌های زیر، ۵ امتیاز روزانه دریافت کنید.</p>
        <div id="mood-tracker-buttons">
          <button class="mood-btn" data-mood="happy">😄 خوشحال</button>
          <button class="mood-btn" data-mood="neutral">😐 معمولی</button>
          <button class="mood-btn" data-mood="sad">😔 ناراحت</button>
        </div>
      [/mission_content]
      [result_content]
        <p>ممنون از اینکه حال امروزت را با ما در میان گذاشتی. منتظر فردای تو هستیم!</p>
      [/result_content]
    [/station]
    ```
-   **روش پیاده‌سازی (کد JavaScript):**
    این کد جاوااسکریپت را باید در فایل JS قالب خود یا از طریق تنظیمات المنتور اضافه کنید. این کد به محض کلیک روی دکمه‌های مود، یک درخواست AJAX به وردپرس ارسال کرده و تابع `psych_complete_mission_by_flag` را در سمت سرور فراخوانی می‌کند.

    ```javascript
    jQuery(document).ready(function($) {
        $('#mood-tracker-buttons').on('click', '.mood-btn', function() {
            const mood = $(this).data('mood');

            // Disable buttons to prevent multiple clicks
            $('#mood-tracker-buttons .mood-btn').prop('disabled', true).text('...');

            $.ajax({
                url: '<?php echo admin_url("admin-ajax.php"); ?>', // This should be localized
                type: 'POST',
                data: {
                    action: 'log_user_mood_and_flag', // Custom action name
                    mood: mood,
                    nonce: '<?php echo wp_create_nonce("mood_tracker_nonce"); ?>' // Security nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('مود شما ثبت شد! صفحه در حال بارگذاری مجدد است تا پاداش خود را ببینید.');
                        location.reload();
                    }
                }
            });
        });
    });
    ```
-   **روش پیاده‌سازی (کد PHP):**
    این کد PHP را باید در فایل `functions.php` قالب خود اضافه کنید.

    ```php
    add_action('wp_ajax_log_user_mood_and_flag', 'handle_log_user_mood_and_flag');
    function handle_log_user_mood_and_flag() {
        check_ajax_referer('mood_tracker_nonce', 'nonce');

        $user_id = get_current_user_id();
        $mood = sanitize_text_field($_POST['mood']);

        if ($user_id && $mood) {
            $today = date('Y-m-d');
            update_user_meta($user_id, 'mood_log_' . $today, $mood);
            psych_complete_mission_by_flag('mood_logged_today', $user_id);
            wp_send_json_success(['message' => 'Mood logged successfully.']);
        } else {
            wp_send_json_error(['message' => 'Invalid data.']);
        }
    }
    ```

---

### **سناریو ۲: تایید انجام عادت توسط مربی (Habit Tracker by Coach)**

-   **هدف:** پیگیری عادات و وظایف آفلاین کاربر (مانند ورزش، مدیتیشن) و تایید آن توسط مربی برای افزایش مسئولیت‌پذیری.
-   **بازیگران ممکن:** `coach`
-   **نمونه شورت‌کد:**
    ```html
    [station station_node_id="habit_meditation" title="انجام مدیتیشن روزانه" mission_type="flag" mission_target="habit_meditation_day_1" actors="coach"]
      [mission_content]
        [student_only]
          <h4>آیا مدیتیشن امروز را انجام دادی؟</h4>
          <p>پس از انجام، به مربی خود اطلاع بده تا این مرحله را برایت تایید کند.</p>
        [/student_only]
        [coach_only]
          <h4>تایید انجام مدیتیشن</h4>
          <p>آیا دانشجو مدیتیشن امروز را انجام داده است؟ با کلیک روی دکمه زیر، انجام این عادت را برای او ثبت و امتیاز مربوطه را منظور کنید.</p>
          <button class="coach-confirm-btn">تایید می‌کنم</button>
        [/coach_only]
      [/mission_content]
      [result_content]
        <p>عالیست! این عادت توسط مربی شما تایید شد.</p>
      [/result_content]
    [/station]
    ```
-   **روش پیاده‌سازی (JavaScript و PHP):**
    این سناریو نیازمند یک ارتباط AJAX است که وقتی مربی (در حالت impersonate) روی دکمه کلیک می‌کند، فلگ را برای دانشجو فعال کند.

    ```javascript
    // (در فایل JS)
    jQuery(document.body).on('click', '.coach-confirm-btn', function() {
        const button = $(this);
        button.prop('disabled', true).text('در حال ثبت...');

        $.ajax({
            url: '<?php echo admin_url("admin-ajax.php"); ?>',
            type: 'POST',
            data: {
                action: 'coach_confirm_habit',
                target_user_id: '<?php echo get_current_user_id(); ?>', // ID دانشجویی که مربی در حال مشاهده اوست
                flag_name: 'habit_meditation_day_1',
                nonce: '<?php echo wp_create_nonce("coach_habit_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('عادت با موفقیت تایید شد.');
                    location.reload();
                } else {
                    alert('خطا: ' + response.data.message);
                    button.prop('disabled', false).text('تایید می‌کنم');
                }
            }
        });
    });
    ```
    ```php
    // (در فایل functions.php)
    add_action('wp_ajax_coach_confirm_habit', 'handle_coach_confirm_habit');
    function handle_coach_confirm_habit() {
        check_ajax_referer('coach_habit_nonce', 'nonce');
        $coach_id = get_current_user_id(); // کاربر فعلی که مربی است
        $student_id = intval($_POST['target_user_id']);
        $flag_name = sanitize_key($_POST['flag_name']);

        // اینجا باید یک منطق برای بررسی اینکه آیا کاربر فعلی واقعا مربی دانشجو است، پیاده‌سازی شود
        if (current_user_can('manage_options') || is_user_coach_of($coach_id, $student_id)) {
            psych_complete_mission_by_flag($flag_name, $student_id);
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'عدم دسترسی']);
        }
    }
    ```

---

### **سناریو ۳: یکپارچه‌سازی با آزمون‌های ماژول پیشرفته**

-   **هدف:** پیشبرد مسیر پس از تکمیل یک آزمون خاص از ماژول "آزمون‌های پیشرفته".
-   **بازیگران ممکن:** `self`
-   **نمونه شورت‌کد:**
    ```html
    [station station_node_id="complete_advanced_test" title="شرکت در آزمون شخصیت MBTI" mission_type="flag" mission_target="completed_advanced_test_mbti"]
      [mission_content]
        <p>گام بعدی، شرکت در آزمون MBTI است. لطفاً از طریق لینک زیر در آزمون شرکت کنید.</p>
        <a href="/link-to-advanced-test-page/">شروع آزمون MBTI</a>
      [/mission_content]
      [result_content]
        <p>آزمون شما با موفقیت ثبت شد.</p>
      [/result_content]
    [/station]
    ```
-   **روش پیاده‌سازی (PHP):**
    در این سناریو، ما باید از یک "هوک" (Hook) که توسط ماژول آزمون پیشرفته ارائه می‌شود استفاده کنیم. فرض می‌کنیم این ماژول پس از تکمیل آزمون، هوکی به نام `psych_advanced_quiz_completed` را اجرا می‌کند.

    ```php
    add_action('psych_advanced_quiz_completed', 'flag_on_advanced_quiz_completion', 10, 2);
    function flag_on_advanced_quiz_completion($user_id, $quiz_id) {
        $flag_map = [
            'mbti_quiz_id' => 'completed_advanced_test_mbti',
            'disc_quiz_id' => 'completed_advanced_test_disc',
        ];
        if (isset($flag_map[$quiz_id])) {
            psych_complete_mission_by_flag($flag_map[$quiz_id], $user_id);
        }
    }
    ```

---

### **سناریو ۴: مشاهده ویدیو از Spot Player یا پلتفرم‌های دیگر**

-   **هدف:** اطمینان از مشاهده کامل یک ویدیوی آموزشی.
-   **بازیگران ممکن:** `self`
-   **نمونه شورت‌کد:**
    ```html
    [station station_node_id="watch_spotplayer_video" title="مشاهده ویدیوی تحلیل بازار" mission_type="flag" mission_target="watched_spotplayer_1"]
      [mission_content]
        <p>لطفاً ویدیوی زیر را به طور کامل مشاهده کنید.</p>
        <!-- کد embed شده از اسپات پلیر در اینجا قرار می‌گیرد -->
      [/mission_content]
      [result_content]
        <p>ویدیوی آموزشی با موفقیت مشاهده شد.</p>
      [/result_content]
    [/station]
    ```
-   **روش پیاده‌سازی (JavaScript):**
    این روش کاملاً به API جاوااسکریپت پلتفرم ویدیوی شما بستگی دارد. باید در مستندات آن به دنبال رویداد `onFinish` یا `onEnded` بگردید.

    ```javascript
    // این یک کد مفهومی است و باید با API واقعی پلتفرم ویدیو تطبیق داده شود
    var videoPlayer = new SomeVideoPlayer({ videoId: 'XYZ' });

    videoPlayer.on('ended', function() {
        // وقتی ویدیو تمام شد، این تابع اجرا می‌شود. حالا فلگ را از طریق AJAX فعال می‌کنیم.
        jQuery.post(ajaxurl, {
            action: 'trigger_generic_flag',
            flag: 'watched_spotplayer_1',
            nonce: '...'
        });
    });
    ```
    برای این کار، یک تابع عمومی AJAX در PHP نیز نیاز است.
    ```php
    add_action('wp_ajax_trigger_generic_flag', function() {
        // Nonce check
        $user_id = get_current_user_id();
        $flag = sanitize_key($_POST['flag']);
        if ($user_id && $flag) {
            psych_complete_mission_by_flag($flag, $user_id);
            wp_send_json_success();
        }
        wp_send_json_error();
    });
    ```

---

### **سناریو ۵: ارسال فرم گرویتی (روش جایگزین)**

-   **هدف:** تکمیل ماموریت پس از ارسال موفق یک فرم از افزونه Gravity Forms.
-   **بازیگران ممکن:** `self`, `coach`
-   **نمونه شورت‌کد:**
    ```html
    [station station_node_id="submit_gform_feedback" title="ارسال فرم بازخورد دوره" mission_type="flag" mission_target="submitted_gform_feedback_1"]
      [mission_content]
        <p>نظر شما درباره این دوره برای ما بسیار ارزشمند است.</p>
        [gravityform id="7" title="false" description="false" ajax="true"]
      [/mission_content]
      [result_content]
        <p>بازخورد شما با موفقیت ثبت شد. متشکریم!</p>
      [/result_content]
    [/station]
    ```
-   **روش پیاده‌سازی (PHP):**
    از هوک `gform_after_submission` استفاده می‌کنیم. این روش از `mission_type="gform"` قابل اعتمادتر است زیرا به منطق داخلی موتور مسیر وابسته نیست.

    ```php
    add_action('gform_after_submission_7', 'trigger_flag_on_gform_submit', 10, 2); // 7 شناسه فرم است
    function trigger_flag_on_gform_submit($entry, $form) {
        $user_id = rgar($entry, 'created_by');
        if ($user_id) {
            psych_complete_mission_by_flag('submitted_gform_feedback_1', $user_id);
        }
    }
    ```
