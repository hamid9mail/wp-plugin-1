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
    // ... (rest of the class properties)

    private function load_modules() {
        $modules_to_load = [
            'gamification-center.php' => 'Psych_Gamification_Center',
            'coach-module.php' => 'Psych_Coach_Module',
            'path-engine-fixed.php' => 'PsychoCourse_Path_Engine',
            'interactive-content.php' => 'Psych_Interactive_Content_Module',
            'report-card.php' => 'Psych_Unified_Report_Card_Enhanced',
            'dashboard-display.php' => 'Psych_Dashboard_Display_Enhanced',
            'personalization-module.php' => 'Psych_Personalization_Module',
            'admin-dashboard-module.php' => 'Psych_Admin_Dashboard_Module',
            'advanced-quiz-module.php' => 'Psych_Advanced_Quiz_Module',
            'psych-flag-triggers.php' => 'Psych_Flag_Triggers' // NEW: Loading the new flag trigger system
        ];

        foreach ($modules_to_load as $file => $class) {
            $file_path = PSYCH_SYSTEM_DIR . $file;
            if (file_exists($file_path)) {
                if (!defined('PSYCH_PREVENT_AUTO_INIT') && $class === 'Psych_Flag_Triggers') {
                    // This is a special case, we want this class to initialize itself
                } else if (!defined('PSYCH_PREVENT_AUTO_INIT')) {
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

    // ... (rest of the methods from the original psych-complete-system.php file)
    // ...
}

// ... (rest of the file remains the same) ...

if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Complete_System_Enhanced::get_instance();
    }, 0);
}

add_action('wp_loaded', function() {
    $GLOBALS['psych_complete_system'] = Psych_Complete_System_Enhanced::get_instance();
});

// ... (rest of the helper functions) ...
?>
