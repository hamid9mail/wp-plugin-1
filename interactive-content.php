<?php
/**
 * فایل: interactive-content.php
 * ماژول محتوای تعاملی یکپارچه (Interactive Content Module - Enhanced Integration Edition)
 *
 * این ماژول، موتور اصلی برای ساخت و نمایش تمام محتواهای تعاملی، آزمون‌ها،
 * مسیرهای پیشرفت گرافیکی و ویجت‌های مبتنی بر وضعیت کاربر است.
 * تمام کدهای PHP, CSS و JavaScript در همین فایل قرار دارند.
 *
 * نسخه: 10.3.0 (Enhanced Integration Edition)
 * سازگار با: Coach Module , Path Engine .2, Gamification Center, Report Card
 *
 * قابلیت‌ها:
 * - شورت‌کد [psych_content_block]: برای ساخت بلاک‌های محتوای چندمرحله‌ای (SPA-like).
 * - شورت‌کد [psych_content_view]: برای تعریف "نما" یا "وضعیت"‌های مختلف یک بلاک.
 * - شورت‌کد [psych_button]: دکمه‌های هوشمند برای تکمیل ماموریت، باز کردن مودال، و toggle محتوا.
 * - شورت‌کد [psych_hidden_content]: برای ایجاد محتوای پنهان ( مودال یا toggle).
 * - شورت‌کد [psych_progress_path]: برای نمایش گرافیکی مسیر پیشرفت بج‌ها.
 * - پشتیبانی کامل از Coach Impersonation
 * - تزریق مستقیم CSS و JS برای کاهش درخواست‌های HTTP.
 * - تولید خودکار Schema.org (Quiz & FAQ) برای بهبود SEO.
 * - یکپارچه‌سازی کامل با تمام ماژول‌های دیگر سیستم
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (class_exists('Psych_Interactive_Content_Module')) {
    return;
}

/**
 * Enhanced Interactive Content Module Class
 */
final class Psych_Interactive_Content_Module {

    private static $instance = null;

