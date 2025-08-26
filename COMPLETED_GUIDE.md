# راهنمای کامل و نهایی سیستم جامع روان گستر (نسخه معماری فلگ)

## مقدمه
این راهنما به شما کمک می‌کند تا از تمام قابلیت‌ها و شورت‌کدهای موجود در سیستم جامع روان گستر، به خصوص ماژول قدرتمند مسیر رشد (Path Engine) و سیستم پیشرفته فلگ، به بهترین شکل استفاده کنید.

---

## بخش ۱: ماژول مسیر رشد (Path Engine)

### ۱.۱ - مفهوم کلیدی: سیستم چند بازیگری (Multi-Actor System)
این سیستم به شما اجازه می‌دهد تا مشخص کنید چه کسی (دانشجو، مربی، یا کاربر مهمان) یک ماموریت را انجام می‌دهد. این قابلیت با استفاده از ویژگی `actors` در شورت‌کد `[station]` کنترل می‌شود.

### ۱.۲ - شورت‌کد اصلی مسیر: `[psychocourse_path]`
این شورت‌کد کانتینر اصلی برای یک مسیر است.
- **ویژگی‌ها:** `display_mode`, `theme`, `show_progress`, `path_title`.

### ۱.۳ - شورت‌کد ایستگاه: `[station]`
قلب تپنده مسیر شما. هر `[station]` یک مرحله یا ماموریت در مسیر است.
- **ویژگی‌های اصلی:** `title`, `station_node_id`, `icon`.
- **ویژگی‌های کنترل وضعیت:** `initial_state`, `unlock_trigger`.
- **ویژگی‌های ماموریت:** `mission_type`, `mission_target`, `mission_button_text`.
- **ویژگی‌های پاداش:** `rewards`, `notification_text`.

---

## بخش ۲: معماری جدید و قدرتمند فلگ (Flag System Architecture)

بر اساس بازخورد هوشمندانه شما، معماری سیستم فلگ به گونه‌ای طراحی شده که بسیار قدرتمند، مقیاس‌پذیر و با کاربری آسان باشد.

### اصل ۱: افزودن فلگ پس از تکمیل هر ماموریت
شما می‌توانید به **هر ایستگاهی**، فارغ از نوع ماموریت آن، یک ویژگی جدید به نام `sets_flag_on_complete` اضافه کنید. به محض اینکه کاربر آن ایستگاه را با موفقیت تکمیل کند، فلگ مورد نظر برای او در سیستم ثبت (ست) می‌شود.

**مثال:**
```html
[station
    mission_type="gform"
    mission_target="form_id:7"
    sets_flag_on_complete="user_submitted_feedback_form"
]
...
[/station]
```
در این مثال، پس از ارسال فرم شماره ۷، فلگی به نام `user_submitted_feedback_form` برای کاربر ثبت می‌شود.

---

### اصل ۲: استفاده از فلگ به عنوان شرط
شما می‌توانید از فلگ‌ها برای کنترل نمایش یا باز شدن ایستگاه‌های دیگر استفاده کنید. برای این کار، دو شرط جدید به `unlock_condition` اضافه شده است:

-   `has_flag:your_flag_name`: ایستگاه فقط در صورتی باز می‌شود که کاربر فلگ مورد نظر را داشته باشد.
-   `has_not_flag:your_flag_name`: ایستگاه فقط در صورتی باز می‌شود که کاربر فلگ مورد نظر را **نداشته باشد**.

**مثال:**
```html
[station
    title="محتوای ویژه برای کسانی که بازخورد داده‌اند"
    unlock_condition="has_flag:user_submitted_feedback_form"
]
...
[/station]
```

---

### اصل ۳: دکمه اختصاصی برای ست کردن فلگ `[psych_button]`
برای رویدادهای سفارشی که در چارچوب ایستگاه‌ها نیستند (مانند کلیک روی یک دکمه در یک صفحه فروش)، می‌توانید از این شورت‌کد جدید استفاده کنید.

-   **ویژگی‌ها:**
    -   `flag`: (الزامی) نام فلگی که می‌خواهید پس از کلیک ست شود.
    -   `text`: متن دکمه.
    -   `class`: کلاس CSS سفارشی برای استایل‌دهی.

