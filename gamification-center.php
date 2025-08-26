<?php
/**
 * Plugin Name: Psych Complete System - Gamification Center (Enhanced Integration Edition)
 * Description: ماژول مرکزی یکپارچه برای مدیریت نشان‌ها، امتیازات، سطوح، نوتیفیکیشن‌ها و اتوماسیون پیامک
 * Version: 2.5.0 (Enhanced Integration Edition)
 * Author: Enhanced Integration Team
 *
 * فایل: gamification-center.php
 * این نسخه Enhanced شامل:
 * - هماهنگی کامل با Coach Module , Path Engine .2, Interactive Content .3
 * - پشتیبانی کامل از Coach Impersonation
 * - تمام متدهای مورد نیاز سایر ماژول‌ها
 * - سیستم امنیتی پیشرفته
 * - API Functions پایدار و قابل اعتماد
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// =====================================================================
// SECTION 0: GLOBAL API FUNCTIONS (STABLE & RELIABLE)
// این توابع به عنوان یک API پایدار برای سایر ماژول‌ها عمل می‌کنند.
// =====================================================================

if (!function_exists('psych_gamification_get_user_level')) {
    /**
     * API Function: اطلاعات سطح یک کاربر را برمی‌گرداند.
     * @param int $user_id شناسه کاربر.
     * @return array جزئیات سطح کاربر.
     */
    function psych_gamification_get_user_level($user_id) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->get_user_level($user_id);
        }
        return ['name' => 'N/A', 'icon' => 'fa-question-circle', 'color' => '#ccc'];
    }
}

if (!function_exists('psych_gamification_get_user_level_info')) {
    /**
     * API Function: اطلاعات کامل سطح و پیشرفت یک کاربر را برمی‌گرداند.
     * @param int $user_id شناسه کاربر.
     * @return array جزئیات کامل سطح کاربر.
     */
    function psych_gamification_get_user_level_info($user_id) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->get_user_level_info($user_id);
        }
        return [
            'name' => 'N/A', 
            'icon' => 'fa-question-circle', 
            'color' => '#ccc', 
            'points_to_next' => 0, 
            'current_points' => 0, 
            'progress_percentage' => 0
        ];
    }
}

if (!function_exists('psych_gamification_add_points')) {
    /**
     * API Function: به یک کاربر امتیاز اضافه می‌کند.
     */
    function psych_gamification_add_points($user_id, $points, $reason = 'کسب امتیاز', $custom_notification = null) {
        if (class_exists('Psych_Gamification_Center')) {
            Psych_Gamification_Center::get_instance()->add_points($user_id, $points, $reason, $custom_notification);
        }
    }
}

if (!function_exists('psych_gamification_award_badge')) {
    /**
     * API Function: یک نشان را بر اساس نامک (slug) به کاربر اعطا می‌کند.
     */
    function psych_gamification_award_badge($user_id, $badge_slug, $custom_notification = null) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->award_badge($user_id, $badge_slug, $custom_notification);
        }
        return false;
    }
}

// Compatibility functions for older modules and path engine
if (!function_exists('psych_award_badge_to_user')) {
    /**
     * Compatibility function for path engine
     */
    function psych_award_badge_to_user($user_id, $badge_id) {
        return psych_gamification_award_badge($user_id, $badge_id);
    }
}

if (!function_exists('psych_user_has_badge')) {
    /**
     * Check if user has a specific badge
     */
    function psych_user_has_badge($user_id, $badge_slug) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->user_has_badge($user_id, $badge_slug);
        }
        return false;
    }
}

if (!function_exists('psych_get_badge_name')) {
    /**
     * Get badge name by slug
     */
    function psych_get_badge_name($badge_slug) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->get_badge_name($badge_slug);
        }
        return 'نامشخص';
    }
}

if (!function_exists('psych_send_sms_by_template')) {
    /**
     * Send SMS by template (compatibility function)
     */
    function psych_send_sms_by_template($user_id, $template, $vars = []) {
        if (class_exists('Psych_Gamification_Center')) {
            return Psych_Gamification_Center::get_instance()->send_sms_by_template($user_id, $template, $vars);
        }
        return false;
    }
}

if (!function_exists('psych_gamification_queue_notification')) {
    /**
     * Queue a notification for a user
     */
    function psych_gamification_queue_notification($user_id, $title, $message) {
        if (class_exists('Psych_Gamification_Center')) {
            Psych_Gamification_Center::get_instance()->queue_notification($user_id, $title, $message);
        }
    }
}

// =====================================================================
// SECTION 1: MAIN PLUGIN CLASS
// =====================================================================

if (class_exists('Psych_Gamification_Center')) {
    return; // جلوگیری از تعریف مجدد کلاس.
}

final class Psych_Gamification_Center {

    /**
     * @var string نسخه فعلی ماژول برای مقاصد اطلاعاتی.
     */
    const VERSION = '2.5.0';

    /**
     * @var Psych_Gamification_Center تنها نمونه از این کلاس (Singleton).
     */
    private static $instance;
    
    // کلیدهای ثابت برای گزینه‌های دیتابیس.
    const LEVELS_OPTION_KEY   = 'psych_gamification_levels';
    const BADGES_OPTION_KEY   = 'psych_gamification_badges';
    const SETTINGS_OPTION_KEY = 'psych_gamification_settings';
    
    private $admin_page_slug = 'psych-gamification-center';
    private $viewing_context = null;

    /**
     * متد اصلی برای گرفتن نمونه کلاس (Singleton Pattern).
     * @return Psych_Gamification_Center
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس (Constructor).
     */
    private function __construct() {
        $this->init_viewing_context();
        $this->add_hooks();
    }

