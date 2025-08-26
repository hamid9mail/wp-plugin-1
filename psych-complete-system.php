<?php
/**
 * Plugin Name:       Psych Complete System - Enhanced Integration Edition
 * Plugin URI:        https://yourwebsite.com/psych-complete-system
 * Description:       سیستم جامع روان گستر برای مدیریت کاربران، مسیرهای یادگیری، گیمیفیکیشن و مربیگری با یکپارچگی کامل
 * Version:           6.1.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Enhanced Integration Team - Fixed by Gemini
 * Author URI:        https://yourwebsite.com
 * License:           GPL  or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       psych-system
 * Domain Path:       /languages
 * Network:           false
 *
 * این نسخه Enhanced شامل:
 * - هماهنگی کامل بین تمام ماژول‌ها
 * - پشتیبانی از Coach Impersonation
 * - سیستم کش پیشرفته
 * - API Functions یکپارچه
 * - رابط کاربری مدرن و responsive
 * - امنیت پیشرفته
 * - رفع مشکلات nonce و headers
 * - رفع مشکل ثبت شورتکدها
 * - مدیریت یکپارچه تمام ماژول‌ها
 * - عدم تداخل با شورتکدهای ماژول‌ها
 * - استفاده از شورتکدهای اصلی ماژول‌ها
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Prevent multiple initializations
if (defined('PSYCH_SYSTEM_LOADED')) {
    return;
}
define('PSYCH_SYSTEM_LOADED', true);

/**
 * Main Psych Complete System Class - Enhanced Integration Edition
 */
final class Psych_Complete_System_Enhanced {

    /**
     * Plugin version
     */
    const VERSION = '6.1.0';

    /**
     * Minimum PHP version required
     */
    const MIN_PHP_VERSION = '7.4';

    /**
     * Minimum WordPress version required
     */
    const MIN_WP_VERSION = '5.0';

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Array to store module instances
     */
    private $modules = [];

    /**
     * Plugin initialization status
     */
    private $initialized = false;

    /**
     * Error messages
     */
    private $errors = [];

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Private to enforce singleton
     */
    private function __construct() {
        $this->basic_requirements_check();

        if (!empty($this->errors)) {
            add_action('admin_notices', [$this, 'display_admin_errors']);
            return;
        }

        $this->define_constants();
        $this->init_hooks();
    }

    /**
     * Basic system requirements check (without WordPress functions)
     */
    private function basic_requirements_check() {
        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->errors[] = sprintf(
                'سیستم روان گستر نیاز به PHP نسخه %s یا بالاتر دارد. نسخه فعلی شما: %s',
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        // Check WordPress version
        global $wp_version;
        if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            $this->errors[] = sprintf(
                'سیستم روان گستر نیاز به وردپرس نسخه %s یا بالاتر دارد. نسخه فعلی شما: %s',
                self::MIN_WP_VERSION,
                $wp_version
            );
        }
    }

