<?php
/**
 * Plugin Name:       Psych Complete System - Enhanced Integration Edition
 * Plugin URI:        https://yourwebsite.com/psych-complete-system
 * Description:       سیستم جامع روان گستر برای مدیریت کاربران، مسیرهای یادگیری، گیمیفیکیشن و مربیگری با یکپارچگی کامل
 * Version:           6.3.0 (Bugfix and Flag System)
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

if (!defined('ABSPATH')) exit;
if (class_exists('Psych_Complete_System_Enhanced')) return;

define('PSYCH_SYSTEM_LOADED', true);

final class Psych_Complete_System_Enhanced {
    const VERSION = '6.3.0';
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
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {
        define('PSYCH_SYSTEM_VERSION', self::VERSION);
        define('PSYCH_SYSTEM_DIR', plugin_dir_path(__FILE__));
        // ... other constants
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init_plugin'], 10);
        // ... other hooks
    }

    public function init_plugin() {
        if ($this->initialized) return;
        $this->load_modules();
        $this->initialized = true;
        do_action('psych_system_loaded', $this);
    }

    private function load_modules() {
        $modules_to_load = [
            'gamification-center.php' => 'Psych_Gamification_Center',
            'coach-module.php' => 'Psych_Coach_Module',
            'path-engine.php' => 'PsychoCourse_Path_Engine', // Correct, clean filename
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
                require_once $file_path;
                if (!class_exists($class)) {
                    // error handling
                }
            }
        }
    }

    // ... (All other original methods from the file) ...
}

Psych_Complete_System_Enhanced::get_instance();
?>
