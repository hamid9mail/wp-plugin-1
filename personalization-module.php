<?php
/**
 * Plugin Name: Psych Personalization Module
 * Description: A module to provide a powerful conditional shortcode [psych_personalize] for showing/hiding content based on user data, quiz results, and more.
 * Version: 1.0.0
 * Author: Jules
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class Psych_Personalization_Module {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_shortcode'));
    }

    public function register_shortcode() {
        add_shortcode('psych_personalize', array($this, 'render_personalize_shortcode'));
    }

    public function render_personalize_shortcode($atts, $content = null) {
        $atts = shortcode_atts(array(
            'condition' => '',
        ), $atts, 'psych_personalize');

        if (empty($atts['condition'])) {
            return ''; // If no condition, hide content by default for safety
        }

        if ($this->evaluate_conditions($atts['condition'])) {
            return do_shortcode($content);
        }

        return ''; // Condition not met, hide content
    }

    private function evaluate_conditions($condition_string) {
        // For now, let's handle a single, simple condition.
        // The full parser for AND/OR logic will be complex.
        // Example: "profile:gender=female"
        // Example: "quiz:stress_test>70"

        $parts = preg_split('/(>=|<=|!=|=|>|<)/', $condition_string, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) !== 3) {
            return false; // Invalid condition format
        }

        list($key, $operator, $expected_value) = $parts;
        $key_parts = explode(':', trim($key));
        $source = $key_parts[0] ?? '';
        $source_id = $key_parts[1] ?? '';

        $actual_value = null;

        // Get the correct user ID, respecting coach impersonation
        $context = function_exists('psych_get_viewing_context') ? psych_get_viewing_context() : ['viewed_user_id' => get_current_user_id()];
        $user_id = $context['viewed_user_id'];

        if (!$user_id) {
            return false; // Cannot determine a user, so hide content
        }

        switch ($source) {
            case 'profile':
                $actual_value = get_user_meta($user_id, $source_id, true);
                break;

            case 'quiz':
                global $wpdb;
                // Use $wpdb->prefix to be more robust
                $table_name = $wpdb->prefix . 'psych_quiz_results';
                $score = $wpdb->get_var($wpdb->prepare(
                    "SELECT score FROM {$table_name} WHERE user_id = %d AND quiz_id = %s ORDER BY id DESC LIMIT 1",
                    $user_id, $source_id
                ));
                $actual_value = $score;
                break;

            case 'role':
                $user = get_userdata($user_id);
                if ($user && in_array($source_id, (array) $user->roles, true)) {
                    $actual_value = $source_id; // For role checks, we just need to match the role name
                }
                break;

            case 'points':
                if (function_exists('psych_get_user_points')) {
                    $actual_value = psych_get_user_points($user_id);
                } else {
                    $actual_value = get_user_meta($user_id, 'psych_total_points', true);
                }
                break;

            case 'badge':
                 if (function_exists('psych_user_has_badge')) {
                    // This source just checks for existence of a badge by its slug or ID
                    if (psych_user_has_badge($user_id, $source_id)) {
                        $actual_value = 'true'; // Set to string 'true' for comparison
                    }
                }
                break;

            // Other cases like gravity_form, mission will be added here.
        }

        if ($actual_value === null) {
            return false;
        }

        // Perform comparison
        switch ($operator) {
            case '=': return $actual_value == $expected_value;
            case '!=': return $actual_value != $expected_value;
            case '>': return (float)$actual_value > (float)$expected_value;
            case '<': return (float)$actual_value < (float)$expected_value;
            case '>=': return (float)$actual_value >= (float)$expected_value;
            case '<=': return (float)$actual_value <= (float)$expected_value;
        }

        return false;
    }
}
