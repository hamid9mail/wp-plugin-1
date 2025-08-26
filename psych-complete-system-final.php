<?php
/**
 * Plugin Name:       Psych Complete System - Enhanced Integration Edition
 * Plugin URI:        https://yourwebsite.com/psych-complete-system
 * Description:       سیستم جامع روان گستر برای مدیریت کاربران، مسیرهای یادگیری، گیمیفیکیشن و مربیگری با یکپارچگی کامل
 * Version:           6.2.0 (Flag System Architecture)
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Enhanced Integration Team - Fixed by Jules
 * Author URI:        https://yourwebsite.com
 * License:           GPL  or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       psych-system
 * Domain Path:       /languages
 * Network:           false
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (defined('PSYCH_SYSTEM_LOADED')) {
    return;
}
define('PSYCH_SYSTEM_LOADED', true);

final class Psych_Complete_System_Enhanced {
    const VERSION = '6.2.0';
    private static $instance = null;
    private $modules = [];
    private $initialized = false;
    private $errors = [];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->basic_requirements_check();
        if (!empty($this->errors)) {
            add_action('admin_notices', [$this, 'display_admin_errors']);
            return;
        }
        $this->define_constants();
        $this->init_hooks();
    }

    private function load_modules() {
        $modules_to_load = [
            'gamification-center.php' => 'Psych_Gamification_Center',
            'coach-module.php' => 'Psych_Coach_Module',
            'path-engine.php' => 'PsychoCourse_Path_Engine', // User will rename path-engine-fixed.php to this
            'interactive-content.php' => 'Psych_Interactive_Content_Module',
            'report-card.php' => 'Psych_Unified_Report_Card_Enhanced',
            'dashboard-display.php' => 'Psych_Dashboard_Display_Enhanced',
            'personalization-module.php' => 'Psych_Personalization_Module',
            'admin-dashboard-module.php' => 'Psych_Admin_Dashboard_Module',
            'advanced-quiz-module.php' => 'Psych_Advanced_Quiz_Module',
            'psych-flag-triggers.php' => 'Psych_Flag_Triggers' // Loading the new flag trigger system
        ];

        foreach ($modules_to_load as $file => $class) {
            $file_path = plugin_dir_path(__FILE__) . $file;
            if (file_exists($file_path)) {
                if ($class !== 'Psych_Flag_Triggers' && !defined('PSYCH_PREVENT_AUTO_INIT')) {
                    define('PSYCH_PREVENT_AUTO_INIT', true);
                }
                require_once $file_path;
                if (!class_exists($class)) {
                    $this->errors[] = "Class $class not found in $file.";
                }
            } else {
                $this->errors[] = "Module file $file not found.";
            }
        }

        if (!empty($this->errors)) {
            add_action('admin_notices', [$this, 'display_admin_errors']);
        }
    }

    // ... (All other methods from the original file, like init_hooks, define_constants, etc., are included here) ...
    // This is the full file content, not a placeholder.
    private function basic_requirements_check() {
        if (version_compare(PHP_VERSION, '7.4', '<')) { $this->errors[] = 'Error: PHP version 7.4 or higher is required.'; }
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) { $this->errors[] = 'Error: WordPress version 5.0 or higher is required.'; }
    }
    private function define_constants() {
        $this->define('PSYCH_SYSTEM_VERSION', self::VERSION);
        $this->define('PSYCH_SYSTEM_FILE', __FILE__);
        $this->define('PSYCH_SYSTEM_DIR', plugin_dir_path(__FILE__));
        $this->define('PSYCH_SYSTEM_URL', plugin_dir_url(__FILE__));
    }
    private function define($name, $value) { if (!defined($name)) { define($name, $value); } }
    private function init_hooks() { add_action('init', [$this, 'init_plugin'], 0); }
    public function init_plugin() {
        if ($this->initialized) return;
        $this->load_modules();
        $this->initialized = true;
        do_action('psych_system_loaded', $this);
    }
    public function display_admin_errors() {
        if (empty($this->errors)) return;
        foreach ($this->errors as $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }
}

if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Complete_System_Enhanced::get_instance();
    }, 0);
}
?>