    private function init_viewing_context() {
        // Get viewing context from path engine if available
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
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_psych_manual_award', [$this, 'handle_manual_award_ajax']);

        // Frontend hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_footer', [$this, 'render_footer_elements']);
        add_action('wp_ajax_psych_get_pending_notifications', [$this, 'ajax_get_pending_notifications']);
        add_action('wp_ajax_psych_clear_notification', [$this, 'ajax_clear_notification']);

        // Integration hooks
        add_action('psych_user_earned_badge', [$this, 'handle_badge_earned_automation'], 10, 2);
        add_action('psych_path_station_completed', [$this, 'handle_station_completion'], 10, 3);
        add_action('psych_coach_student_progress', [$this, 'handle_coach_notification'], 10, 4);
        
        // Shortcodes
        add_shortcode('psych_user_points', [$this, 'render_user_points_shortcode']);
        add_shortcode('psych_user_level', [$this, 'render_user_level_shortcode']);
        add_shortcode('psych_user_badges', [$this, 'render_user_badges_shortcode']);
        add_shortcode('psych_leaderboard', [$this, 'render_leaderboard_shortcode']);
        
        // Reward processing hooks (for path engine integration)
        add_action('psych_reward_points', 'psych_gamification_add_points', 10, 3);
        add_action('psych_reward_badge', 'psych_award_badge_to_user', 10, 2);
        add_action('psych_reward_coupon', [$this, 'reward_generate_coupon'], 10, 3);
        add_action('psych_reward_unlock_product', [$this, 'reward_unlock_product'], 10, 3);
        add_action('psych_reward_send_file', [$this, 'reward_send_file'], 10, 3);
        add_action('psych_reward_send_sms', [$this, 'reward_send_sms'], 10, 3);
    }

    /**
     * Handle station completion from path engine
     */
    public function handle_station_completion($user_id, $node_id, $station_data) {
        $points = isset($station_data['points']) ? intval($station_data['points']) : 10;
        $custom_notification = $station_data['notification_text'] ?? null;
        
        if ($points > 0) {
            $reason = 'تکمیل ایستگاه: ' . ($station_data['title'] ?? 'ایستگاه');
            $this->add_points($user_id, $points, $reason, $custom_notification);
        }
        
        $this->check_automatic_badge_awards($user_id);
    }

    /**
     * Handle coach notification when student makes progress
     */
    public function handle_coach_notification($coach_id, $student_id, $node_id, $station_data) {
        $student_data = get_userdata($student_id);
        if (!$student_data) return;
        
        $this->queue_notification(
            $coach_id,
            'پیشرفت دانشجو',
            $student_data->display_name . ' ایستگاه جدیدی را تکمیل کرد',
            'student_progress',
            ['student_id' => $student_id, 'station' => $station_data['title'] ?? 'ایستگاه']
        );
    }

    /**
     * Check for automatic badge awards based on user stats
     */
    private function check_automatic_badge_awards($user_id) {
        $user_points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $user_badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];
        
        // Example automatic badges
        $auto_badges = [
            'first_steps' => ['points' => 50, 'name' => 'نخستین گام‌ها'],
            'dedicated_learner' => ['points' => 200, 'name' => 'فراگیر مجاهد'],
            'point_collector' => ['points' => 500, 'name' => 'جمع‌آور امتیاز'],
            'achievement_hunter' => ['points' => 1000, 'name' => 'شکارچی موفقیت'],
        ];
        