    /**
     * Complete requirements check (with WordPress functions)
     */
    public function complete_requirements_check() {
        // Check required functions after WordPress is fully loaded
        $required_functions = ['wp_create_nonce', 'wp_verify_nonce', 'current_user_can'];
        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                $this->errors[] = "تابع مورد نیاز $function موجود نیست.";
            }
        }

        if (!empty($this->errors)) {
            add_action('admin_notices', [$this, 'display_admin_errors']);
            return false;
        }

        return true;
    }

    /**
     * Define plugin constants
     */
    private function define_constants() {
        $this->define('PSYCH_SYSTEM_VERSION', self::VERSION);
        $this->define('PSYCH_SYSTEM_FILE', __FILE__);
        $this->define('PSYCH_SYSTEM_DIR', plugin_dir_path(__FILE__));
        $this->define('PSYCH_SYSTEM_URL', plugin_dir_url(__FILE__));
        $this->define('PSYCH_SYSTEM_BASENAME', plugin_basename(__FILE__));
        $this->define('PSYCH_SYSTEM_TEXT_DOMAIN', 'psych-system');
        $this->define('PSYCH_SYSTEM_CACHE_GROUP', 'psych_system');
        $this->define('PSYCH_SYSTEM_MIN_CACHE_TIME', 60);
        $this->define('PSYCH_SYSTEM_MAX_CACHE_TIME', 3600);
    }

    /**
     * Define constant if not already set
     */
    private function define($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Core initialization - Load modules on init to ensure WordPress is ready
        add_action('init', [$this, 'init_plugin'], 0);
        add_action('init', [$this, 'load_textdomain'], 1);

        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu'], 9);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_enqueue_scripts']);

        // AJAX hooks
        add_action('wp_ajax_psych_system_status', [$this, 'ajax_system_status']);
        add_action('wp_ajax_psych_clear_cache', [$this, 'ajax_clear_cache']);

        // Cleanup hooks
        add_action('psych_system_daily_cleanup', [$this, 'daily_cleanup']);

        // Integration hooks
        add_action('psych_system_loaded', [$this, 'after_system_loaded'], 999);
        
        // Fix for nested shortcode processing
        add_filter('psych_process_station_content', [$this, 'process_nested_shortcodes'], 10, 1);
    }

    /**
     * Initialize the plugin
     */
    public function init_plugin() {
        if ($this->initialized || !empty($this->errors)) {
            return;
        }

        $this->load_modules();
        $this->initialize_modules();
        $this->setup_global_functions();
        // DON'T setup utility shortcodes - let modules handle their own
        $this->initialized = true;

        do_action('psych_system_loaded', $this);
    }

    /**
     * Load textdomain for internationalization
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'psych-system',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Load all module files
     */
    private function load_modules() {
        $modules_to_load = [
            'gamification-center.php' => 'Psych_Gamification_Center',
            'coach-module.php' => 'Psych_Coach_Module',
            'path-engine.php' => 'PsychoCourse_Path_Engine',
            'interactive-content.php' => 'Psych_Interactive_Content_Module',
            'report-card.php' => 'Psych_Unified_Report_Card_Enhanced',
            'dashboard-display.php' => 'Psych_Dashboard_Display_Enhanced',
            'personalization-module.php' => 'Psych_Personalization_Module',
            'admin-dashboard-module.php' => 'Psych_Admin_Dashboard_Module'
        ];

        foreach ($modules_to_load as $file => $class) {
            $file_path = PSYCH_SYSTEM_DIR . $file;

            if (file_exists($file_path)) {
                // Prevent automatic initialization of individual modules
                if (!defined('PSYCH_PREVENT_AUTO_INIT')) {
                    define('PSYCH_PREVENT_AUTO_INIT', true);
                }
                
                require_once $file_path;

                if (!class_exists($class)) {
                    $this->errors[] = "کلاس $class در فایل $file یافت نشد.";
                }
            } else {
                $this->errors[] = "فایل ماژول $file یافت نشد.";
            }
        }

        if (!empty($this->errors)) {
            add_action('admin_notices', [$this, 'display_admin_errors']);
        }
    }

    /**
     * Initialize all modules in correct order
     */
    private function initialize_modules() {
        $initialization_order = [
            'gamification' => 'Psych_Gamification_Center',
            'coach' => 'Psych_Coach_Module',
            'path_engine' => 'PsychoCourse_Path_Engine',
            'interactive_content' => 'Psych_Interactive_Content_Module',
            'report_card' => 'Psych_Unified_Report_Card_Enhanced',
            'dashboard' => 'Psych_Dashboard_Display_Enhanced',
            'personalization' => 'Psych_Personalization_Module',
            'admin_dashboard' => 'Psych_Admin_Dashboard_Module'
        ];

        foreach ($initialization_order as $module_key => $class_name) {
            if (class_exists($class_name)) {
                try {
                    if (method_exists($class_name, 'get_instance')) {
                        $this->modules[$module_key] = $class_name::get_instance();
                    } else {
                        $this->modules[$module_key] = new $class_name();
                    }

                    // Call init method if it exists
                    if (method_exists($this->modules[$module_key], 'init')) {
                        $this->modules[$module_key]->init();
                    }
                    
                    do_action("psych_{$module_key}_initialized", $this->modules[$module_key]);
                } catch (Exception $e) {
                    $this->errors[] = "خطا در راه‌اندازی ماژول {$module_key}: " . $e->getMessage();
                }
            }
        }
    }

    /**
     * Setup global utility functions
     */
    private function setup_global_functions() {
        // Global API functions for other plugins/themes to use
        $this->register_global_functions();

        // Integration hooks for third-party plugins
        $this->setup_integration_hooks();
    }

    /**
     * Process nested shortcodes in station content
     */
    public function process_nested_shortcodes($content) {
        return do_shortcode($content);
    }

    /**
     * Register global API functions
     */
    private function register_global_functions() {
        if (!function_exists('psych_get_user_points')) {
            function psych_get_user_points($user_id) {
                if (isset($GLOBALS['psych_system']->modules['gamification'])) {
                    return $GLOBALS['psych_system']->modules['gamification']->get_user_total_points($user_id);
                }
                return 0;
            }
        }

        if (!function_exists('psych_award_points')) {
            function psych_award_points($user_id, $points, $reason = '') {
                if (isset($GLOBALS['psych_system']->modules['gamification'])) {
                    return $GLOBALS['psych_system']->modules['gamification']->award_points($user_id, $points, $reason);
                }
                return false;
            }
        }

        if (!function_exists('psych_get_user_level')) {
            function psych_get_user_level($user_id) {
                if (isset($GLOBALS['psych_system']->modules['gamification'])) {
                    return $GLOBALS['psych_system']->modules['gamification']->get_user_level_info($user_id);
                }
                return null;
            }
        }

        if (!function_exists('psych_get_viewing_context')) {
            function psych_get_viewing_context() {
                if (isset($GLOBALS['psych_system']->modules['path_engine'])) {
                    return $GLOBALS['psych_system']->modules['path_engine']->get_viewing_context();
                }
                return ['is_impersonating' => false, 'real_user_id' => get_current_user_id(), 'viewed_user_id' => get_current_user_id()];
            }
        }

        if (!function_exists('psych_is_coach_impersonating')) {
            function psych_is_coach_impersonating() {
                $context = psych_get_viewing_context();
                return $context['is_impersonating'];
            }
        }

        // Store reference in global scope for other functions
        $GLOBALS['psych_system'] = $this;
    }

    /**
     * Setup integration hooks for third-party compatibility
     */
    private function setup_integration_hooks() {
        // WooCommerce integration
        add_action('woocommerce_order_status_completed', [$this, 'woocommerce_order_completed']);

        // BuddyPress integration
        add_action('bp_activity_add', [$this, 'buddypress_activity_add']);

        // LearnDash integration
        add_action('learndash_lesson_completed', [$this, 'learndash_lesson_completed'], 10, 2);

        // bbPress integration
        add_action('bbp_new_topic', [$this, 'bbpress_new_topic']);
        add_action('bbp_new_reply', [$this, 'bbpress_new_reply']);
    }

    /**
     * Add main admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'سیستم روان گستر',
            'روان گستر',
            'manage_options',
            'psych-system',
            [$this, 'render_main_admin_page'],
            'dashicons-chart-line',
            30
        );

        add_submenu_page(
            'psych-system',
            'وضعیت سیستم',
            'وضعیت سیستم',
            'manage_options',
            'psych-system-status',
            [$this, 'render_system_status_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'psych-system') !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);

            wp_enqueue_script(
                'psych-system-admin',
                PSYCH_SYSTEM_URL . 'assets/admin.js',
                ['jquery', 'chart-js'],
                self::VERSION,
                true
            );

            wp_localize_script('psych-system-admin', 'psych_system_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('psych_system_admin_nonce'),
                'version' => self::VERSION,
                'modules' => array_keys($this->modules)
            ]);

            wp_enqueue_style(
                'psych-system-admin',
                PSYCH_SYSTEM_URL . 'assets/admin.css',
                [],
                self::VERSION
            );

            // Add inline admin styles
            wp_add_inline_style('psych-system-admin', $this->get_admin_inline_styles());
        }
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function frontend_enqueue_scripts() {
        // Only load when shortcodes are present
        if ($this->should_load_frontend_assets()) {
            wp_enqueue_script('jquery');

            // The psych-system-frontend script and style have been removed
            // because the files were deleted and the functionality is handled by
            // the self-contained dashboard-display.php module.
        }
    }

    /**
     * Check if frontend assets should be loaded
     */
    private function should_load_frontend_assets() {
        global $post;

        if (!is_a($post, 'WP_Post')) {
            return false;
        }

        $shortcodes = [
            'psych_dashboard',
            'psych_gamified_header',
            'psych_progress_path',
            'psych_leaderboard',
            'psych_interactive_content',
            'psych_report_card',
            'psychocourse_path',
            'psych_content_block',
            'psych_user_points',
            'psych_user_level',
            'psych_user_badges',
            'psych_user_performance_header',
            'station',
            'psych_button',
            'psych_hidden_content',
            'psych_accordion',
            'psych_quiz'
        ];

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get admin inline styles
     */
    private function get_admin_inline_styles() {
        return '
            .psych-admin-container {
                background: #f1f1f1;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }

            .psych-admin-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .psych-admin-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                border-right: 4px solid #3498db;
            }

            .psych-admin-card h3 {
                margin-top: 0;
                color: #2c3e50;
            }

            .psych-status-indicator {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                margin-left: 10px;
            }

            .psych-status-active { background: #27ae60; }
            .psych-status-inactive { background: #e74c3c; }
            .psych-status-warning { background: #f39c12; }

            .psych-admin-stats {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin: 20px 0;
            }

            .psych-admin-stat {
                background: #fff;
                padding: 15px;
                border-radius: 6px;
                text-align: center;
                border-left: 4px solid #3498db;
            }
        ';
    }

    /**
     * Get frontend inline styles
     */
    private function get_frontend_inline_styles() {
        return '
            .psych-user-performance-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
            
            .psych-user-performance-header .user-welcome h2 {
                margin: 0 0 10px 0;
                font-size: 24px;
            }
            
            .psych-user-performance-header .user-stats {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            
            .psych-user-performance-header .stat-item {
                background: rgba(255,255,255,0.2);
                padding: 10px 15px;
                border-radius: 5px;
            }
            
            .psych-user-performance-header .stat-label {
                display: block;
                font-size: 12px;
                opacity: 0.8;
            }
            
            .psych-user-performance-header .stat-value {
                display: block;
                font-size: 18px;
                font-weight: bold;
                margin-top: 5px;
            }
            
            .psychocourse-path {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            
            .psychocourse-path .path-header h2 {
                color: #343a40;
                margin-bottom: 20px;
                border-bottom: 2px solid #007bff;
                padding-bottom: 10px;
            }
            
            .psych-login-notice {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                color: #856404;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }
        ';
    }

    /**
     * Render main admin page
     */
    public function render_main_admin_page() {
        $system_status = $this->get_system_status();
        ?>
        <div class="wrap">
            <h1>سیستم جامع روان گستر</h1>
            <p>نسخه <?php echo esc_html(self::VERSION); ?> - Enhanced Integration Edition</p>

            <div class="psych-admin-container">
                <div class="psych-admin-grid">
                    <div class="psych-admin-card">
                        <h3>وضعیت سیستم</h3>
                        <ul>
                            <?php foreach ($system_status['modules'] as $module => $status): ?>
                                <li>
                                    <span class="psych-status-indicator psych-status-<?php echo $status['active'] ? 'active' : 'inactive'; ?>"></span>
                                    <?php echo esc_html($status['name']); ?>
                                    <small>(<?php echo $status['active'] ? 'فعال' : 'غیرفعال'; ?>)</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="psych-admin-card">
                        <h3>آمار کلی</h3>
                        <div class="psych-admin-stats">
                            <div class="psych-admin-stat">
                                <h3><?php echo number_format_i18n($system_status['stats']['total_users']); ?></h3>
                                <p>کل کاربران</p>
                            </div>
                            <div class="psych-admin-stat">
                                <h3><?php echo number_format_i18n($system_status['stats']['total_points']); ?></h3>
                                <p>کل امتیازات</p>
                            </div>
                            <div class="psych-admin-stat">
                                <h3><?php echo number_format_i18n($system_status['stats']['total_badges']); ?></h3>
                                <p>نشان‌های اعطا شده</p>
                            </div>
                            <div class="psych-admin-stat">
                                <h3><?php echo number_format_i18n($system_status['stats']['active_coaches']); ?></h3>
                                <p>مربیان فعال</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="psych-admin-card">
                    <h3>ابزارهای سیستم</h3>
                    <p>
                        <button type="button" class="button button-secondary" onclick="psychClearCache()">پاک کردن کش</button>
                        <button type="button" class="button button-secondary" onclick="psychTestSystem()">تست سیستم</button>
                        <a href="<?php echo admin_url('admin.php?page=psych-system-status'); ?>" class="button button-primary">وضعیت تفصیلی</a>
                    </p>
                </div>
            </div>
        </div>

        <script>
        function psychClearCache() {
            if (confirm('آیا مطمئن هستید که می‌خواهید کش را پاک کنید؟')) {
                jQuery.post(ajaxurl, {
                    action: 'psych_clear_cache',
                    nonce: '<?php echo wp_create_nonce('psych_clear_cache'); ?>'
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
                nonce: '<?php echo wp_create_nonce('psych_system_admin_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    alert('سیستم عملکرد مناسبی دارد!');
                } else {
                    alert('خطا در سیستم: ' + response.data.message);
                }
            });
        }
        </script>
        <?php
    }

    /**
     * Render system status page
     */
    public function render_system_status_page() {
        ?>
        <div class="wrap">
            <h1>وضعیت تفصیلی سیستم</h1>
            
            <div class="psych-admin-container">
                <h2>ماژول‌های بارگذاری شده</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>نام ماژول</th>
                            <th>کلاس</th>
                            <th>نسخه</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->modules as $key => $module): ?>
                        <tr>
                            <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $key))); ?></td>
                            <td><?php echo esc_html(get_class($module)); ?></td>
                            <td><?php echo defined(get_class($module) . '::VERSION') ? constant(get_class($module) . '::VERSION') : 'N/A'; ?></td>
                            <td><span class="psych-status-indicator psych-status-active"></span>فعال</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2>شورتکدهای ثبت شده</h2>
                <div class="psych-admin-card">
                    <p>شورتکدهای زیر توسط ماژول‌ها ثبت شده‌اند:</p>
                    <ul>
                        <?php
                        global $shortcode_tags;
                        $psych_shortcodes = array_filter(array_keys($shortcode_tags), function($tag) {
                            return strpos($tag, 'psych') !== false || strpos($tag, 'psycho') !== false;
                        });
                        sort($psych_shortcodes);
                        foreach ($psych_shortcodes as $shortcode):
                        ?>
                            <li><code>[<?php echo esc_html($shortcode); ?>]</code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get system status
     */
    public function get_system_status() {
        $stats = [
            'total_users' => count_users()['total_users'],
            'total_points' => $this->get_total_points_awarded(),
            'total_badges' => $this->get_total_badges_awarded(),
            'active_coaches' => $this->get_active_coaches_count()
        ];

        $modules = [];
        foreach ($this->modules as $key => $module) {
            $modules[$key] = [
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'active' => is_object($module),
                'class' => is_object($module) ? get_class($module) : 'N/A'
            ];
        }

        return [
            'stats' => $stats,
            'modules' => $modules,
            'version' => self::VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version')
        ];
    }

    /**
     * AJAX handler for system status
     */
    public function ajax_system_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_system_admin_nonce')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $status = $this->get_system_status();
        wp_send_json_success($status);
    }

    /**
     * AJAX handler for cache clearing
     */
    public function ajax_clear_cache() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_clear_cache')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $this->clear_all_cache();
        wp_send_json_success(['message' => 'کش با موفقیت پاک شد.']);
    }

    /**
     * Activation hook
     */
    public function activate() {
        // Create database tables if needed
        $this->create_database_tables();

        // Set default options
        if (!get_option('psych_system_settings')) {
            update_option('psych_system_settings', $this->get_default_settings());
        }

        // Set version
        update_option('psych_system_version', self::VERSION);

        // Schedule cleanup event
        if (!wp_next_scheduled('psych_system_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'psych_system_daily_cleanup');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('psych_system_daily_cleanup');

        // Clear all cache
        $this->clear_all_cache();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Uninstall hook
     */
    public static function uninstall() {
        // Remove all options
        delete_option('psych_system_settings');
        delete_option('psych_system_version');

        // Clean up user meta
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'psych_%'");

        // Drop custom tables if needed
        // $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}psych_achievements");
    }

    /**
     * Create database tables
     */
    private function create_database_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}psych_achievements (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            achievement_type varchar(50) NOT NULL,
            achievement_value text NOT NULL,
            points int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY achievement_type (achievement_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get default settings
     */
    private function get_default_settings() {
        return [
            'enable_gamification' => true,
            'enable_coach_module' => true,
            'enable_path_engine' => true,
            'enable_interactive_content' => true,
            'enable_dashboard' => true,
            'cache_enabled' => true,
            'cache_duration' => 3600,
            'show_paths_to_guests' => true,
            'guest_can_see_free_stations' => true
        ];
    }

    /**
     * Daily cleanup
     */
    public function daily_cleanup() {
        $this->cleanup_old_achievements();
        $this->optimize_database_tables();
        $this->clear_expired_cache();
    }

    /**
     * Cleanup old achievements
     */
    private function cleanup_old_achievements() {
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        global $wpdb;
        $table_name = $wpdb->prefix . 'psych_achievements';

        $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name
            WHERE created_at < %s
        ", $cutoff_date));
    }

    /**
     * Optimize database tables
     */
    private function optimize_database_tables() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'psych_achievements'
        ];

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
    }

    /**
     * Clear expired cache
     */
    private function clear_expired_cache() {
        global $wpdb;

        // Clear WordPress transients
        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_timeout_psych_%'
            AND option_value < UNIX_TIMESTAMP()
        ");

        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_psych_%'
            AND option_name NOT IN (
                SELECT CONCAT('_transient_', SUBSTRING(option_name, 20))
                FROM {$wpdb->options} o2
                WHERE o2.option_name LIKE '_transient_timeout_psych_%'
            )
        ");
    }

    /**
     * Clear all cache
     */
    public function clear_all_cache() {
        global $wpdb;

        // Clear WordPress transients
        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_psych_%'
            OR option_name LIKE '_transient_timeout_psych_%'
        ");

        // Clear object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        do_action('psych_system_cache_cleared');
    }

    /**
     * Helper methods for statistics
     */
    private function get_total_points_awarded() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT SUM(meta_value) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'psych_total_points'
        ");
        return intval($result);
    }

    private function get_total_badges_awarded() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'psych_user_badges'
            AND meta_value != ''
        ");
        return intval($result);
    }

    private function get_active_coaches_count() {
        $users = get_users(['role__in' => ['coach', 'adviser']]);
        return count($users);
    }

    /**
     * Third-party integrations
     */
    public function woocommerce_order_completed($order_id) {
        if (!function_exists('psych_award_points')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $user_id = $order->get_user_id();
        if (!$user_id) return;

        $points = apply_filters('psych_woocommerce_order_points', 50, $order);
        psych_award_points($user_id, $points, 'تکمیل سفارش');
    }

    public function buddypress_activity_add($activity) {
        if (!function_exists('psych_award_points')) return;

        $points = apply_filters('psych_buddypress_activity_points', 10, $activity);
        psych_award_points($activity['user_id'], $points, 'فعالیت در شبکه اجتماعی');
    }

    public function learndash_lesson_completed($user_id, $lesson_id) {
        if (!function_exists('psych_award_points')) return;

        $points = apply_filters('psych_learndash_lesson_points', 25, $lesson_id);
        psych_award_points($user_id, $points, 'تکمیل درس');
    }

    public function bbpress_new_topic($topic_id) {
        if (!function_exists('psych_award_points')) return;

        $user_id = bbp_get_topic_author_id($topic_id);
        $points = apply_filters('psych_bbpress_topic_points', 15, $topic_id);
        psych_award_points($user_id, $points, 'ایجاد موضوع جدید');
    }

    public function bbpress_new_reply($reply_id) {
        if (!function_exists('psych_award_points')) return;

        $user_id = bbp_get_reply_author_id($reply_id);
        $points = apply_filters('psych_bbpress_reply_points', 5, $reply_id);
        psych_award_points($user_id, $points, 'پاسخ به موضوع');
    }

    /**
     * After system loaded hook
     */
    public function after_system_loaded() {
        // All modules are loaded and initialized
        // Trigger final integration hooks
        do_action('psych_system_fully_loaded', $this);
    }

    /**
     * Display admin errors
     */
    public function display_admin_errors() {
        if (empty($this->errors)) return;

        foreach ($this->errors as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }

    /**
     * Get module instance
     */
    public function get_module($module_name) {
        return $this->modules[$module_name] ?? null;
    }

    /**
     * Check if module is active
     */
    public function is_module_active($module_name) {
        return isset($this->modules[$module_name]) && is_object($this->modules[$module_name]);
    }

    /**
     * Get all active modules
     */
    public function get_active_modules() {
        return array_filter($this->modules, 'is_object');
    }

    /**
     * Prevent cloning
     */
    public function __clone() {
        wp_die('Cloning is not allowed.');
    }

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        wp_die('Unserializing is not allowed.');
    }
}

// Initialize the plugin only after WordPress is fully loaded
if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Complete_System_Enhanced::get_instance();
    }, 0);
}

// Create global instance for backward compatibility
add_action('wp_loaded', function() {
    $GLOBALS['psych_complete_system'] = Psych_Complete_System_Enhanced::get_instance();
});

// Additional utility functions
if (!function_exists('psych_system_get_instance')) {
    function psych_system_get_instance() {
        return Psych_Complete_System_Enhanced::get_instance();
    }
}

if (!function_exists('psych_system_is_active')) {
    function psych_system_is_active() {
        return class_exists('Psych_Complete_System_Enhanced') && 
               psych_system_get_instance()->initialized;
    }
}

?>