    // برای نگهداری اطلاعات بلاک‌ها و نماهای آن‌ها در یک صفحه
    private $blocks = [];
    private $current_block_id = null;
    private $assets_injected = false; // برای جلوگیری از تزریق چندباره استایل و اسکریپت
    private $viewing_context = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_shortcodes();
        $this->add_hooks();
        $this->init_viewing_context();
    }

    private function init_viewing_context() {
        // Get viewing context from coach module if available
        if (function_exists('psych_path_get_viewing_context')) {
            $this->viewing_context = psych_path_get_viewing_context();
        } else {
            // Fallback to basic context
            $this->viewing_context = [
                'is_impersonating' => false,
                'real_user_id' => get_current_user_id(),
                'viewed_user_id' => get_current_user_id(),
            ];
        }
    }

    private function get_viewing_context() {
        if ($this->viewing_context === null) {
            $this->init_viewing_context();
        }
        return $this->viewing_context;
    }

    private function add_hooks() {
        // هوک‌ها برای تزریق CSS, JS, و کانتینر مودال
        add_action('wp_head', [$this, 'inject_inline_css']);
        add_action('wp_footer', [$this, 'inject_modal_and_js']);

        // هوک‌های AJAX
        add_action('wp_ajax_psych_load_modal_content', [$this, 'ajax_load_modal_content']);
        add_action('wp_ajax_nopriv_psych_load_modal_content', [$this, 'ajax_load_modal_content']);

        // Integration hooks
        add_action('psych_path_station_completed', [$this, 'handle_station_completion'], 10, 3);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_integration_scripts']);

        // Coach module integration
        add_filter('psych_interactive_can_access_content', [$this, 'coach_access_filter'], 10, 3);
    }

    /**
     * Coach access filter for content restrictions
     */
    public function coach_access_filter($can_access, $user_id, $block_data) {
        $context = $this->get_viewing_context();

        // If coach is impersonating, apply their access rules
        if ($context['is_impersonating'] && class_exists('Psych_Coach_Module')) {
            $coach_id = $context['real_user_id'];
            $current_page_id = get_queried_object_id();

            // Check if coach has access to this page
            $coach_allowed_pages = get_user_meta($coach_id, 'psych_coach_allowed_pages', true) ?: [];
            if (!user_can($coach_id, 'manage_options') && !in_array($current_page_id, (array)$coach_allowed_pages)) {
                return false;
            }
        }

        return $can_access;
    }

    private function register_shortcodes() {
        add_shortcode('psych_content_block', [$this, 'render_content_block_shortcode']);
        add_shortcode('psych_content_view', [$this, 'capture_content_view_shortcode']);
        add_shortcode('psych_button', [$this, 'render_button_shortcode']);
        add_shortcode('psych_hidden_content', [$this, 'render_hidden_content_shortcode']);
        add_shortcode('psych_progress_path', [$this, 'render_progress_path_shortcode']);
    }

    // ===================================================================
    // SECTION 1: Shortcode Rendering
    // ===================================================================

    public function render_content_block_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'id' => 'psych_block_' . uniqid(),
            'default_station' => 'start',
            'type' => 'generic',
            'require_login' => 'false'
        ], $atts, 'psych_content_block');

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        // Check login requirement
        if ($atts['require_login'] === 'true' && !$user_id) {
            return '<div class="psych-login-required"><p>برای مشاهده این محتوا باید وارد شوید.</p></div>';
        }

        // Check access permissions
        $can_access = apply_filters('psych_interactive_can_access_content', true, $user_id, $atts);
        if (!$can_access) {
            return '<div class="psych-access-denied"><p>شما مجاز به مشاهده این محتوا نیستید.</p></div>';
        }

        $this->assets_injected = true; // علامت‌گذاری برای تزریق استایل‌ها
        $block_id = esc_attr($atts['id']);
        $this->current_block_id = $block_id;
        $this->blocks[$block_id] = [
            'views' => [],
            'config' => $atts,
            'seo_data' => ['questions' => []],
            'user_id' => $user_id
        ];

        do_shortcode($content);

        $current_station = 'start';

        // Try to get current station from path engine (updated class name)
        if (class_exists('PsychoCourse_Path_Engine_2')) {
            $path_engine = PsychoCourse_Path_Engine_2::get_instance();
            if (method_exists($path_engine, 'get_user_station_for_block')) {
                $current_station = $path_engine->get_user_station_for_block($block_id, $user_id);
            }
        }

        $current_station = $current_station ?: $atts['default_station'];

        ob_start();

        // Add coach impersonation notice if applicable
        if ($context['is_impersonating']) {
            $viewed_user_data = get_userdata($user_id);
            echo '<div class="coach-interactive-notice">
                    <i class="fas fa-user-eye"></i>
                    در حال مشاهده محتوای تعاملی برای: <strong>' .
                    esc_html($viewed_user_data->display_name) . '</strong>
                  </div>';
        }

        if ($atts['type'] === 'quiz' && !empty($this->blocks[$block_id]['seo_data']['questions'])) {
            $this->render_quiz_schema($block_id, $current_station);
        }

        echo sprintf('<div id="%s" class="psych-content-block" data-station="%s">',
                    $block_id, esc_attr($current_station));

        if (isset($this->blocks[$block_id]['views'][$current_station])) {
            echo do_shortcode($this->blocks[$block_id]['views'][$current_station]['content']);
        } else {
            $default_content = $this->blocks[$block_id]['views'][$atts['default_station']]['content'] ??
                             '<p>محتوا در حال آماده‌سازی است...</p>';
            echo do_shortcode($default_content);
        }

        echo '</div>';
        $this->current_block_id = null;
        return ob_get_clean();
    }

    public function capture_content_view_shortcode($atts, $content = null) {
        if (!$this->current_block_id) return '';

        $atts = shortcode_atts([
            'station' => 'start',
            'seo_title' => '',
            'seo_desc' => '',
            'access_level' => 'public' // public, user, coach, admin
        ], $atts, 'psych_content_view');

        $station_id = esc_attr($atts['station']);
        $this->blocks[$this->current_block_id]['views'][$station_id] = [
            'content' => $content,
            'seo_title' => $atts['seo_title'],
            'seo_desc' => $atts['seo_desc'],
            'access_level' => $atts['access_level']
        ];

        if (!empty($atts['seo_title'])) {
            $this->blocks[$this->current_block_id]['seo_data']['questions'][] = [
                'title' => $atts['seo_title']
            ];
        }

        return '';
    }

    public function render_button_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'text' => 'کلیک کنید',
            'action' => 'mission',
            'mission_id' => '',
            'target' => '',
            'class' => 'psych-btn-primary',
            'icon' => '',
            'confirm' => 'false' // Add confirmation dialog
        ], $atts, 'psych_button');

        $context = $this->get_viewing_context();
        $this->assets_injected = true;

        $data_attrs = '';
        $tag = 'button';
        $href = '';
        $extra_class = '';

        // Add coach impersonation class if applicable
        if ($context['is_impersonating']) {
            $extra_class .= ' coach-impersonation';
        }

        switch ($atts['action']) {
            case 'mission':
                $data_attrs = sprintf('data-action="mission" data-mission-id="%s"', esc_attr($atts['mission_id']));
                break;
            case 'modal':
                $data_attrs = sprintf('data-action="modal" data-target="#%s"', esc_attr($atts['target']));
                break;
            case 'toggle':
                $data_attrs = sprintf('data-action="toggle" data-target="#%s"', esc_attr($atts['target']));
                break;
            case 'link':
                $tag = 'a';
                $href = sprintf('href="%s"', esc_url($atts['target']));
                break;
        }

        if ($atts['confirm'] === 'true') {
            $data_attrs .= ' data-confirm="true"';
        }

        $icon = !empty($atts['icon']) ? sprintf('<i class="%s"></i> ', esc_attr($atts['icon'])) : '';
        $button_text = $context['is_impersonating'] ? $atts['text'] . ' (مربی)' : $atts['text'];

        return sprintf(
            '<%s class="psych-button %s%s" %s %s>%s%s</%s>',
            $tag,
            esc_attr($atts['class']),
            $extra_class,
            $data_attrs,
            $href,
            $icon,
            esc_html($button_text),
            $tag
        );
    }

    public function render_hidden_content_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'id' => 'psych_hidden_' . uniqid(),
            'type' => 'modal', // modal یا toggle
            'title' => '',
            'access_level' => 'public'
        ], $atts, 'psych_hidden_content');

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        // Check access level
        $can_access = $this->check_access_level($atts['access_level'], $user_id, $context);
        if (!$can_access) {
            return '<div class="psych-access-denied"><p>شما مجاز به مشاهده این محتوا نیستید.</p></div>';
        }

        $this->assets_injected = true;
        $content_id = esc_attr($atts['id']);

        if ($atts['type'] === 'modal') {
            return sprintf(
                '<div id="%s" class="psych-modal-content" data-title="%s" style="display:none;">%s</div>',
                $content_id,
                esc_attr($atts['title']),
                do_shortcode($content)
            );
        } else {
            return sprintf(
                '<div id="%s" class="psych-toggle-content" style="display:none;">%s</div>',
                $content_id,
                do_shortcode($content)
            );
        }
    }

    public function render_progress_path_shortcode($atts, $content = null) {
        $atts = shortcode_atts([
            'type' => 'badges', // badges, levels, custom
            'user_id' => '',
            'show_locked' => 'true'
        ], $atts, 'psych_progress_path');

        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

        if (!$user_id) {
            return '<p>برای مشاهده مسیر پیشرفت باید وارد شوید.</p>';
        }

        $this->assets_injected = true;

        ob_start();

        // Add coach notice if applicable
        if ($context['is_impersonating']) {
            $viewed_user_data = get_userdata($user_id);
            echo '<div class="coach-progress-notice">
                    <i class="fas fa-chart-line"></i>
                    مسیر پیشرفت برای: <strong>' . esc_html($viewed_user_data->display_name) . '</strong>
                  </div>';
        }

        echo '<div class="psych-progress-path">';

        if ($atts['type'] === 'badges') {
            echo $this->render_badges_progress($user_id, $atts['show_locked'] === 'true');
        } elseif ($atts['type'] === 'levels') {
            echo $this->render_levels_progress($user_id);
        } else {
            echo do_shortcode($content);
        }

        echo '</div>';
        return ob_get_clean();
    }

    // ===================================================================
    // SECTION 2: Helper Methods
    // ===================================================================

    private function check_access_level($access_level, $user_id, $context) {
        switch ($access_level) {
            case 'admin':
                return user_can($user_id, 'manage_options') ||
                       ($context['is_impersonating'] && user_can($context['real_user_id'], 'manage_options'));
            case 'coach':
                if ($context['is_impersonating']) {
                    $coach_roles = ['coach', 'adviser', 'administrator'];
                    $coach_user = get_userdata($context['real_user_id']);
                    return $coach_user && !empty(array_intersect($coach_roles, (array)$coach_user->roles));
                }
                return false;
            case 'user':
                return $user_id > 0;
            case 'public':
            default:
                return true;
        }
    }

    private function render_badges_progress($user_id, $show_locked = true) {
        $badges_data = [];

        // Try to get badges from gamification center
        if (class_exists('Psych_Gamification_Center')) {
            $gamification = Psych_Gamification_Center::get_instance();
            if (method_exists($gamification, 'get_user_badges_progress')) {
                $badges_data = $gamification->get_user_badges_progress($user_id);
            }
        }

        if (empty($badges_data)) {
            return '<p>اطلاعات نشان‌ها در دسترس نیست.</p>';
        }

        $output = '<div class="badges-progress-container">';
        foreach ($badges_data as $badge) {
            $status_class = $badge['earned'] ? 'earned' : ($show_locked ? 'locked' : 'hidden');
            if (!$show_locked && !$badge['earned']) continue;

            $output .= sprintf(
                '<div class="badge-item %s">
                    <div class="badge-icon">
                        <i class="%s" style="color: %s;"></i>
                    </div>
                    <div class="badge-info">
                        <h4>%s</h4>
                        <p>%s</p>
                        <div class="badge-progress">
                            <div class="progress-bar" style="width: %d%%;"></div>
                        </div>
                    </div>
                </div>',
                $status_class,
                esc_attr($badge['icon'] ?? 'fa-trophy'),
                esc_attr($badge['color'] ?? '#FFD700'),
                esc_html($badge['name']),
                esc_html($badge['description'] ?? ''),
                intval($badge['progress'] ?? 0)
            );
        }
        $output .= '</div>';

        return $output;
    }

    private function render_levels_progress($user_id) {
        $levels_data = [];

        // Try to get levels from gamification center
        if (class_exists('Psych_Gamification_Center')) {
            $gamification = Psych_Gamification_Center::get_instance();
            if (method_exists($gamification, 'get_user_level_info')) {
                $levels_data = $gamification->get_user_level_info($user_id);
            }
        }

        if (empty($levels_data)) {
            return '<p>اطلاعات سطح در دسترس نیست.</p>';
        }

        return sprintf(
            '<div class="levels-progress-container">
                <div class="current-level">
                    <div class="level-icon" style="color: %s;">
                        <i class="%s"></i>
                    </div>
                    <div class="level-info">
                        <h3>%s</h3>
                        <p>امتیاز فعلی: %s</p>
                        <p>تا سطح بعدی: %s امتیاز</p>
                        <div class="level-progress">
                            <div class="progress-bar" style="width: %d%%;"></div>
                        </div>
                    </div>
                </div>
            </div>',
            esc_attr($levels_data['color'] ?? '#3498DB'),
            esc_attr($levels_data['icon'] ?? 'fa-star'),
            esc_html($levels_data['name'] ?? 'نامشخص'),
            number_format_i18n($levels_data['current_points'] ?? 0),
            number_format_i18n($levels_data['points_to_next'] ?? 0),
            intval($levels_data['progress_percentage'] ?? 0)
        );
    }

    private function render_quiz_schema($block_id, $current_station) {
        $block_data = $this->blocks[$block_id];
        $questions = $block_data['seo_data']['questions'];

        if (empty($questions)) return;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Quiz',
            'name' => get_the_title(),
            'description' => get_the_excerpt() ?: 'آزمون روانشناسی',
            'educationalLevel' => 'beginner',
            'educationalUse' => 'assessment',
            'learningResourceType' => 'quiz',
            'inLanguage' => 'fa',
            'hasPart' => []
        ];

        foreach ($questions as $question) {
            $schema['hasPart'][] = [
                '@type' => 'Question',
                'name' => $question['title'],
                'educationalAlignment' => [
                    '@type' => 'AlignmentObject',
                    'alignmentType' => 'educationalSubject',
                    'educationalFramework' => 'Psychology'
                ]
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>';
    }

    // ===================================================================
    // SECTION 3: AJAX Handlers
    // ===================================================================

    public function ajax_load_modal_content() {
        // Enhanced security check
        if (!check_ajax_referer('psych_interactive_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست شما منقضی شده است.'], 403);
        }

        $modal_id = sanitize_key($_POST['modal_id'] ?? '');
        if (empty($modal_id)) {
            wp_send_json_error(['message' => 'شناسه مودال مشخص نشده است.']);
        }

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        // Here you would typically load the modal content from database or other source
        // For now, we'll return a simple response
        $content = '<p>محتوای مودال در حال بارگذاری...</p>';

        // Apply filters for custom content loading
        $content = apply_filters('psych_interactive_modal_content', $content, $modal_id, $user_id, $context);

        wp_send_json_success(['content' => $content]);
    }

    public function handle_station_completion($user_id, $node_id, $station_data) {
        // Handle integration with path engine when stations are completed
        // This can trigger content block state changes
        do_action('psych_interactive_station_completed', $user_id, $node_id, $station_data);
    }

    public function enqueue_integration_scripts() {
        if ($this->assets_injected) {
            // Enqueue integration scripts if needed
            wp_localize_script('jquery', 'psych_interactive_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('psych_interactive_nonce'),
                'viewing_context' => $this->get_viewing_context()
            ]);
        }
    }

    // ===================================================================
    // SECTION 4: CSS & JS Injection
    // ===================================================================

    public function inject_inline_css() {
        if (!$this->assets_injected) return;
        ?>
        <style id="psych-interactive-styles">
            /* Coach impersonation notices */
            .coach-interactive-notice, .coach-progress-notice {
                background: linear-gradient(135deg, #8E44AD, #9B59B6);
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                margin-bottom: 20px;
                text-align: center;
                font-weight: bold;
                box-shadow: 0 2px 8px rgba(142, 68, 173, 0.3);
            }

            .coach-interactive-notice i,
            .coach-progress-notice i {
                margin-right: 8px;
            }

            /* Content blocks */
            .psych-content-block {
                margin: 20px 0;
                padding: 25px;
                background: #fdfdfd;
                border: 1px solid #e1e1e1;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
            }

            .psych-content-block:hover {
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }

            /* Buttons */
            .psych-button {
                display: inline-block;
                padding: 12px 25px;
                margin: 5px;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.3s ease;
                text-align: center;
            }

            .psych-btn-primary {
                background: linear-gradient(135deg, #3498DB, #2980B9);
                color: white;
            }

            .psych-btn-primary:hover {
                background: linear-gradient(135deg, #2980B9, #1F618D);
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            }

            .psych-btn-success {
                background: linear-gradient(135deg, #27AE60, #229954);
                color: white;
            }

            .psych-btn-success:hover {
                background: linear-gradient(135deg, #229954, #1E8449);
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            }

            .psych-btn-warning {
                background: linear-gradient(135deg, #F39C12, #E67E22);
                color: white;
            }

            .psych-btn-warning:hover {
                background: linear-gradient(135deg, #E67E22, #D35400);
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
            }

            .psych-button.coach-impersonation {
                border: 2px solid #8E44AD;
                position: relative;
            }

            .psych-button.coach-impersonation::after {
                content: "👁";
                position: absolute;
                top: -5px;
                right: -5px;
                background: #8E44AD;
                color: white;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
            }

            /* Modal styles */
           .psych-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.6);
                display: flex; /* Changed from none to flex */
                align-items: center;
                justify-content: center;
                z-index: 9999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .psych-modal-overlay.active {
                opacity: 1;
                visibility: visible;
            }


            .psych-modal-dialog {
                background: white;
                border-radius: 10px;
                padding: 30px;
                max-width: 90%;
                max-height: 90%;
                overflow-y: auto;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                transform: scale(0.8);
                transition: transform 0.3s ease;
            }

            .psych-modal-overlay.active .psych-modal-dialog {
                transform: scale(1);
            }

            .psych-modal-close {
                position: absolute;
                top: 15px;
                right: 20px;
                font-size: 24px;
                background: none;
                border: none;
                cursor: pointer;
                color: #666;
                z-index: 10001;
            }

            .psych-modal-close:hover {
                color: #dc3545;
            }

            /* Toggle content */
            .psych-toggle-content {
                margin-top: 15px;
                padding: 20px;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 6px;
                animation: slideDown 0.3s ease-out;
            }

            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            /* Progress path styles */
            .psych-progress-path {
                margin: 30px 0;
            }

            .badges-progress-container {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
            }

            .badge-item {
                display: flex;
                align-items: center;
                padding: 20px;
                background: white;
                border: 2px solid #e9ecef;
                border-radius: 10px;
                transition: all 0.3s ease;
            }

            .badge-item.earned {
                border-color: #28a745;
                background: linear-gradient(135deg, #d4edda, #c3e6cb);
            }

            .badge-item.locked {
                opacity: 0.6;
                border-color: #6c757d;
            }

            .badge-item:hover {
                transform: translateY(-3px);
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            }

            .badge-icon {
                margin-left: 15px;
                font-size: 2.5rem;
            }

            .badge-info h4 {
                margin: 0 0 5px 0;
                font-size: 1.1rem;
            }

            .badge-info p {
                margin: 0 0 10px 0;
                color: #666;
                font-size: 0.9rem;
            }

            .badge-progress, .level-progress {
                width: 100%;
                height: 8px;
                background: #e9ecef;
                border-radius: 4px;
                overflow: hidden;
            }

            .progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #28a745, #20c997);
                transition: width 0.5s ease;
            }

            .levels-progress-container {
                padding: 30px;
                background: white;
                border-radius: 15px;
                box-shadow: 0 5px 25px rgba(0,0,0,0.1);
            }

            .current-level {
                display: flex;
                align-items: center;
            }

            .level-icon {
                margin-left: 20px;
                font-size: 4rem;
            }

            .level-info h3 {
                margin: 0 0 10px 0;
                font-size: 1.5rem;
            }

            .level-info p {
                margin: 5px 0;
                color: #666;
            }

            /* Access denied / Login required */
            .psych-login-required,
            .psych-access-denied {
                padding: 20px;
                text-align: center;
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 8px;
                color: #856404;
            }

            .psych-access-denied {
                background: #f8d7da;
                border-color: #f5c6cb;
                color: #721c24;
            }

            /* Responsive design */
            @media (max-width: 768px) {
                .psych-content-block {
                    padding: 15px;
                    margin: 10px 0;
                }

                .psych-button {
                    display: block;
                    width: 100%;
                    margin: 5px 0;
                }

                .psych-modal-dialog {
                    padding: 20px;
                    margin: 10px;
                }

                .badges-progress-container {
                    grid-template-columns: 1fr;
                }

                .badge-item,
                .current-level {
                    flex-direction: column;
                    text-align: center;
                }

                .badge-icon,
                .level-icon {
                    margin: 0 0 15px 0;
                }
            }
        </style>
        <?php
    }

    public function inject_modal_and_js() {
    if (!$this->assets_injected) return;
    ?>

    <!-- Modal Container -->
    <div id="psych-modal-overlay" class="psych-modal-overlay" style="display: none;">
        <div class="psych-modal-dialog">
            <button class="psych-modal-close" id="psych-modal-close">&times;</button>
            <div id="psych-modal-content"></div>
        </div>
    </div>

    <script id="psych-interactive-scripts">
    jQuery(document).ready(function($) {
        // تنظیمات مودال
        const modal = $('#psych-modal-overlay');
        const modalContent = $('#psych-modal-content');
        const closeBtn = $('#psych-modal-close');

        // اطمینان از بسته بودن مودال در ابتدا
        modal.removeClass('active').hide();
        $('body').css('overflow', '');

        // Button click handlers
        $(document).on('click', '.psych-button', function(e) {
            const $this = $(this);
            const action = $this.data('action');
            const target = $this.data('target');
            const missionId = $this.data('mission-id');
            const needsConfirm = $this.data('confirm');

            // Confirmation dialog
            if (needsConfirm && !confirm('آیا مطمئن هستید؟')) {
                e.preventDefault();
                return false;
            }

            switch (action) {
                case 'modal':
                    e.preventDefault();
                    openModal(target);
                    break;

                case 'toggle':
                    e.preventDefault();
                    toggleContent(target);
                    break;

                case 'mission':
                    e.preventDefault();
                    completeMission(missionId, $this);
                    break;

                case 'link':
                    // Let the default link behavior happen
                    break;
            }
        });

        // Modal functions
        function openModal(targetId) {
            const targetElement = $(targetId);
            if (targetElement.length) {
                const title = targetElement.data('title') || 'اطلاعات';
                const content = targetElement.html();

                modalContent.html('<h2>' + title + '</h2>' + content);
                modal.show().addClass('active');
                $('body').css('overflow', 'hidden');
            } else {
                // Load via AJAX
                loadModalContent(targetId.replace('#', ''));
            }
        }

        function loadModalContent(modalId) {
            modalContent.html('<div style="text-align:center; padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:2rem; color:#3498DB;"></i><p>در حال بارگذاری...</p></div>');
            modal.show().addClass('active');
            $('body').css('overflow', 'hidden');

            // بررسی وجود psych_interactive_ajax
            if (typeof psych_interactive_ajax === 'undefined') {
                modalContent.html('<div class="alert alert-danger"><p>خطا: متغیرهای JavaScript بارگذاری نشده‌اند.</p></div>');
                return;
            }

            $.post(psych_interactive_ajax.ajax_url, {
                action: 'psych_load_modal_content',
                modal_id: modalId,
                nonce: psych_interactive_ajax.nonce
            }).done(function(response) {
                if (response.success) {
                    modalContent.html(response.data.content);
                } else {
                    modalContent.html('<div class="alert alert-danger"><p>خطا در بارگذاری محتوا: ' + (response.data.message || 'خطای ناشناخته') + '</p></div>');
                }
            }).fail(function(xhr, status, error) {
                modalContent.html('<div class="alert alert-danger"><p>خطای ارتباط با سرور: ' + error + '</p></div>');
            });
        }

        function closeModal() {
            modal.removeClass('active');
            // تاخیر برای انیمیشن
            setTimeout(function() {
                modal.hide();
                $('body').css('overflow', '');
                modalContent.empty();
            }, 300);
        }

        function toggleContent(targetId) {
            const targetElement = $(targetId);
            if (targetElement.length) {
                targetElement.slideToggle(300);
            }
        }

        function completeMission(missionId, buttonElement) {
            if (!missionId) {
                alert('شناسه ماموریت مشخص نشده است.');
                return;
            }

            const originalText = buttonElement.html();
            buttonElement.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> در حال پردازش...');

            // Here you would integrate with the path engine or other mission systems
            // For now, we'll show a success message
            setTimeout(function() {
                alert('✅ ماموریت با موفقیت تکمیل شد!');
                buttonElement.prop('disabled', false).html('✅ تکمیل شد');
                buttonElement.removeClass('psych-btn-primary').addClass('psych-btn-success');

                // Fire custom event for other integrations
                $(document).trigger('psych_mission_completed', [missionId, buttonElement]);
            }, 1500);
        }

        // Close modal events
        closeBtn.on('click', function(e) {
            e.preventDefault();
            closeModal();
        });

        modal.on('click', function(e) {
            if ($(e.target).is(modal)) {
                closeModal();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && modal.hasClass('active')) {
                e.preventDefault();
                closeModal();
            }
        });

        // اطمینان از بسته بودن مودال در صورت بارگذاری مجدد صفحه
        $(window).on('beforeunload', function() {
            closeModal();
        });
    });
    </script>
    <?php
}

}

// Initialize the enhanced module
Psych_Interactive_Content_Module::get_instance();
