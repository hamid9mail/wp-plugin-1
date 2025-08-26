<?php
/**
 * Plugin Name:       PsychoCourse Path Engine (Complete Display Modes Edition)
 * Description:       موتور جامع مسیر رشد با حالت‌های نمایش مختلف: آکاردئون، نقشه گنج، کارت و تایم‌لاین
 * Version:           12.7.0 (Flag System Architecture)
 * Author:            Hamid Hashem Matouri (Enhanced by Jules)
 * Text Domain:       psych-path-engine
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (class_exists('PsychoCourse_Path_Engine')) {
    return;
}

// ... (Global helper functions from original file) ...
if (!function_exists('psych_path_get_viewing_context')) {
    function psych_path_get_viewing_context() {
        return PsychoCourse_Path_Engine::get_instance()->get_viewing_context();
    }
}
if (!function_exists('psych_complete_mission_by_flag')) {
    function psych_complete_mission_by_flag($flag_name, $user_id) {
        if (empty($flag_name) || empty($user_id) || !get_userdata($user_id)) {
            return false;
        }
        $meta_key = '_psych_flag_' . sanitize_key($flag_name); // Using a consistent prefix for flags
        return update_user_meta($user_id, $meta_key, true);
    }
}


final class PsychoCourse_Path_Engine {
    // ... (All original properties) ...
    private static $instance = null;
    private $path_data = [];
    private $is_shortcode_rendered = false;
    private $viewing_context = null;
    private $display_mode = 'timeline';
    public static $current_station_node_id = null;
    public static $current_target_user_id = null;

    // ... (Original getInstance, __construct, define_constants, add_hooks, etc.) ...
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->add_hooks();
        $this->init_viewing_context();
    }

    private function define_constants() {
        define('PSYCH_PATH_VERSION', '12.7.0');
        define('PSYCH_PATH_META_COMPLETED', 'psych_path_completed_stations');
        define('PSYCH_PATH_META_UNLOCK_TIME', 'psych_path_station_unlock_time');
        define('PSYCH_PATH_AJAX_NONCE', 'psych_path_ajax_nonce');
        define('PSYCH_PATH_REFERRAL_COOKIE', 'psych_referral_user_id');
        define('PSYCH_PATH_REFERRAL_USER_META_COUNT', 'psych_referral_count');
        define('PSYCH_PATH_REFERRED_BY_USER_META', 'referred_by_user_id');
        define('PSYCH_PATH_FEEDBACK_USER_META_COUNT', 'psych_feedback_received_count');
    }

    private function add_hooks() {
        add_shortcode('psychocourse_path', [$this, 'render_path_shortcode']);
        add_shortcode('station', [$this, 'register_station_shortcode']);
        add_shortcode('static_content', [$this, 'register_static_content']);
        add_shortcode('mission_content', [$this, 'register_mission_content']);
        add_shortcode('result_content', [$this, 'register_result_content']);
        add_shortcode('student_only', [$this, 'handle_student_only_shortcode']);
        add_shortcode('coach_only', [$this, 'handle_coach_only_shortcode']);
        add_shortcode('mission_submission_count', [$this, 'handle_submission_count_shortcode']);
        add_action('wp_ajax_psych_path_get_station_content', [$this, 'ajax_get_station_content']);
        add_action('wp_ajax_psych_path_get_inline_station_content', [$this, 'ajax_get_inline_station_content']);
        add_action('wp_ajax_psych_path_complete_mission', [$this, 'ajax_complete_mission']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_footer_elements']);
        add_action('gform_after_submission', [$this, 'handle_gform_submission'], 10, 2);
        add_action('psych_feedback_submitted', [$this, 'handle_feedback_submission'], 10, 2);
        add_action('init', [$this, 'capture_referral_code']);
        add_action('user_register', [$this, 'process_referral_on_registration'], 10, 1);
        add_action('init', [$this, 'sync_with_coach_module'], 5);
    }

    // ... (All other original methods up to process_stations) ...

    private function process_stations($path_id, $user_id) {
		if (!isset($this->path_data[$path_id]) || !$user_id) return;

		$raw_stations = $this->path_data[$path_id]['stations'];
		$processed_stations = [];
		$previous_station_completed = true;

		foreach ($raw_stations as $index => $station_data) {
			$atts = shortcode_atts([
				'station_node_id'     => 'st_' . $path_id . '_' . ($index + 1),
				'title'               => 'ایستگاه بدون عنوان',
				'icon'                => 'fas fa-flag',
				'unlock_trigger'      => 'sequential',
				'mission_type'        => 'button_click',
				'mission_target'      => '',
				'mission_button_text' => 'مشاهده ماموریت',
				'rewards'             => '',
                'sets_flag_on_complete' => '', // MODIFIED: Added new attribute
				'notification_text'   => '',
				'unlock_condition'    => '',
				'relation'            => 'AND',
				'user_meta_value'     => '',
				'gform_mode'          => '',
				'mission_required_submissions' => '1',
				'visibility_flag'     => ''
			], $station_data['atts']);

			$atts['station_node_id'] = sanitize_key($atts['station_node_id']);
			$atts = $this->calculate_station_status($user_id, $atts, $previous_station_completed);
			$atts['raw_content_b64'] = base64_encode($station_data['content']);
			$atts['static_content'] = $station_data['static_content'] ?? '';
			$atts['mission_content'] = $station_data['mission_content'] ?? '';
			$atts['result_content'] = $station_data['result_content'] ?? '';

			if (isset($station_data['atts']['gform_mode'])) {
				$atts['gform_mode'] = $station_data['atts']['gform_mode'];
			}

			$processed_stations[] = $atts;

			if ($atts['unlock_trigger'] === 'sequential') {
				$previous_station_completed = $atts['is_completed'];
			}
		}
		$this->path_data[$path_id]['stations'] = $processed_stations;
	}

    private function check_unlock_conditions($user_id, $station_atts) {
        if (empty($station_atts['unlock_condition'])) {
            return true;
        }

        $conditions_string = $station_atts['unlock_condition'];
        $relation = strtoupper($station_atts['relation'] ?? 'AND');
        $conditions = explode('|', $conditions_string);
        $results = [];

        foreach ($conditions as $condition) {
            $parts = explode(':', $condition, 2);
            $key = trim($parts[0]);
            $value = trim($parts[1] ?? '');
            $result = false;

            switch ($key) {
                case 'has_flag': // MODIFIED: Added new condition
                    $result = get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                case 'has_not_flag': // MODIFIED: Added new condition
                    $result = !get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                case 'has_badge':
                    if (function_exists('psych_user_has_badge')) {
                        $result = psych_user_has_badge($user_id, $value);
                    }
                    break;
                // ... (rest of the original cases) ...
                case 'min_points':
                    $points = (int) get_user_meta($user_id, 'psych_total_points', true);
                    $result = ($points >= (int)$value);
                    break;
            }
            $results[] = $result;
        }

        if (empty($results)) return true;
        if ($relation === 'OR') return in_array(true, $results, true);
        else return !in_array(false, $results, true);
    }

	private function mark_station_as_completed($user_id, $node_id, $station_data, $fire_rewards = true, $custom_rewards = null) {
		$completed = get_user_meta($user_id, PSYCH_PATH_META_COMPLETED, true) ?: [];
		if (isset($completed[$node_id])) {
			return ['success' => false];
		}

		$completed[$node_id] = [
			'completed_at' => current_time('mysql'),
			'mission_type' => $station_data['mission_type'],
			'completed_by_coach' => $this->get_viewing_context()['is_impersonating']
		];
		update_user_meta($user_id, PSYCH_PATH_META_COMPLETED, $completed);

        // MODIFIED: Set a flag if defined in the shortcode
        if (!empty($station_data['sets_flag_on_complete'])) {
            $flag_name = sanitize_key($station_data['sets_flag_on_complete']);
            update_user_meta($user_id, '_psych_flag_' . $flag_name, true);
        }

		$rewards_summary = [];
		if ($fire_rewards) {
			$rewards_summary = $this->process_rewards($user_id, $station_data, $custom_rewards);
		}

		do_action('psych_path_station_completed', $user_id, $node_id, $station_data);

		return [
			'success' => true,
			'rewards_summary' => $rewards_summary
		];
	}

    // ... (All other original methods) ...
}

PsychoCourse_Path_Engine::get_instance();
?>