**مثال:**
```html
<p>با کلیک روی دکمه زیر، تایید می‌کنید که قوانین سایت را مطالعه کرده‌اید.</p>
[psych_button flag="user_accepted_terms" text="قوانین را خواندم و می‌پذیرم"]
```

---

### اصل ۴: شورت‌کدهای پوششی (Wrapper) برای افزونه‌های دیگر
برای اینکه بتوانید به شورت‌کدهای افزونه‌های دیگر (مثل گرویتی فرم یا آزمون‌ساز) قابلیت ست کردن فلگ را اضافه کنید، از شورت‌کدهای پوششی استفاده می‌کنیم.

#### الف) پوشش برای گرویتی فرم:
```html
[trigger_on_gform_submit flag="user_sent_contact_form"]
    [gravityform id="1" ajax="true"]
[/trigger_on_gform_submit]
```
در این مثال، به محض ارسال فرم شماره ۱، فلگ `user_sent_contact_form` برای کاربر ست می‌شود.

#### ب) پوشش برای آزمون‌ساز پیشرفته (با شرط):
```html
[trigger_on_quiz_complete flag="passed_mbti_test" condition="score>90"]
    [advanced_quiz id="mbti"]
[/trigger_on_quiz_complete]
```
در این مثال، فلگ `passed_mbti_test` تنها در صورتی برای کاربر ست می‌شود که آزمون `mbti` را با نمره **بالای ۹۰** به پایان برساند.

---

## بخش ۳: سناریوی جامع برای نمایش تمام قابلیت‌ها

<!--
    نیازمندی‌ها برای این سناریو:
    1. محصول ووکامرس با ID: 123
    2. فرم گرویتی با ID: 456
    3. نشان‌هایی با نامک: 'starter_badge', 'pro_badge', 'vip_customer'
-->

[psychocourse_path path_title="دوره جامع آموزش سیستم (نسخه فلگ)" display_mode="timeline"]

    [station
        station_node_id="intro"
        title="۱. خوش‌آمدگویی"
        mission_type="button_click"
        rewards="add_points:10|award_badge:starter_badge"
        sets_flag_on_complete="started_the_course"
    ]
        [static_content]<h3>به دوره جامع آموزش سیستم خوش آمدید!</h3>[/static_content]
        [result_content]<p>عالی! شما فلگ "شروع دوره" را کسب کردید.</p>[/result_content]
    [/station]

    [station
        station_node_id="purchase_mission"
        title="۲. خرید محصول برای فعال‌سازی مسیر VIP"
        mission_type="purchase"
        mission_target="product_id:123"
        rewards="add_points:100|award_badge:vip_customer"
        sets_flag_on_complete="is_vip"
    ]
        [mission_content]<p>برای دسترسی به محتوای ویژه، محصول "عضویت VIP" را تهیه کنید.</p>[/mission_content]
        [result_content]<p>خرید شما با موفقیت ثبت شد. فلگ "VIP" برای شما فعال شد!</p>[/result_content]
    [/station]

    [station
        station_node_id="vip_content"
        title="۳. محتوای ویژه برای کاربران VIP"
        unlock_condition="has_flag:is_vip"
    ]
        [static_content]
            <h4>تبریک!</h4>
            <p>شما کاربر ویژه هستید و این محتوای مخفی برای شما نمایش داده می‌شود.</p>
        [/static_content]
    [/station]

    [station
        station_node_id="coach_approval"
        title="۴. تایید انجام وظیفه آفلاین توسط مربی"
        actors="coach"
        mission_type="flag"
        mission_target="did_offline_task"
    ]
        [mission_content]
            [student_only]<p>این مرحله باید توسط مربی شما تایید شود.</p>[/student_only]
            [coach_only]
                <p>آیا دانشجو وظیفه آفلاین را انجام داده؟</p>
                [psych_button flag="did_offline_task" text="بله، تایید می‌کنم"]
            [/coach_only]
        [/mission_content]
    [/station]

    [station
        station_node_id="final_step"
        title="۵. مرحله نهایی"
        unlock_condition="has_flag:did_offline_task"
    ]
        [static_content]<p>شما تمام مراحل را با موفقیت به پایان رساندید.</p>[/static_content]
    [/station]

[/psychocourse_path]
