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

// ... (Global helper functions remain the same) ...

final class PsychoCourse_Path_Engine {

    // ... (Properties remain the same) ...

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

    // ... (Most methods remain the same, I will only show the modified ones) ...

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
                'sets_flag_on_complete' => '', // NEW: The flag to set upon completion
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
                case 'has_flag': // NEW
                    $result = get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                case 'has_not_flag': // NEW
                    $result = !get_user_meta($user_id, '_psych_flag_' . sanitize_key($value), true);
                    break;
                case 'has_badge':
                    if (function_exists('psych_user_has_badge')) {
                        $result = psych_user_has_badge($user_id, $value);
                    }
                    break;
                case 'missing_badge':
                    if (function_exists('psych_user_has_badge')) {
                        $result = !psych_user_has_badge($user_id, $value);
                    }
                    break;
                case 'min_points':
                    $points = (int) get_user_meta($user_id, 'psych_total_points', true);
                    $result = ($points >= (int)$value);
                    break;
                case 'max_points':
                    $points = (int) get_user_meta($user_id, 'psych_total_points', true);
                    $result = ($points <= (int)$value);
                    break;
                case 'user_level':
                     if (function_exists('psych_gamification_get_user_level')) {
                        $level = psych_gamification_get_user_level($user_id);
                        $result = (is_array($level) && strtolower($level['name']) === strtolower($value));
                    }
                    break;
                case 'user_meta_key':
                    $meta_value = get_user_meta($user_id, $value, true);
                    $check_value = $station_atts['user_meta_value'] ?? '';
                    $result = ($meta_value == $check_value);
                    break;
            }
            $results[] = $result;
        }

        if (empty($results)) {
            return true;
        }

        if ($relation === 'OR') {
            return in_array(true, $results, true);
        } else {
            return !in_array(false, $results, true);
        }
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

        // NEW: Set a flag if defined in the shortcode
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

    // ... (The rest of the file's original content) ...
}

PsychoCourse_Path_Engine::get_instance();
?>
