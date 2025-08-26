# راهنمای کامل سیستم جامع روان گستر (Psych Complete System)

## مقدمه
این راهنما به شما کمک می‌کند تا از تمام قابلیت‌ها و شورت‌کدهای موجود در سیستم جامع روان گستر، به خصوص ماژول قدرتمند مسیر رشد (Path Engine)، به بهترین شکل استفاده کنید. این سیستم برای ایجاد تجربه‌های یادگیری پویا، تعاملی و شخصی‌سازی شده طراحی شده است.

---

## بخش ۱: ماژول مسیر رشد (Path Engine)

### ۱.۱ - مفهوم کلیدی: سیستم چند بازیگری (Multi-Actor System)
یکی از قدرتمندترین ویژگی‌های سیستم مسیر رشد، توانایی آن در مدیریت "بازیگران" (Actors) مختلف است. این یعنی سیستم به خوبی درک می‌کند که **چه کسی** در حال انجام یک عمل است و آن عمل **برای چه کسی** ثبت می‌شود. این قابلیت به شما اجازه می‌دهد سناریوهای ارزیابی، تایید و بازخورد بسیار پیشرفته‌ای را پیاده‌سازی کنید.

---
این ماژول به شما اجازه می‌دهد تا مسیرهای یادگیری پیچیده، شرطی و تعاملی برای کاربران خود بسازید.

### ۱.۲ - شورت‌کد اصلی مسیر: `[psychocourse_path]`
این شورت‌کد به عنوان کانتینر اصلی برای یک مسیر عمل می‌کند و تمام ایستگاه‌ها باید درون آن قرار گیرند.
*   **ویژگی‌ها:** `display_mode`, `theme`, `show_progress`, `path_title`.

---

### ۱.۳ - شورت‌کد ایستگاه: `[station]`
قلب تپنده مسیر شما. هر `[station]` یک مرحله یا ماموریت در مسیر است.

**مهم‌ترین ویژگی‌ها:**

| ویژگی | توضیح | مثال |
| :--- | :--- | :--- |
| `title` | **(الزامی)** عنوان ایستگاه. | `title="فصل اول: مقدمه"` |
| `station_node_id` | **(بسیار مهم)** شناسه منحصر به فرد ایستگاه برای ارجاع در پاداش‌ها. | `station_node_id="intro_ch1"` |
| `initial_state` | وضعیت اولیه ایستگاه. مقادیر با کاما جدا می‌شوند. | `initial_state="locked,hidden"` |
| `mission_type` | نوع ماموریت. | `mission_type="gform"` |
| `mission_target` | هدف ماموریت (بسته به نوع). | `mission_target="form_id:7"` |
| `rewards` | پاداش‌ها پس از تکمیل. پاداش‌ها با `|` جدا می‌شوند. | `rewards="add_points:50|show_station:ch2"` |

**توضیح `mission_type="flag"`:**
این نوع ماموریت بسیار انعطاف‌پذیر است و به عنوان یک **"آداپتور جهانی"** عمل می‌کند. شما می‌توانید از هر جای دیگر سایت (با استفاده از کدنویسی و تابع `psych_complete_mission_by_flag`) این فلگ را فعال کرده و در نتیجه این ایستگاه را تکمیل کنید. این برای یکپارچه‌سازی با رویدادهای خارجی (مانند تکمیل یک پروفایل، مشاهده یک ویدیو و ...) عالی است.

---

### ۱.۴ - کنترل وضعیت ایستگاه از طریق پاداش‌ها
شما می‌توانید از پاداش‌های یک ایستگاه برای تغییر وضعیت یک ایستگاه دیگر استفاده کنید. این کار با ارجاع به `station_node_id` ایستگاه هدف انجام می‌شود.

*   `show_station:station_id`: یک ایستگاه مخفی را نمایان می‌کند.
*   `hide_station:station_id`: یک ایستگاه را مخفی می‌کند.
*   `unlock_station:station_id`: یک ایستگاه قفل را باز می‌کند.
*   `lock_station:station_id`: یک ایستگاه را قفل می‌کند.