        foreach ($auto_badges as $badge_slug => $criteria) {
            if ($user_points >= $criteria['points'] && !in_array($badge_slug, $user_badges)) {
                $this->award_badge($user_id, $badge_slug);
            }
        }
    }
    
    // =====================================================================
    // SECTION 2: ADMIN PANEL & SETUP
    // =====================================================================

    public function add_admin_menu() {
        // The main page is now a submenu of the main system.
        add_submenu_page('psych-system', 'مرکز گیمیفیکیشن', 'گیمیفیکیشن', 'manage_options', $this->admin_page_slug, [$this, 'render_admin_page']);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        ?>
        <div class="wrap psych-admin-wrap">
            <h1><i class="dashicons-before dashicons-star-filled"></i> مرکز گیمیفیکیشن</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=overview" class="nav-tab <?php echo $active_tab == 'overview' ? 'nav-tab-active' : ''; ?>">نمای کلی</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">تنظیمات عمومی</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=levels" class="nav-tab <?php echo $active_tab == 'levels' ? 'nav-tab-active' : ''; ?>">سطوح</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=badges" class="nav-tab <?php echo $active_tab == 'badges' ? 'nav-tab-active' : ''; ?>">نشان‌ها</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=sms_templates" class="nav-tab <?php echo $active_tab == 'sms_templates' ? 'nav-tab-active' : ''; ?>">قالب‌های پیامک</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=manual_award" class="nav-tab <?php echo $active_tab == 'manual_award' ? 'nav-tab-active' : ''; ?>">اعطای دستی</a>
                <a href="?page=<?php echo $this->admin_page_slug; ?>&tab=stats" class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">آمار</a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'levels':
                        $this->render_levels_page();
                        break;
                    case 'badges':
                        $this->render_badges_page();
                        break;
                    case 'sms_templates':
                        $this->render_sms_templates_page();
                        break;
                    case 'manual_award':
                        $this->render_manual_award_page();
                        break;
                    case 'stats':
                        $this->render_stats_tab();
                        break;
                    case 'overview':
                    default:
                        $this->render_overview_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_overview_tab() {
        $total_users = count_users()['total_users'];
        $active_badges = count($this->get_badges());
        $total_points_awarded = $this->get_total_points_awarded();
        ?>
        <div class="psych-dashboard-cards">
            <div class="psych-card">
                <div class="psych-card-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <div class="psych-card-content">
                    <h3><?php echo number_format_i18n($total_users); ?></h3>
                    <p>کل کاربران</p>
                </div>
            </div>
            
            <div class="psych-card">
                <div class="psych-card-icon">
                    <i class="dashicons dashicons-awards"></i>
                </div>
                <div class="psych-card-content">
                    <h3><?php echo number_format_i18n($active_badges); ?></h3>
                    <p>نشان‌های فعال</p>
                </div>
            </div>
            
            <div class="psych-card">
                <div class="psych-card-icon">
                    <i class="dashicons dashicons-star-filled"></i>
                </div>
                <div class="psych-card-content">
                    <h3><?php echo number_format_i18n($total_points_awarded); ?></h3>
                    <p>کل امتیازات اعطا شده</p>
                </div>
            </div>
        </div>

        <div class="psych-recent-activities">
            <h2>فعالیت‌های اخیر</h2>
            <?php $this->render_recent_activities(); ?>
        </div>
        <?php
    }

    private function render_settings_tab() {
        if (isset($_POST['submit'])) {
            check_admin_referer('psych_gamification_settings');
            $settings = [
                'points_per_login' => intval($_POST['points_per_login']),
                'points_per_post' => intval($_POST['points_per_post']),
                'points_per_comment' => intval($_POST['points_per_comment']),
                'enable_notifications' => isset($_POST['enable_notifications']),
                'sms_enabled' => isset($_POST['sms_enabled']),
                'sms_api_key' => sanitize_text_field($_POST['sms_api_key']),
                'sms_sender' => sanitize_text_field($_POST['sms_sender']),
            ];
            update_option(self::SETTINGS_OPTION_KEY, $settings);
            echo '<div class="notice notice-success"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        }

        $settings = $this->get_settings();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('psych_gamification_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">امتیاز ورود روزانه</th>
                    <td><input type="number" name="points_per_login" value="<?php echo esc_attr($settings['points_per_login']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">امتیاز انتشار مطلب</th>
                    <td><input type="number" name="points_per_post" value="<?php echo esc_attr($settings['points_per_post']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">امتیاز ثبت نظر</th>
                    <td><input type="number" name="points_per_comment" value="<?php echo esc_attr($settings['points_per_comment']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row">فعال‌سازی نوتیفیکیشن</th>
                    <td><input type="checkbox" name="enable_notifications" <?php checked($settings['enable_notifications']); ?> /></td>
                </tr>
                <tr>
                    <th scope="row">فعال‌سازی پیامک</th>
                    <td><input type="checkbox" name="sms_enabled" <?php checked($settings['sms_enabled']); ?> /></td>
                </tr>
                <tr>
                    <th scope="row">کلید API پیامک</th>
                    <td><input type="text" name="sms_api_key" value="<?php echo esc_attr($settings['sms_api_key']); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">شماره فرستنده</th>
                    <td><input type="text" name="sms_sender" value="<?php echo esc_attr($settings['sms_sender']); ?>" class="regular-text" /></td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_stats_tab() {
        $top_users = $this->get_top_users_by_points(10);
        $badge_stats = $this->get_badge_statistics();
        ?>
        <div class="psych-stats-container">
            <div class="psych-stats-section">
                <h2>کاربران برتر</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>رتبه</th>
                            <th>کاربر</th>
                            <th>امتیاز</th>
                            <th>سطح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_users as $index => $user_data): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo esc_html($user_data['display_name']); ?></td>
                            <td><?php echo number_format_i18n($user_data['points']); ?></td>
                            <td><?php echo esc_html($user_data['level']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="psych-stats-section">
                <h2>آمار نشان‌ها</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>نشان</th>
                            <th>تعداد اعطا شده</th>
                            <th>درصد کاربران</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($badge_stats as $badge): ?>
                        <tr>
                            <td><?php echo esc_html($badge['name']); ?></td>
                            <td><?php echo number_format_i18n($badge['awarded_count']); ?></td>
                            <td><?php echo number_format($badge['percentage'], 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // =====================================================================
    // SECTION 3: CORE FUNCTIONALITY
    // =====================================================================

    /**
     * Add points to a user
     */
    public function add_points($user_id, $points, $reason = 'کسب امتیاز', $custom_notification = null) {
        if (!$user_id || $points == 0) return false;
        
        $current_points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $new_total = $current_points + $points;
        update_user_meta($user_id, 'psych_total_points', $new_total);
        
        $this->log_points_transaction($user_id, $points, $reason);
        
        if ($points > 0) {
            $this->check_level_up($user_id, $new_total);
            $notification_title = 'امتیاز جدید!';
            $notification_message = $custom_notification ?: "شما {$points} امتیاز کسب کردید: {$reason}";
            $this->queue_notification($user_id, $notification_title, $notification_message);
        }
        
        do_action('psych_points_awarded', $user_id, $points, $reason, $new_total);
        return true;
    }

    /**
     * Award a badge to a user
     */
    public function award_badge($user_id, $badge_slug, $custom_notification = null) {
        if (!$user_id || empty($badge_slug)) return false;
        
        $user_badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];
        if (in_array($badge_slug, $user_badges)) {
            return false; // User already has this badge
        }
        
        $badges = $this->get_badges();
        if (!isset($badges[$badge_slug])) {
            return false; // Badge doesn't exist
        }
        
        $user_badges[] = $badge_slug;
        update_user_meta($user_id, 'psych_user_badges', $user_badges);
        
        $this->log_badge_award($user_id, $badge_slug);
        
        $badge_name = $badges[$badge_slug]['name'];
        $message = $custom_notification ?: "تبریک! شما نشان '{$badge_name}' را کسب کردید.";
        $this->queue_notification($user_id, 'نشان جدید!', $message);
        
        do_action('psych_user_earned_badge', $user_id, $badge_slug);
        return true;
    }

    /**
     * Check if user has a specific badge
     */
    public function user_has_badge($user_id, $badge_slug) {
        $user_badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];
        return in_array($badge_slug, $user_badges);
    }

    /**
     * Get badge name by slug
     */
    public function get_badge_name($badge_slug) {
        $badges = $this->get_badges();
        return isset($badges[$badge_slug]) ? $badges[$badge_slug]['name'] : 'نامشخص';
    }

    /**
     * Get user level information
     */
    public function get_user_level($user_id) {
        $points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $levels = $this->get_levels();
        
        $current_level = ['name' => 'تازه‌کار', 'icon' => 'fa-seedling', 'color' => '#95a5a6'];
        
        foreach ($levels as $level) {
            if ($points >= $level['required_points']) {
                $current_level = $level;
            } else {
                break;
            }
        }
        
        return $current_level;
    }

    /**
     * Get comprehensive user level info (for interactive content)
     */
    public function get_user_level_info($user_id) {
        $points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $levels = $this->get_levels();
        $current_level = $this->get_user_level($user_id);
        
        // Find next level
        $next_level = null;
        foreach ($levels as $level) {
            if ($level['required_points'] > $points) {
                $next_level = $level;
                break;
            }
        }
        
        $points_to_next = $next_level ? ($next_level['required_points'] - $points) : 0;
        $progress_percentage = 0;
        
        if ($next_level) {
            $prev_level_points = 0;
            foreach ($levels as $level) {
                if ($level['required_points'] <= $points) {
                    $prev_level_points = $level['required_points'];
                }
            }
            
            $level_range = $next_level['required_points'] - $prev_level_points;
            $user_progress = $points - $prev_level_points;
            $progress_percentage = $level_range > 0 ? ($user_progress / $level_range) * 100 : 100;
        }
        
        return [
            'name' => $current_level['name'],
            'icon' => $current_level['icon'],
            'color' => $current_level['color'],
            'current_points' => $points,
            'points_to_next' => $points_to_next,
            'progress_percentage' => min(100, $progress_percentage)
        ];
    }

    /**
     * Get user badges progress (for interactive content)
     */
    public function get_user_badges_progress($user_id) {
        $all_badges = $this->get_badges();
        $user_badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];
        $badges_progress = [];
        
        foreach ($all_badges as $slug => $badge) {
            $badges_progress[] = [
                'slug' => $slug,
                'name' => $badge['name'],
                'description' => $badge['description'] ?? '',
                'icon' => $badge['icon'] ?? 'fa-trophy',
                'color' => $badge['color'] ?? '#FFD700',
                'earned' => in_array($slug, $user_badges),
                'progress' => in_array($slug, $user_badges) ? 100 : 0
            ];
        }
        
        return $badges_progress;
    }

    /**
     * Check for level up
     */
    private function check_level_up($user_id, $new_points) {
        $levels = $this->get_levels();
        $old_level = get_user_meta($user_id, 'psych_user_level', true);
        
        $new_level = null;
        foreach ($levels as $level) {
            if ($new_points >= $level['required_points']) {
                $new_level = $level;
            }
        }
        
        if ($new_level && (!$old_level || $new_level['required_points'] > ($old_level['required_points'] ?? 0))) {
            update_user_meta($user_id, 'psych_user_level', $new_level);
            $this->queue_notification($user_id, 'سطح جدید!', "تبریک! شما به سطح '{$new_level['name']}' رسیدید.");
            do_action('psych_user_level_up', $user_id, $new_level, $old_level);
        }
    }

    /**
     * Queue notification for user
     */
    public function queue_notification($user_id, $title, $message, $type = 'info', $data = []) {
        $notifications = get_user_meta($user_id, 'psych_pending_notifications', true) ?: [];
        $notifications[] = [
            'id' => uniqid(),
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => $data,
            'timestamp' => current_time('timestamp')
        ];
        
        // Keep only last 10 notifications
        $notifications = array_slice($notifications, -10);
        update_user_meta($user_id, 'psych_pending_notifications', $notifications);
    }

    /**
     * Send SMS by template
     */
    public function send_sms_by_template($user_id, $template, $vars = []) {
        $settings = $this->get_settings();
        if (!$settings['sms_enabled'] || empty($settings['sms_api_key'])) {
            return false;
        }
        
        $user = get_userdata($user_id);
        if (!$user) return false;
        
        $phone = get_user_meta($user_id, 'phone', true);
        if (empty($phone)) return false;
        
        $default_templates = [
            'badge_earned' => 'تبریک {user_name}! شما نشان {badge_name} را کسب کردید.',
            'level_up' => 'تبریک {user_name}! شما به سطح {level_name} رسیدید.',
            'points_awarded' => '{user_name} عزیز، شما {points} امتیاز کسب کردید!'
        ];
        
        $custom_templates = get_option('psych_gamification_sms_templates', $default_templates);
        
        if (!isset($custom_templates[$template]) || empty($custom_templates[$template])) {
            // Fallback to default if custom template is not set or is empty
            if (!isset($default_templates[$template])) return false;
            $message = $default_templates[$template];
        } else {
            $message = $custom_templates[$template];
        }
        $default_vars = [
            'user_name' => $user->display_name,
            'site_name' => get_bloginfo('name')
        ];
        
        $vars = array_merge($default_vars, $vars);
        foreach ($vars as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }
        
        // Here you would integrate with your SMS provider
        // For now, we'll just log it
        error_log("SMS to {$phone}: {$message}");
        
        return true;
    }

    // =====================================================================
    // SECTION 4: DATA MANAGEMENT
    // =====================================================================

    /**
     * Get all levels
     */
    public function get_levels() {
        return get_option(self::LEVELS_OPTION_KEY, $this->get_default_levels());
    }

    /**
     * Get all badges
     */
    public function get_badges() {
        return get_option(self::BADGES_OPTION_KEY, $this->get_default_badges());
    }

    /**
     * Get settings
     */
    public function get_settings() {
        return wp_parse_args(get_option(self::SETTINGS_OPTION_KEY, []), [
            'points_per_login' => 5,
            'points_per_post' => 20,
            'points_per_comment' => 5,
            'enable_notifications' => true,
            'sms_enabled' => false,
            'sms_api_key' => '',
            'sms_sender' => ''
        ]);
    }

    /**
     * Get default levels
     */
    private function get_default_levels() {
        return [
            [
                'name' => 'تازه‌کار',
                'required_points' => 0,
                'icon' => 'fa-seedling',
                'color' => '#95a5a6'
            ],
            [
                'name' => 'فراگیر',
                'required_points' => 100,
                'icon' => 'fa-user-graduate',
                'color' => '#3498db'
            ],
            [
                'name' => 'ماهر',
                'required_points' => 500,
                'icon' => 'fa-medal',
                'color' => '#f39c12'
            ],
            [
                'name' => 'خبره',
                'required_points' => 1000,
                'icon' => 'fa-crown',
                'color' => '#e74c3c'
            ],
            [
                'name' => 'استاد',
                'required_points' => 2500,
                'icon' => 'fa-trophy',
                'color' => '#9b59b6'
            ]
        ];
    }

    /**
     * Get default badges
     */
    private function get_default_badges() {
        return [
            'first_steps' => [
                'name' => 'نخستین گام‌ها',
                'description' => 'اولین ۵۰ امتیاز را کسب کنید',
                'icon' => 'fa-baby',
                'color' => '#2ecc71'
            ],
            'dedicated_learner' => [
                'name' => 'فراگیر مجاهد',
                'description' => '۲۰۰ امتیاز کسب کنید',
                'icon' => 'fa-book-reader',
                'color' => '#3498db'
            ],
            'point_collector' => [
                'name' => 'جمع‌آور امتیاز',
                'description' => '۵۰۰ امتیاز کسب کنید',
                'icon' => 'fa-coins',
                'color' => '#f39c12'
            ],
            'achievement_hunter' => [
                'name' => 'شکارچی موفقیت',
                'description' => '۱۰۰۰ امتیاز کسب کنید',
                'icon' => 'fa-crosshairs',
                'color' => '#e74c3c'
            ]
        ];
    }

    // =====================================================================
    // SECTION 5: SHORTCODES
    // =====================================================================

    /**
     * Render user points shortcode
     */
    public function render_user_points_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'show_label' => 'true'
        ], $atts);
        
        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];
        
        if (!$user_id) return '';
        
        $points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $label = $atts['show_label'] === 'true' ? 'امتیاز: ' : '';
        
        return sprintf('<span class="psych-user-points">%s%s</span>', $label, number_format_i18n($points));
    }

    /**
     * Render user level shortcode
     */
    public function render_user_level_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'show_icon' => 'true'
        ], $atts);
        
        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];
        
        if (!$user_id) return '';
        
        $level = $this->get_user_level($user_id);
        $icon = $atts['show_icon'] === 'true' ? '<i class="' . esc_attr($level['icon']) . '"></i> ' : '';
        
        return sprintf('<span class="psych-user-level" style="color: %s;">%s%s</span>', 
                      esc_attr($level['color']), $icon, esc_html($level['name']));
    }

    /**
     * Render user badges shortcode
     */
    public function render_user_badges_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'limit' => 5
        ], $atts);
        
        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];
        
        if (!$user_id) return '';
        
        $user_badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];
        $all_badges = $this->get_badges();
        $limit = intval($atts['limit']);
        
        if (empty($user_badges)) {
            return '<p class="psych-no-badges">هنوز نشانی کسب نشده است.</p>';
        }
        
        $output = '<div class="psych-user-badges-list">';
        $count = 0;
        
        foreach ($user_badges as $badge_slug) {
            if ($limit > 0 && $count >= $limit) break;
            
            if (isset($all_badges[$badge_slug])) {
                $badge = $all_badges[$badge_slug];
                $output .= sprintf(
                    '<span class="psych-badge" style="color: %s;" title="%s">
                        <i class="%s"></i> %s
                    </span>',
                    esc_attr($badge['color']),
                    esc_attr($badge['description'] ?? ''),
                    esc_attr($badge['icon']),
                    esc_html($badge['name'])
                );
                $count++;
            }
        }
        
        $output .= '</div>';
        return $output;
    }

    /**
     * Render leaderboard shortcode
     */
    public function render_leaderboard_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 10,
            'show_current_user' => 'true'
        ], $atts);
        
        $limit = intval($atts['limit']);
        $top_users = $this->get_top_users_by_points($limit);
        $context = $this->get_viewing_context();
        $current_user_id = $context['viewed_user_id'];
        
        if (empty($top_users)) {
            return '<p>هنوز آماری موجود نیست.</p>';
        }
        
        $output = '<div class="psych-leaderboard">';
        $output .= '<h3>جدول امتیازات</h3>';
        $output .= '<ol class="psych-leaderboard-list">';
        
        foreach ($top_users as $user_data) {
            $is_current = ($user_data['ID'] == $current_user_id);
            $css_class = $is_current ? ' class="current-user"' : '';
            
            $output .= sprintf(
                '<li%s>
                    <span class="user-name">%s</span>
                    <span class="user-points">%s امتیاز</span>
                    <span class="user-level">%s</span>
                </li>',
                $css_class,
                esc_html($user_data['display_name']),
                number_format_i18n($user_data['points']),
                esc_html($user_data['level'])
            );
        }
        
        $output .= '</ol></div>';
        return $output;
    }

    // =====================================================================
    // SECTION 6: AJAX HANDLERS
    // =====================================================================

    /**
     * Get pending notifications via AJAX
     */
    public function ajax_get_pending_notifications() {
        if (!check_ajax_referer('psych_gamification_ajax', 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست شما منقضی شده است.'], 403);
        }
        
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'کاربر نامعتبر.'], 401);
        }
        
        $notifications = get_user_meta($user_id, 'psych_pending_notifications', true) ?: [];
        wp_send_json_success(['notifications' => $notifications]);
    }

    /**
     * Clear notification via AJAX
     */
    public function ajax_clear_notification() {
        if (!check_ajax_referer('psych_gamification_ajax', 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست شما منقضی شده است.'], 403);
        }
        
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];
        $notification_id = sanitize_text_field($_POST['notification_id'] ?? '');
        
        if (!$user_id || empty($notification_id)) {
            wp_send_json_error(['message' => 'پارامترهای نامعتبر.']);
        }
        
        $notifications = get_user_meta($user_id, 'psych_pending_notifications', true) ?: [];
        $notifications = array_filter($notifications, function($notif) use ($notification_id) {
            return $notif['id'] !== $notification_id;
        });
        
        update_user_meta($user_id, 'psych_pending_notifications', array_values($notifications));
        wp_send_json_success(['message' => 'نوتیفیکیشن حذف شد.']);
    }

    /**
     * Manual award handler
     */
    public function handle_manual_award_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }
        
        if (!check_ajax_referer('psych_manual_award', 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست منقضی شده.'], 403);
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        $award_type = sanitize_key($_POST['award_type'] ?? '');
        $award_value = sanitize_text_field($_POST['award_value'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? 'اعطای دستی');
        
        if (!$user_id || !$award_type || !$award_value) {
            wp_send_json_error(['message' => 'پارامترهای ناقص.']);
        }
        
        $success = false;
        $message = '';
        
        switch ($award_type) {
            case 'points':
                $points = intval($award_value);
                if ($points > 0) {
                    $this->add_points($user_id, $points, $reason);
                    $success = true;
                    $message = "{$points} امتیاز با موفقیت اعطا شد.";
                }
                break;
                
            case 'badge':
                if ($this->award_badge($user_id, $award_value)) {
                    $success = true;
                    $message = "نشان با موفقیت اعطا شد.";
                } else {
                    $message = "کاربر این نشان را قبلاً دارد یا نشان وجود ندارد.";
                }
                break;
        }
        
        if ($success) {
            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => $message ?: 'خطا در اعطای جایزه.']);
        }
    }

    // =====================================================================
    // SECTION 7: UTILITY METHODS
    // =====================================================================

    /**
     * Get top users by points
     */
    public function get_top_users_by_points($limit = 10) {
    global $wpdb;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT u.ID, u.display_name, um.meta_value as points
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
         WHERE um.meta_key = 'psych_total_points'
         ORDER BY CAST(um.meta_value AS UNSIGNED) DESC
         LIMIT %d",
        $limit
    ));

    $users = [];
    foreach ($results as $result) {
        $level = $this->get_user_level($result->ID);
        $users[] = [
            'ID' => $result->ID,
            'display_name' => $result->display_name,
            'points' => intval($result->points),
            'level' => $level['name']
        ];
    }

    return $users;
}

    /**
     * Get badge statistics
     */
    public function get_badge_statistics() {
        global $wpdb;
        $total_users = count_users()['total_users'];
        $badges = $this->get_badges();
        $stats = [];
        
        foreach ($badges as $slug => $badge) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} 
                 WHERE meta_key = 'psych_user_badges' 
                 AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($slug) . '%'
            ));
            
            $stats[] = [
                'slug' => $slug,
                'name' => $badge['name'],
                'awarded_count' => intval($count),
                'percentage' => $total_users > 0 ? ($count / $total_users) * 100 : 0
            ];
        }
        
        return $stats;
    }

    /**
     * Get total points awarded
     */
    public function get_total_points_awarded() {
        global $wpdb;
        
        $total = $wpdb->get_var(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) 
             FROM {$wpdb->usermeta} 
             WHERE meta_key = 'psych_total_points'"
        );
        
        return intval($total);
    }

    /**
     * Log points transaction
     */
    public function log_points_transaction($user_id, $points, $reason) {
        $log = get_option('psych_points_log', []);
        $log[] = [
            'user_id' => $user_id,
            'points' => $points,
            'reason' => $reason,
            'timestamp' => current_time('mysql')
        ];
        
        // Keep only last 1000 entries
        $log = array_slice($log, -1000);
        update_option('psych_points_log', $log);
    }

    /**
     * Log badge award
     */
    public function log_badge_award($user_id, $badge_slug) {
        $log = get_option('psych_badge_log', []);
        $log[] = [
            'user_id' => $user_id,
            'badge_slug' => $badge_slug,
            'timestamp' => current_time('mysql')
        ];
        
        // Keep only last 1000 entries
        $log = array_slice($log, -1000);
        update_option('psych_badge_log', $log);
    }

    /**
     * Render recent activities
     */
    private function render_recent_activities() {
        $points_log = array_slice(get_option('psych_points_log', []), -10);
        $badge_log = array_slice(get_option('psych_badge_log', []), -10);
        
        // Merge and sort by timestamp
        $activities = [];
        
        foreach ($points_log as $entry) {
            $user = get_userdata($entry['user_id']);
            if ($user) {
                $activities[] = [
                    'type' => 'points',
                    'user' => $user->display_name,
                    'description' => "کسب {$entry['points']} امتیاز: {$entry['reason']}",
                    'timestamp' => $entry['timestamp']
                ];
            }
        }
        
        foreach ($badge_log as $entry) {
            $user = get_userdata($entry['user_id']);
            $badge_name = $this->get_badge_name($entry['badge_slug']);
            if ($user) {
                $activities[] = [
                    'type' => 'badge',
                    'user' => $user->display_name,
                    'description' => "کسب نشان: {$badge_name}",
                    'timestamp' => $entry['timestamp']
                ];
            }
        }
        
        // Sort by timestamp
        usort($activities, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        $activities = array_slice($activities, 0, 15);
        
        if (empty($activities)) {
            echo '<p>هنوز فعالیتی ثبت نشده است.</p>';
            return;
        }
        
        echo '<ul class="psych-activities-list">';
        foreach ($activities as $activity) {
            $icon = $activity['type'] === 'points' ? 'fa-star' : 'fa-trophy';
            printf(
                '<li><i class="fas %s"></i> <strong>%s:</strong> %s <small>(%s)</small></li>',
                $icon,
                esc_html($activity['user']),
                esc_html($activity['description']),
                human_time_diff(strtotime($activity['timestamp']))
            );
        }
        echo '</ul>';
    }

    // =====================================================================
    // SECTION 8: ASSETS & FRONTEND
    // =====================================================================

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, $this->admin_page_slug) === false) return;
        
        wp_enqueue_style(
            'psych-gamification-admin',
            plugin_dir_url(__FILE__) . 'assets/gamification-admin.css',
            [],
            self::VERSION
        );
        
        wp_enqueue_script(
            'psych-gamification-admin',
            plugin_dir_url(__FILE__) . 'assets/gamification-admin.js',
            ['jquery'],
            self::VERSION,
            true
        );
        
        wp_localize_script('psych-gamification-admin', 'psych_gamification_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('psych_manual_award')
        ]);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        if (!is_user_logged_in()) return;
        
        wp_enqueue_style(
            'psych-gamification-frontend',
            plugin_dir_url(__FILE__) . 'assets/gamification-frontend.css',
            [],
            self::VERSION
        );
        
        wp_enqueue_script(
            'psych-gamification-frontend',
            plugin_dir_url(__FILE__) . 'assets/gamification-frontend.js',
            ['jquery'],
            self::VERSION,
            true
        );
        
        wp_localize_script('psych-gamification-frontend', 'psych_gamification', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('psych_gamification_ajax'),
            'viewing_context' => $this->get_viewing_context()
        ]);
    }

    /**
     * Render footer elements
     */
    public function render_footer_elements() {
        if (!is_user_logged_in()) return;
        
        $context = $this->get_viewing_context();
        if ($context['is_impersonating']) return; // Don't show notifications when impersonating
        
        ?>
        <div id="psych-notification-container" style="display: none;">
            <div id="psych-notification-popup">
                <button id="psych-notification-close">&times;</button>
                <div id="psych-notification-content"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('psych_gamification_settings', self::SETTINGS_OPTION_KEY);
        register_setting('psych_gamification_levels', self::LEVELS_OPTION_KEY);
        register_setting('psych_gamification_badges', self::BADGES_OPTION_KEY);
    }

    // =====================================================================
    // SECTION 9: REWARD HANDLERS (Path Engine Integration)
    // =====================================================================

    /**
     * Generate coupon reward
     */
    public function reward_generate_coupon($user_id, $coupon_data, $context) {
        $coupon_code = 'PSYCH_' . strtoupper(wp_generate_password(8, false));
        
        // Store coupon data
        $user_coupons = get_user_meta($user_id, 'psych_user_coupons', true) ?: [];
        $user_coupons[] = [
            'code' => $coupon_code,
            'discount' => $coupon_data['discount'] ?? 10,
            'type' => $coupon_data['type'] ?? 'percent',
            'expires' => date('Y-m-d', strtotime('+30 days')),
            'used' => false,
            'created' => current_time('mysql')
        ];
        
        update_user_meta($user_id, 'psych_user_coupons', $user_coupons);
        
        // Queue notification
        $this->queue_notification(
            $user_id, 
            'کد تخفیف جدید!', 
            "کد تخفیف شما: {$coupon_code} - {$coupon_data['discount']}% تخفیف"
        );
    }

    /**
     * Unlock product reward
     */
    public function reward_unlock_product($user_id, $product_data, $context) {
        $unlocked_products = get_user_meta($user_id, 'psych_unlocked_products', true) ?: [];
        
        if (!in_array($product_data['product_id'], $unlocked_products)) {
            $unlocked_products[] = $product_data['product_id'];
            update_user_meta($user_id, 'psych_unlocked_products', $unlocked_products);
            
            $product_name = $product_data['name'] ?? 'محصول خاص';
            $this->queue_notification(
                $user_id,
                'محصول باز شد!',
                "تبریک! دسترسی به '{$product_name}' برای شما فعال شد."
            );
        }
    }

    /**
     * Send file reward
     */
    public function reward_send_file($user_id, $file_data, $context) {
        $user_files = get_user_meta($user_id, 'psych_reward_files', true) ?: [];
        $user_files[] = [
            'file_id' => $file_data['file_id'],
            'file_name' => $file_data['name'] ?? 'فایل پاداش',
            'file_url' => $file_data['url'] ?? '',
            'received_date' => current_time('mysql')
        ];
        
        update_user_meta($user_id, 'psych_reward_files', $user_files);
        
        $this->queue_notification(
            $user_id,
            'فایل پاداش جدید!',
            "فایل '{$file_data['name']}' برای شما ارسال شد."
        );
    }

    /**
     * Send SMS reward
     */
    public function reward_send_sms($user_id, $sms_data, $context) {
        $template = $sms_data['template'] ?? 'custom';
        $message = $sms_data['message'] ?? 'تبریک! شما پاداش جدیدی کسب کردید.';
        
        if ($template !== 'custom') {
            $this->send_sms_by_template($user_id, $template, $sms_data['vars'] ?? []);
        } else {
            // Send custom message
            $user = get_userdata($user_id);
            $phone = get_user_meta($user_id, 'phone', true);
            
            if ($user && $phone) {
                // Replace variables in custom message
                $message = str_replace(['{user_name}', '{site_name}'], 
                                     [$user->display_name, get_bloginfo('name')], 
                                     $message);
                
                // Log SMS (in real implementation, send via SMS service)
                error_log("SMS to {$phone}: {$message}");
            }
        }
    }

    /**
     * Handle badge earned automation (SMS, etc.)
     */
    public function handle_badge_earned_automation($user_id, $badge_slug) {
        $settings = $this->get_settings();
        
        if ($settings['sms_enabled']) {
            $badge_name = $this->get_badge_name($badge_slug);
            $this->send_sms_by_template($user_id, 'badge_earned', [
                'badge_name' => $badge_name
            ]);
        }
        
        // Coach notification if student earns badge
        if (function_exists('psych_get_user_coach')) {
            $coach_id = psych_get_user_coach($user_id);
            if ($coach_id) {
                $student_data = get_userdata($user_id);
                $badge_name = $this->get_badge_name($badge_slug);
                
                $this->queue_notification(
                    $coach_id,
                    'دانشجو نشان کسب کرد',
                    $student_data->display_name . " نشان '{$badge_name}' را کسب کرد"
                );
            }
        }
    }

    // =====================================================================
    // SECTION 10: ADDITIONAL ADMIN PAGES
    // =====================================================================

    /**
     * Render SMS Templates management page
     */
    public function render_sms_templates_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $option_key = 'psych_gamification_sms_templates';
        $default_templates = [
            'badge_earned' => 'تبریک {user_name}! شما نشان {badge_name} را کسب کردید.',
            'level_up' => 'تبریک {user_name}! شما به سطح {level_name} رسیدید.',
            'points_awarded' => '{user_name} عزیز، شما {points} امتیاز کسب کردید!'
        ];

        if (isset($_POST['submit']) && check_admin_referer('psych_save_sms_templates')) {
            $saved_templates = [];
            foreach ($default_templates as $key => $default_text) {
                if (isset($_POST[$key])) {
                    $saved_templates[$key] = sanitize_textarea_field($_POST[$key]);
                }
            }
            update_option($option_key, $saved_templates);
            echo '<div class="notice notice-success"><p>قالب‌ها با موفقیت ذخیره شدند.</p></div>';
        }

        $current_templates = get_option($option_key, $default_templates);
        ?>
        <div class="wrap">
            <h1>قالب‌های پیامک</h1>
            <p>در این بخش می‌توانید متن پیامک‌های خودکار را ویرایش کنید. از متغیرهای موجود برای شخصی‌سازی استفاده کنید.</p>
            <p>متغیرهای موجود: <code>{user_name}</code>, <code>{site_name}</code>, <code>{badge_name}</code>, <code>{level_name}</code>, <code>{points}</code></p>

            <form method="post" action="">
                <?php wp_nonce_field('psych_save_sms_templates'); ?>
                <table class="form-table">
                    <?php foreach ($default_templates as $key => $default_text): ?>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></label>
                        </th>
                        <td>
                            <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="4" class="large-text"><?php echo esc_textarea($current_templates[$key] ?? $default_text); ?></textarea>
                            <p class="description">پیش‌فرض: <?php echo esc_html($default_text); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('ذخیره قالب‌ها'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render levels management page
     */
    public function render_levels_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle form submission
        if (isset($_POST['save_levels'])) {
            check_admin_referer('psych_save_levels');
            
            $levels = [];
            if (isset($_POST['levels']) && is_array($_POST['levels'])) {
                foreach ($_POST['levels'] as $level_data) {
                    $levels[] = [
                        'name' => sanitize_text_field($level_data['name']),
                        'required_points' => intval($level_data['required_points']),
                        'icon' => sanitize_text_field($level_data['icon']),
                        'color' => sanitize_hex_color($level_data['color'])
                    ];
                }
                
                // Sort by required points
                usort($levels, function($a, $b) {
                    return $a['required_points'] - $b['required_points'];
                });
            }
            
            update_option(self::LEVELS_OPTION_KEY, $levels);
            echo '<div class="notice notice-success"><p>سطوح با موفقیت ذخیره شد.</p></div>';
        }
        
        $levels = $this->get_levels();
        ?>
        <div class="wrap">
            <h1>مدیریت سطوح</h1>
            <form method="post" action="">
                <?php wp_nonce_field('psych_save_levels'); ?>
                
                <div id="levels-container">
                    <?php foreach ($levels as $index => $level): ?>
                    <div class="level-row">
                        <input type="text" name="levels[<?php echo $index; ?>][name]" 
                               value="<?php echo esc_attr($level['name']); ?>" placeholder="نام سطح" />
                        <input type="number" name="levels[<?php echo $index; ?>][required_points]" 
                               value="<?php echo esc_attr($level['required_points']); ?>" placeholder="امتیاز مورد نیاز" />
                        <input type="text" name="levels[<?php echo $index; ?>][icon]" 
                               value="<?php echo esc_attr($level['icon']); ?>" placeholder="کلاس آیکون" />
                        <input type="color" name="levels[<?php echo $index; ?>][color]" 
                               value="<?php echo esc_attr($level['color']); ?>" />
                        <button type="button" class="button remove-level">حذف</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <p>
                    <button type="button" id="add-level" class="button">افزودن سطح</button>
                    <button type="submit" name="save_levels" class="button-primary">ذخیره سطوح</button>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var levelIndex = <?php echo count($levels); ?>;
            
            $("#add-level").click(function() {
                var html = '<div class="level-row">' +
                    '<input type="text" name="levels[' + levelIndex + '][name]" placeholder="نام سطح" />' +
                    '<input type="number" name="levels[' + levelIndex + '][required_points]" placeholder="امتیاز مورد نیاز" />' +
                    '<input type="text" name="levels[' + levelIndex + '][icon]" placeholder="کلاس آیکون" />' +
                    '<input type="color" name="levels[' + levelIndex + '][color]" value="#3498db" />' +
                    '<button type="button" class="button remove-level">حذف</button>' +
                    '</div>';
                
                $("#levels-container").append(html);
                levelIndex++;
            });
            
            $(document).on("click", ".remove-level", function() {
                $(this).closest(".level-row").remove();
            });
        });
        </script>
        
        <?php
    }

    /**
     * Render badges management page
     */
    public function render_badges_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle form submission
        if (isset($_POST['save_badges'])) {
            check_admin_referer('psych_save_badges');
            
            $badges = [];
            if (isset($_POST['badges']) && is_array($_POST['badges'])) {
                foreach ($_POST['badges'] as $slug => $badge_data) {
                    $badges[sanitize_key($slug)] = [
                        'name' => sanitize_text_field($badge_data['name']),
                        'description' => sanitize_textarea_field($badge_data['description']),
                        'icon' => sanitize_text_field($badge_data['icon']),
                        'color' => sanitize_hex_color($badge_data['color'])
                    ];
                }
            }
            
            update_option(self::BADGES_OPTION_KEY, $badges);
            echo '<div class="notice notice-success"><p>نشان‌ها با موفقیت ذخیره شد.</p></div>';
        }
        
        $badges = $this->get_badges();
        ?>
        <div class="wrap">
            <h1>مدیریت نشان‌ها</h1>
            <form method="post" action="">
                <?php wp_nonce_field('psych_save_badges'); ?>
                
                <div id="badges-container">
                    <?php foreach ($badges as $slug => $badge): ?>
                    <div class="badge-row">
                        <input type="text" value="<?php echo esc_attr($slug); ?>" 
                               name="badge_slugs[]" placeholder="نامک نشان" readonly />
                        <input type="text" name="badges[<?php echo esc_attr($slug); ?>][name]" 
                               value="<?php echo esc_attr($badge['name']); ?>" placeholder="نام نشان" />
                        <textarea name="badges[<?php echo esc_attr($slug); ?>][description]" 
                                  placeholder="توضیحات"><?php echo esc_textarea($badge['description'] ?? ''); ?></textarea>
                        <input type="text" name="badges[<?php echo esc_attr($slug); ?>][icon]" 
                               value="<?php echo esc_attr($badge['icon']); ?>" placeholder="کلاس آیکون" />
                        <input type="color" name="badges[<?php echo esc_attr($slug); ?>][color]" 
                               value="<?php echo esc_attr($badge['color']); ?>" />
                        <button type="button" class="button remove-badge">حذف</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <p>
                    <button type="button" id="add-badge" class="button">افزودن نشان</button>
                    <button type="submit" name="save_badges" class="button-primary">ذخیره نشان‌ها</button>
                </p>
            </form>
        </div>
        
        <?php
    }

    /**
     * Render manual award page
     */
    public function render_manual_award_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>اعطای دستی پاداش</h1>
            
            <div class="manual-award-container">
                <form id="manual-award-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">جستجوی کاربر</th>
                            <td>
                                <input type="text" id="award_user_search" placeholder="نام کاربری یا ایمیل..." class="regular-text" />
                                <input type="hidden" id="award_user_id" name="user_id" />
                                <div id="user-search-results"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">نوع پاداش</th>
                            <td>
                                <select id="award_type" name="award_type">
                                    <option value="points">امتیاز</option>
                                    <option value="badge">نشان</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">مقدار</th>
                            <td>
                                <input type="text" id="award_value" name="award_value" placeholder="مقدار امتیاز یا نامک نشان" class="regular-text" />
                                <p class="description">برای امتیاز: عدد وارد کنید. برای نشان: نامک نشان را وارد کنید.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">دلیل</th>
                            <td>
                                <input type="text" id="award_reason" name="reason" placeholder="دلیل اعطای پاداش..." class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button-primary">اعطای پاداش</button>
                    </p>
                </form>
                
                <div class="badges-reference">
                    <h3>راهنمای نشان‌ها</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>نامک</th>
                                <th>نام</th>
                                <th>توضیحات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->get_badges() as $slug => $badge): ?>
                            <tr>
                                <td><code><?php echo esc_html($slug); ?></code></td>
                                <td><?php echo esc_html($badge['name']); ?></td>
                                <td><?php echo esc_html($badge['description'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <style>
        .manual-award-container {
            max-width: 800px;
        }
        .badges-reference {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        #user-search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-top: none;
            background: white;
            display: none;
        }
        .user-search-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .user-search-item:hover {
            background: #f5f5f5;
        }
        </style>
        <?php
    }
}

// Initialize the enhanced gamification center
if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Gamification_Center::get_instance();
    });
}

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create default data on activation
    if (!get_option(Psych_Gamification_Center::LEVELS_OPTION_KEY)) {
        $center = Psych_Gamification_Center::get_instance();
        update_option(Psych_Gamification_Center::LEVELS_OPTION_KEY, $center->get_levels());
        update_option(Psych_Gamification_Center::BADGES_OPTION_KEY, $center->get_badges());
    }
});

// Integration hooks for other modules
add_action('init', function() {
    // Register integration hooks after all plugins are loaded
    do_action('psych_gamification_loaded');
});

?>