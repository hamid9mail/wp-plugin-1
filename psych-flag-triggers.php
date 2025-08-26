<?php
/**
 * Plugin Name: Psych Complete System - Flag Triggers
 * Description: ماژول کمکی برای ایجاد دکمه‌ها و تریگرهای سفارشی برای سیستم فلگ
 * Version: 1.0.0
 * Author: Jules
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Psych_Flag_Triggers {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'init_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_psych_set_flag_from_button', [$this, 'ajax_set_flag_from_button']);
    }

    public function init_shortcodes() {
        add_shortcode('psych_button', [$this, 'render_psych_button']);
        add_shortcode('trigger_on_gform_submit', [$this, 'handle_gform_trigger_wrapper']);
        add_shortcode('trigger_on_quiz_complete', [$this, 'handle_quiz_trigger_wrapper']);
    }

    public function enqueue_scripts() {
        // We can add logic to only enqueue this when a shortcode is present if needed
        wp_register_script(
            'psych-flag-triggers-js',
            plugin_dir_url(__FILE__) . 'assets/flag-triggers.js',
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script('psych-flag-triggers-js', 'psych_flag_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psych_flag_trigger_nonce')
        ]);
    }

    /**
     * Renders the [psych_button] shortcode.
     */
    public function render_psych_button($atts) {
        wp_enqueue_script('psych-flag-triggers-js');
        $atts = shortcode_atts([
            'text' => 'Click Me',
            'flag' => '',
            'class' => 'psych-custom-button'
        ], $atts);

        if (empty($atts['flag'])) {
            return '<p>Error: "flag" attribute is missing for psych_button.</p>';
        }

        return sprintf(
            '<button class="psych-flag-button %s" data-flag="%s">%s</button>',
            esc_attr($atts['class']),
            esc_attr($atts['flag']),
            esc_html($atts['text'])
        );
    }

    /**
     * Handles the [trigger_on_gform_submit] wrapper shortcode.
     */
    public function handle_gform_trigger_wrapper($atts, $content = null) {
        $atts = shortcode_atts(['flag' => ''], $atts);
        $flag = $atts['flag'];

        if (empty($flag)) return '<!-- Flag trigger error: flag attribute missing -->';

        // Find the form ID from the nested shortcode
        preg_match('/\[gravityform .*id="?\'?(\d+)"?\'?.*\]/s', $content, $matches);
        $form_id = $matches[1] ?? 0;

        if ($form_id > 0) {
            add_action("gform_after_submission_{$form_id}", function($entry, $form) use ($flag) {
                $user_id = rgar($entry, 'created_by');
                if ($user_id) {
                    update_user_meta($user_id, '_psych_flag_' . sanitize_key($flag), true);
                }
            }, 10, 2);
        }

        return do_shortcode($content);
    }

    /**
     * Handles the [trigger_on_quiz_complete] wrapper shortcode.
     */
    public function handle_quiz_trigger_wrapper($atts, $content = null) {
        $atts = shortcode_atts([
            'flag' => '',
            'condition' => '' // e.g., "score>90"
        ], $atts);

        $flag = $atts['flag'];
        $condition = $atts['condition'];

        if (empty($flag)) return '<!-- Flag trigger error: flag attribute missing -->';

        add_action('psych_advanced_quiz_completed', function($user_id, $quiz_id, $results) use ($flag, $condition) {
            $condition_met = true; // Assume true if no condition
            if (!empty($condition)) {
                // Basic condition parser: "score>90"
                if (preg_match('/(score)\s*([><=]+)\s*(\d+)/', $condition, $matches)) {
                    $score = $results['score'] ?? 0;
                    $operator = $matches[2];
                    $value = intval($matches[3]);
                    switch ($operator) {
                        case '>': $condition_met = $score > $value; break;
                        case '<': $condition_met = $score < $value; break;
                        case '>=': $condition_met = $score >= $value; break;
                        case '<=': $condition_met = $score <= $value; break;
                        case '=': $condition_met = $score == $value; break;
                        default: $condition_met = false;
                    }
                }
            }

            if ($condition_met) {
                update_user_meta($user_id, '_psych_flag_' . sanitize_key($flag), true);
            }
        }, 10, 3);

        return do_shortcode($content);
    }

    /**
     * AJAX handler for the button click.
     */
    public function ajax_set_flag_from_button() {
        check_ajax_referer('psych_flag_trigger_nonce', 'nonce');

        $user_id = get_current_user_id();
        $flag = sanitize_key($_POST['flag']);

        if ($user_id && !empty($flag)) {
            update_user_meta($user_id, '_psych_flag_' . $flag, true);
            wp_send_json_success(['message' => 'Flag set: ' . $flag]);
        } else {
            wp_send_json_error(['message' => 'Invalid data.']);
        }
    }
}

Psych_Flag_Triggers::get_instance();
?>