**مثال: مسیر شرطی**
```html
<!-- ایستگاه انتخاب مسیر -->
[station station_node_id="path_choice" title="انتخاب مسیر تخصصی" rewards="show_station:tech_path_1|unlock_station:tech_path_1"]
  [mission_content]
    <p>با تکمیل این مرحله، مسیر تکنیکی برای شما باز خواهد شد.</p>
  [/mission_content]
[/station]

<!-- ایستگاه مسیر تکنیکی (در ابتدا مخفی و قفل) -->
[station station_node_id="tech_path_1" title="درس ۱: مبانی برنامه‌نویسی" initial_state="locked,hidden"]
  [mission_content]
    <p>به مسیر تکنیکی خوش آمدید!</p>
  [/mission_content]
[/station]
```
*توضیح:* پس از تکمیل ایستگاه `path_choice`، ایستگاه `tech_path_1` که در ابتدا مخفی و قفل بود، همزمان نمایان و باز می‌شود.

---

### ۱.۵ - شورت‌کدهای محتوای ایستگاه
*   `[static_content]`, `[mission_content]`, `[result_content]` برای سازماندهی محتوای درون `[station]` استفاده می‌شوند.

---

### ۱.۶ - سیستم بازیگران و تایید مربی
این دو سیستم به شما اجازه می‌دهند ماموریت‌های پیچیده‌تر با تعاملات چندنفره و نیاز به بازبینی ایجاد کنید.

| ویژگی `[station]` | توضیح |
| :--- | :--- |
| `actors` | چه کسانی مجاز به تکمیل ماموریت هستند (`self`, `coach`, `guest_link`, `user_link`). |
| `required_actors_count` | تعداد بازیگران خارجی لازم برای تکمیل ماموریت. |
| `requires_approval` | اگر برابر `coach` باشد، ماموریت پس از انجام توسط دانشجو نیازمند تایید مربی خواهد بود. |

| شورت‌کد | هدف |
| :--- | :--- |
| `[mission_actor_link]` | لینک اشتراک‌گذاری برای ماموریت‌های چند بازیگره. |
| `[mission_actors_count]` | نمایش تعداد بازیگرانی که ماموریت را تکمیل کرده‌اند. |
| `[mission_approval_form]` | نمایش فرم تایید/رد برای مربی. |

---

## بخش ۲: ماژول شخصی‌سازی (Personalization Module)
با استفاده از شورت‌کد `[psych_personalize]` و ویژگی `condition`، محتوای شرطی بر اساس اطلاعات کاربر (امتیاز، نشان، نقش و ...) نمایش دهید.
*   **مثال:** `[psych_personalize condition="points>=1000"]محتوای ویژه[/psych_personalize]`

---

## بخش ۳: شورت‌کدهای عمومی و گیمیفیکیشن
این شورت‌کدها برای نمایش اطلاعات کاربر در هر جای سایت استفاده می‌شوند (`[psych_dashboard]`, `[psych_user_points]`, `[psych_leaderboard]` و ...).

---

## بخش ۴: مثال جامع
```html
[psychocourse_path]

  [station station_node_id="ch1" title="فصل اول: مقدمه" rewards="unlock_station:ch2"]
    ...
  [/station]

  [station station_node_id="ch2" title="فصل دوم: تکلیف" initial_state="locked" requires_approval="coach" rewards="show_station:ch3_feedback"]
    [mission_content]
      <p>تکلیف خود را ارسال کنید.</p>
      [gravityform id="8" ajax="true"]
      [coach_only]
        [mission_approval_form]
      [/coach_only]
    [/mission_content]
  [/station]

  [station station_node_id="ch3_feedback" title="فصل سوم: بازخورد ۳۶۰ درجه" initial_state="hidden" actors="guest_link" required_actors_count="3"]
    [static_content]
      <p>لینک زیر را برای ۳ همکار ارسال کنید: [mission_actor_link]</p>
      <p>پیشرفت: [mission_actors_count]</p>
    [/static_content]
  [/station]

[/psychocourse_path]
```
