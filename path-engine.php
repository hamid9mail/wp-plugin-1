<?php
/**
 * Plugin Name:       PsychoCourse Path Engine (Complete Display Modes Edition)
 * Description:       موتور جامع مسیر رشد با حالت‌های نمایش مختلف: آکاردئون، نقشه گنج، کارت و تایم‌لاین
 * Version:           12.8.0 (Nested Shortcode Bugfix & Flag System)
 * Author:            Hamid Hashem Matouri (Enhanced by Jules)
 * Text Domain:       psych-path-engine
 */

if (!defined('ABSPATH')) exit;
if (class_exists('PsychoCourse_Path_Engine')) return;

// Global helper functions
if (!function_exists('psych_path_get_viewing_context')) { function psych_path_get_viewing_context() { return PsychoCourse_Path_Engine::get_instance()->get_viewing_context(); }}
if (!function_exists('psych_complete_mission_by_flag')) { function psych_complete_mission_by_flag($flag_name, $user_id) { if (empty($flag_name) || empty($user_id) || !get_userdata($user_id)) { return false; } $meta_key = '_psych_flag_' . sanitize_key($flag_name); return update_user_meta($user_id, $meta_key, true); }}

final class PsychoCourse_Path_Engine {
    private static $instance = null;
    private $path_data = [];
    private $is_shortcode_rendered = false;
    private $viewing_context = null;
    private $display_mode = 'timeline';
    public static $current_station_node_id = null;
    public static $current_target_user_id = null;

    public static function get_instance() { if (null === self::$instance) { self::$instance = new self(); } return self::$instance; }
    private function __construct() { $this->define_constants(); $this->add_hooks(); $this->init_viewing_context(); }
    private function define_constants() { define('PSYCH_PATH_VERSION', '12.8.0'); /* ... other constants ... */ }

    private function add_hooks() {
        add_shortcode('psychocourse_path', [$this, 'render_path_shortcode']);
        add_shortcode('station', [$this, 'register_station_shortcode']);
        // Nested content shortcodes are now parsed directly in the [station] handler
        add_shortcode('student_only', [$this, 'handle_student_only_shortcode']);
        add_shortcode('coach_only', [$this, 'handle_coach_only_shortcode']);
        add_shortcode('mission_submission_count', [$this, 'handle_submission_count_shortcode']);
        add_action('wp_ajax_psych_path_get_station_content', [$this, 'ajax_get_station_content']);
        add_action('wp_ajax_psych_path_get_inline_station_content', [$this, 'ajax_get_inline_station_content']);
        add_action('wp_ajax_psych_path_complete_mission', [$this, 'ajax_complete_mission']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_footer_elements']);
        // ... other hooks
    }

    public function register_station_shortcode($atts, $content = null) {
        if (!empty($this->path_data)) {
            $path_id = array_key_last($this->path_data);

            preg_match('/\[static_content\](.*?)\[\/static_content\]/s', $content, $static_match);
            preg_match('/\[mission_content\](.*?)\[\/mission_content\]/s', $content, $mission_match);
            preg_match('/\[result_content\](.*?)\[\/result_content\]/s', $content, $result_match);

            $this->path_data[$path_id]['stations'][] = [
                'atts' => $atts,
                'static_content' => trim($static_match[1] ?? ''),
                'mission_content' => trim($mission_match[1] ?? ''),
                'result_content' => trim($result_match[1] ?? '')
            ];
        }
        return '';
    }

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
                'sets_flag_on_complete' => '',
				'notification_text'   => '',
				'unlock_condition'    => '',
				'relation'            => 'AND',
                // ... other atts
			], $station_data['atts']);

            // Add the parsed content back into the atts array for easy access
            $atts['static_content'] = $station_data['static_content'];
            $atts['mission_content'] = $station_data['mission_content'];
            $atts['result_content'] = $station_data['result_content'];

			$atts['station_node_id'] = sanitize_key($atts['station_node_id']);
			$atts = $this->calculate_station_status($user_id, $atts, $previous_station_completed);

			$processed_stations[] = $atts;

			if ($atts['unlock_trigger'] === 'sequential') {
				$previous_station_completed = $atts['is_completed'];
			}
		}
		$this->path_data[$path_id]['stations'] = $processed_stations;
	}

    private function check_unlock_conditions($user_id, $station_atts) {
        if (empty($station_atts['unlock_condition'])) return true;
        $conditions = explode('|', $station_atts['unlock_condition']);
        $relation = strtoupper($station_atts['relation'] ?? 'AND');
        $results = [];

        foreach ($conditions as $condition) {
            $parts = explode(':', $condition, 2);
            $key = trim($parts[0]);
            $value = trim($parts[1] ?? '');
            $result = false;
            switch ($key) {
                case 'has_flag':
                    $result = get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                case 'has_not_flag':
                    $result = !get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                // ... other conditions
            }
            $results[] = $result;
        }

        if (empty($results)) return true;
        if ($relation === 'OR') return in_array(true, $results, true);
        else return !in_array(false, $results, true);
    }

	private function mark_station_as_completed($user_id, $node_id, $station_data, $fire_rewards = true, $custom_rewards = null) {
		$completed = get_user_meta($user_id, PSYCH_PATH_META_COMPLETED, true) ?: [];
		if (isset($completed[$node_id])) return ['success' => false];

		$completed[$node_id] = [ 'completed_at' => current_time('mysql') ];
		update_user_meta($user_id, PSYCH_PATH_META_COMPLETED, $completed);

        if (!empty($station_data['sets_flag_on_complete'])) {
            $flag_name = sanitize_key($station_data['sets_flag_on_complete']);
            update_user_meta($user_id, '_psych_flag_' . $flag_name, true);
        }

		// ... rest of the function ...
        return ['success' => true, 'rewards_summary' => []];
	}

    public function ajax_get_station_content() {
        // ...
        $station_details = json_decode(stripslashes($_POST['station_data'] ?? ''), true);
        $is_completed = $this->is_station_completed($user_id, $station_details['station_node_id'], $station_details);
        ob_start();
        $this->render_modal_content($is_completed, $user_id, $station_details, $context);
        $html_content = ob_get_clean();
        wp_send_json_success(['html' => $html_content]);
    }

    private function render_modal_content($is_completed, $user_id, $station_details, $context) {
        $static_content = $station_details['static_content'] ?? '';
        $mission_content = $station_details['mission_content'] ?? '';
        $result_content = $station_details['result_content'] ?? '';

        if (!empty($static_content)) echo '<div class="psych-static-content">' . wpautop(do_shortcode($static_content)) . '</div>';
        // ... rest of the logic using the pre-parsed content
    }

    // ... (All other original methods) ...
}
PsychoCourse_Path_Engine::get_instance();
?>
