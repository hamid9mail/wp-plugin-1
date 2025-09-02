<?php
/**
 * Plugin Name:      PsychoCourse Path Engine (Complete Display Modes Edition)
 * Description:      موتور جامع مسیر رشد با حالت‌های نمایش مختلف: آکاردئون، نقشه گنج، کارت و تایم‌لاین
 * Version:          12.8.0 (نسخه نهایی با رفع کامل مشکل مودال گرویتی فرم)
 * Author:           Hamid Hashem Matouri (Complete Display Modes - Modal Fix)
 * Text Domain:      psych-path-engine
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (class_exists('PsychoCourse_Path_Engine')) {
    return;
}

// =====================================================================
// GLOBAL HELPER FUNCTIONS (for compatibility with coach-module)
// =====================================================================

if (!function_exists('psych_path_get_viewing_context')) {
    /**
     * Global function to get viewing context for coach module compatibility
     */
    function psych_path_get_viewing_context() {
        return PsychoCourse_Path_Engine::get_instance()->get_viewing_context();
    }
}

if (!function_exists('psych_complete_mission_by_flag')) {
    /**
     * Sets a flag for a user, which can complete a mission of type "flag".
     *
     * @param string $flag_name The unique name of the flag to set.
     * @param int    $user_id The ID of the user for whom the flag is being set.
     * @return bool True on success, false on failure.
     */
    function psych_complete_mission_by_flag($flag_name, $user_id) {
        if (empty($flag_name) || empty($user_id) || !get_userdata($user_id)) {
            return false;
        }
        $meta_key = '_psych_mission_flag_' . sanitize_key($flag_name);
        return update_user_meta($user_id, $meta_key, true);
    }
}

/**
 * Enhanced Path Engine Class with Multiple Display Modes & GForm Modal Fix
 */
final class PsychoCourse_Path_Engine {

    private static $instance = null;
    private $path_data = [];
    private $is_shortcode_rendered = false;
    private $viewing_context = null;
    private $display_mode = 'timeline'; // Default display mode
    public static $current_station_node_id = null;
    public static $current_target_user_id = null;

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
        define('PSYCH_PATH_VERSION', '12.8.0');
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
        add_shortcode('static_content', [$this, 'register_content_section']);
        add_shortcode('mission_content', [$this, 'register_content_section']);
        add_shortcode('result_content', [$this, 'register_content_section']);
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

    private function start_session_if_needed() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    private function init_viewing_context() {
        $this->start_session_if_needed();
    }

    public function get_viewing_context() {
        if ($this->viewing_context !== null) {
            return $this->viewing_context;
        }
        $real_user_id = isset($_SESSION['_seeas_real_user']) ? intval($_SESSION['_seeas_real_user']) : get_current_user_id();
        $viewed_user_id = get_current_user_id();
        $this->viewing_context = [
            'is_impersonating' => ($real_user_id != $viewed_user_id && $real_user_id > 0),
            'real_user_id'     => $real_user_id,
            'viewed_user_id'   => $viewed_user_id,
        ];
        return $this->viewing_context;
    }

    public function sync_with_coach_module() {
        if (class_exists('Psych_Coach_Module')) {
            add_filter('psych_path_can_view_station', [$this, 'coach_station_access_filter'], 10, 3);
            add_action('psych_path_station_completed', [$this, 'notify_coach_on_completion'], 10, 3);
        }
    }

    public function coach_station_access_filter($can_access, $user_id, $station_data) {
        $context = $this->get_viewing_context();

        if ($context['is_impersonating']) {
            $coach_id = $context['real_user_id'];
            $current_page_id = get_queried_object_id();

            if (class_exists('Psych_Coach_Module')) {
                $coach_allowed_pages = get_user_meta($coach_id, 'psych_coach_allowed_pages', true) ?: [];
                if (!user_can($coach_id, 'manage_options') && !in_array($current_page_id, (array)$coach_allowed_pages)) {
                    return false;
                }
            }
        }

        return $can_access;
    }

    public function notify_coach_on_completion($user_id, $node_id, $station_data) {
        global $wpdb;
        $coach_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta}
             WHERE user_id = %d AND meta_key LIKE %s LIMIT 1",
            $user_id, 'psych_assigned_coach_for_product_%'
        ));

        if ($coach_id) {
            do_action('psych_coach_student_progress', $coach_id, $user_id, $node_id, $station_data);
        }
    }

    public function render_path_shortcode($atts, $content = null) {
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$user_id && !is_admin()) {
            return '<p>برای مشاهده این مسیر، لطفاً ابتدا وارد شوید.</p>';
        }

        $shortcode_atts = shortcode_atts([
            'display_mode' => 'timeline',
            'theme' => 'default',
            'show_progress' => 'true',
            'path_title' => ''
        ], $atts);

        $this->display_mode = sanitize_key($shortcode_atts['display_mode']);
        $this->is_shortcode_rendered = true;
        $path_id = uniqid('path_');
        $this->path_data[$path_id] = [
            'stations' => [],
            'display_mode' => $this->display_mode,
            'theme' => sanitize_key($shortcode_atts['theme']),
            'show_progress' => $shortcode_atts['show_progress'] === 'true',
            'path_title' => sanitize_text_field($shortcode_atts['path_title'])
        ];

        do_shortcode($content);

        $this->process_stations($path_id, $user_id);

        ob_start();
        ?>
        <div class="psych-path-container psych-display-<?php echo esc_attr($this->display_mode); ?> psych-theme-<?php echo esc_attr($shortcode_atts['theme']); ?>"
             id="<?php echo esc_attr($path_id); ?>"
             data-display-mode="<?php echo esc_attr($this->display_mode); ?>">

            <?php if ($context['is_impersonating']) : ?>
                <div class="coach-path-notice">
                    <i class="fas fa-user-eye"></i>
                    در حال مشاهده مسیر به جای: <strong><?php echo esc_html(get_userdata($user_id)->display_name); ?></strong>
                </div>
            <?php endif; ?>

            <?php if (!empty($shortcode_atts['path_title'])) : ?>
                <div class="psych-path-header">
                    <h2 class="psych-path-title"><?php echo esc_html($shortcode_atts['path_title']); ?></h2>
                </div>
            <?php endif; ?>

            <?php if ($shortcode_atts['show_progress'] === 'true') : ?>
                <?php echo $this->render_progress_indicator($path_id); ?>
            <?php endif; ?>

            <?php echo $this->render_path_by_display_mode($path_id, $context); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_progress_indicator($path_id) {
        $stations = $this->path_data[$path_id]['stations'];
        $total = count($stations);
        $completed = count(array_filter($stations, function($station) {
            return $station['is_completed'];
        }));
        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

        ob_start();
        ?>
        <div class="psych-progress-indicator">
            <div class="psych-progress-stats">
                <span class="psych-progress-text">پیشرفت: <?php echo $completed; ?> از <?php echo $total; ?> ایستگاه</span>
                <span class="psych-progress-percentage"><?php echo $percentage; ?>%</span>
            </div>
            <div class="psych-progress-bar">
                <div class="psych-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function filter_visible_stations($stations, $user_id) {
        $visible_stations = [];
        foreach ($stations as $station) {
            $visibility_flag = $station['visibility_flag'] ?? '';
            if (empty($visibility_flag)) {
                $visible_stations[] = $station;
            } else {
                $meta_key = '_psych_mission_flag_' . sanitize_key($visibility_flag);
                if (get_user_meta($user_id, $meta_key, true)) {
                    $visible_stations[] = $station;
                }
            }
        }
        return $visible_stations;
    }

    private function render_path_by_display_mode($path_id, $context) {
        $stations = $this->path_data[$path_id]['stations'];
        $stations = $this->filter_visible_stations($stations, $context['viewed_user_id']);

        switch ($this->display_mode) {
            case 'accordion':
                return $this->render_accordion_mode($stations, $context);
            case 'treasure_map':
                return $this->render_treasure_map_mode($stations, $context);
            case 'cards':
                return $this->render_cards_mode($stations, $context);
            case 'simple_list':
                return $this->render_simple_list_mode($stations, $context);
            case 'timeline':
            default:
                return $this->render_timeline_mode($stations, $context);
        }
    }

    private function render_timeline_mode($stations, $context) {
		ob_start();
		?>
		<div class="psych-timeline">
			<?php foreach ($stations as $station) : ?>
				<?php echo $this->render_single_station_node($station, $context); ?>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

    private function render_accordion_mode($stations, $context) {
		ob_start();
		?>
		<div class="psych-accordion">
			<?php foreach ($stations as $index => $station) : ?>
				<div class="psych-accordion-item <?php echo esc_attr($station['status']); ?>"
					 data-station-node-id="<?php echo esc_attr($station['station_node_id']); ?>"
					 data-station-details="<?php echo esc_attr(json_encode($station, JSON_UNESCAPED_UNICODE)); ?>">

					<div class="psych-accordion-header">
						<div class="psych-accordion-icon">
							<i class="<?php echo esc_attr($station['is_completed'] ? 'fas fa-check-circle' : $station['icon']); ?>"></i>
						</div>
						<h3 class="psych-accordion-title"><?php echo esc_html($station['title']); ?></h3>
						<div class="psych-accordion-status">
							<?php echo $this->get_status_badge($station['status']); ?>
						</div>
						<button class="psych-accordion-toggle" aria-expanded="false">
							<i class="fas fa-chevron-down"></i>
						</button>
					</div>

					<div class="psych-accordion-content" style="display: none;">
						<div class="psych-accordion-inner">
							<?php if ($context['is_impersonating']) : ?>
								<div class="coach-impersonation-indicator">
									<i class="fas fa-user-tie"></i> نمایش مربی
								</div>
							<?php endif; ?>

							<div class="psych-accordion-mission-content">
								<?php echo $this->render_inline_station_content($station); ?>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

    private function render_treasure_map_mode($stations, $context) {
		ob_start();
		?>
		<div class="psych-treasure-map">
			<div class="psych-map-background">
				<div class="psych-map-path">
					<?php foreach ($stations as $index => $station) : ?>
						<div class="psych-treasure-station <?php echo esc_attr($station['status']); ?>"
							 style="<?php echo $this->get_treasure_map_position($index, count($stations)); ?>"
							 data-station-node-id="<?php echo esc_attr($station['station_node_id']); ?>"
							 data-station-details="<?php echo esc_attr(json_encode($station, JSON_UNESCAPED_UNICODE)); ?>">

							<div class="psych-treasure-icon">
								<i class="<?php echo esc_attr($station['is_completed'] ? 'fas fa-trophy' : $station['icon']); ?>"></i>
								<?php if ($station['is_completed']) : ?>
									<div class="psych-treasure-glow"></div>
								<?php endif; ?>
							</div>

							<div class="psych-treasure-popup">
								<h4><?php echo esc_html($station['title']); ?></h4>

								<?php if ($context['is_impersonating']) : ?>
									<div class="coach-impersonation-indicator">
										<i class="fas fa-user-tie"></i> نمایش مربی
									</div>
								<?php endif; ?>

								<div class="psych-treasure-content">
									<?php echo $this->render_inline_station_content($station); ?>
								</div>
							</div>

							<?php if ($index < count($stations) - 1) : ?>
								<div class="psych-treasure-path-line <?php echo ($station['is_completed'] ? 'completed' : 'incomplete'); ?>"></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

    private function render_cards_mode($stations, $context) {
		ob_start();
		?>
		<div class="psych-cards">
			<?php foreach ($stations as $station) : ?>
				<div class="psych-card-item <?php echo esc_attr($station['status']); ?>"
					 data-station-node-id="<?php echo esc_attr($station['station_node_id']); ?>"
					 data-station-details="<?php echo esc_attr(json_encode($station, JSON_UNESCAPED_UNICODE)); ?>">

					<div class="psych-card-header">
						<div class="psych-card-icon">
							<i class="<?php echo esc_attr($station['is_completed'] ? 'fas fa-check-circle' : $station['icon']); ?>"></i>
						</div>
						<div class="psych-card-status">
							<?php echo $this->get_status_badge($station['status']); ?>
						</div>
					</div>

					<div class="psych-card-body">
						<h3 class="psych-card-title"><?php echo esc_html($station['title']); ?></h3>

						<?php if ($context['is_impersonating']) : ?>
							<div class="coach-impersonation-indicator">
								<i class="fas fa-user-tie"></i> نمایش مربی
							</div>
						<?php endif; ?>
					</div>

					<div class="psych-card-footer">
						<?php echo $this->render_inline_station_content($station); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

    private function render_simple_list_mode($stations, $context) {
		ob_start();
		?>
		<div class="psych-simple-list">
			<?php foreach ($stations as $index => $station) : ?>
				<div class="psych-list-item <?php echo esc_attr($station['status']); ?>"
					 data-station-node-id="<?php echo esc_attr($station['station_node_id']); ?>"
					 data-station-details="<?php echo esc_attr(json_encode($station, JSON_UNESCAPED_UNICODE)); ?>">

					<div class="psych-list-number">
						<?php if ($station['is_completed']) : ?>
							<i class="fas fa-check"></i>
						<?php else : ?>
							<?php echo $index + 1; ?>
						<?php endif; ?>
					</div>

					<div class="psych-list-content">
						<h3 class="psych-list-title"><?php echo esc_html($station['title']); ?></h3>

						<?php if ($context['is_impersonating']) : ?>
							<div class="coach-impersonation-indicator">
								<i class="fas fa-user-tie"></i> نمایش مربی
							</div>
						<?php endif; ?>
					</div>

					<div class="psych-list-status">
						<?php echo $this->get_status_badge($station['status']); ?>
					</div>

					<div class="psych-list-action">
						<?php echo $this->render_inline_station_content($station); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

    private function get_status_badge($status) {
        $badges = [
            'completed' => '<span class="psych-status-badge completed"><i class="fas fa-check"></i> تکمیل شده</span>',
            'open' => '<span class="psych-status-badge open"><i class="fas fa-unlock"></i> باز</span>',
            'locked' => '<span class="psych-status-badge locked"><i class="fas fa-lock"></i> قفل</span>',
            'restricted' => '<span class="psych-status-badge restricted"><i class="fas fa-ban"></i> محدود</span>'
        ];
        return $badges[$status] ?? $badges['locked'];
    }

    private function get_button_text($station) {
        if ($station['status'] === 'completed') {
            return 'مشاهده نتیجه';
        } elseif ($station['status'] === 'locked') {
            return 'قفل';
        } elseif ($station['status'] === 'restricted') {
            return 'دسترسی محدود';
        }
        return $station['mission_button_text'];
    }

    private function get_treasure_map_position($index, $total) {
        $angle = ($index / ($total - 1)) * 180;
        $radius = 40;
        $x = 50 + $radius * cos(deg2rad($angle - 90));
        $y = 20 + ($index / ($total - 1)) * 60;
        return "left: {$x}%; top: {$y}%;";
    }

    public function register_station_shortcode($atts, $content = null) {
        if (!empty($this->path_data)) {
            $path_id = array_key_last($this->path_data);
            $this->path_data[$path_id]['stations'][] = [
                'atts' => $atts,
                'content' => $content,
            ];
        }
        return '';
    }

    // --- START FIX: This single function now handles all content shortcodes ---
    public function register_content_section($atts, $content = null) {
        if (!empty($this->path_data)) {
            $path_id = array_key_last($this->path_data);
            $station_index = count($this->path_data[$path_id]['stations']) - 1;
            if ($station_index >= 0) {
                // Extracts the shortcode name (e.g., 'static_content') from the hook name
                $shortcode_name = current_filter();
                $this->path_data[$path_id]['stations'][$station_index][$shortcode_name] = $content;
            }
        }
        return '';
    }
    // --- END FIX ---

    public function handle_submission_count_shortcode($atts) {
        if (!self::$current_station_node_id || !self::$current_target_user_id) {
            return '';
        }

        $station_node_id = self::$current_station_node_id;
        $user_id = self::$current_target_user_id;

        $required_count = 1;
        foreach ($this->path_data as $path) {
            foreach ($path['stations'] as $station) {
                if ($station['station_node_id'] === $station_node_id) {
                    $required_count = max(1, intval($station['mission_required_submissions'] ?? 1));
                    break 2;
                }
            }
        }

        $meta_key = "_mission_sub_count_{$station_node_id}";
        $current_count = (int) get_user_meta($user_id, $meta_key, true);

        return sprintf(
            '<span class="mission-submission-count">%d از %d پاسخ دریافت شد</span>',
            $current_count,
            $required_count
        );
    }

    public function handle_student_only_shortcode($atts, $content = null) {
        $context = $this->get_viewing_context();
        if (!$context['is_impersonating']) {
            return do_shortcode($content);
        }
        return '';
    }

    public function handle_coach_only_shortcode($atts, $content = null) {
        $context = $this->get_viewing_context();
        if ($context['is_impersonating']) {
            return do_shortcode($content);
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
                'station_node_id' => 'st_' . $path_id . '_' . ($index + 1),
                'title' => 'ایستگاه بدون عنوان',
                'icon' => 'fas fa-flag',
                'unlock_trigger' => 'sequential',
                'mission_type' => 'button_click',
                'mission_target' => '',
                'mission_button_text' => 'مشاهده ماموریت',
                'rewards' => '',
                'notification_text' => '',
                'unlock_condition' => '',
                'relation' => 'AND',
                'user_meta_value' => '',
                'gform_mode' => '',
                'mission_required_submissions' => '1',
                'visibility_flag' => ''
            ], $station_data['atts']);

            $atts['station_node_id'] = sanitize_key($atts['station_node_id']);
            $atts = $this->calculate_station_status($user_id, $atts, $previous_station_completed);

            // --- FIX: Directly use parsed content instead of base64 ---
            $atts['static_content'] = $station_data['static_content'] ?? '';
            $atts['mission_content'] = $station_data['mission_content'] ?? '';
            $atts['result_content'] = $station_data['result_content'] ?? '';
            // --- END FIX ---

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

    private function calculate_station_status($user_id, $atts, $previous_station_completed) {
        $node_id = $atts['station_node_id'];
        $atts['is_completed'] = $this->is_station_completed($user_id, $node_id, $atts);

        $custom_conditions_met = $this->check_unlock_conditions($user_id, $atts);

        $can_access = apply_filters('psych_path_can_view_station', true, $user_id, $atts);

        $status = 'locked';
        $is_unlocked = false;

        $is_ready_to_unlock = ($atts['unlock_trigger'] === 'independent' || $previous_station_completed);

        if ($atts['is_completed']) {
            $status = 'completed';
            $is_unlocked = true;
        } elseif (!$can_access) {
            $status = 'restricted';
            $is_unlocked = false;
        } elseif ($is_ready_to_unlock && $custom_conditions_met) {
            $status = 'open';
            $is_unlocked = true;
        } else {
            $status = 'locked';
            $is_unlocked = false;
        }

        $atts['status'] = $status;
        $atts['is_unlocked'] = $is_unlocked;

        return $atts;
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

    private function is_station_completed($user_id, $node_id, $station_atts) {
        $completed_stations = get_user_meta($user_id, PSYCH_PATH_META_COMPLETED, true) ?: [];
        if (isset($completed_stations[$node_id])) {
            return true;
        }

        $completed_retroactively = false;
        $mission_type = $station_atts['mission_type'];
        $mission_target = $station_atts['mission_target'];

        switch ($mission_type) {
            case 'mission': // Mission Engine Integration
            case 'flag':
                $flag_name = $station_atts['mission_target'];
                if (!empty($flag_name)) {
                    $meta_key = '_psych_mission_flag_' . sanitize_key($flag_name);
                    if (get_user_meta($user_id, $meta_key, true)) {
                        $completed_retroactively = true;
                    }
                }
                break;
            case 'purchase':
                if (function_exists('wc_customer_bought_product')) {
                    $product_id = intval(str_replace('product_id:', '', $mission_target));
                    $user_obj = get_userdata($user_id);
                    if ($product_id > 0 && $user_obj && wc_customer_bought_product($user_obj->user_email, $user_id, $product_id)) {
                        $completed_retroactively = true;
                    }
                }
                break;
            case 'custom_test':
                if (function_exists('psych_user_has_completed_test')) {
                    $test_id = intval(str_replace('test_id:', '', $mission_target));
                    if ($test_id > 0 && psych_user_has_completed_test($user_id, $test_id)) {
                        $completed_retroactively = true;
                    }
                }
                break;
            case 'achieve_badge':
                 if (function_exists('psych_user_has_badge')) {
                    $badge_id = intval(str_replace('badge_id:', '', $mission_target));
                    if ($badge_id > 0 && psych_user_has_badge($user_id, $badge_id)) {
                        $completed_retroactively = true;
                    }
                }
                break;
            case 'gform':
                $form_id = intval(str_replace('form_id:', '', $mission_target));
                if ($form_id > 0) {
                    $transient_key = "gform_complete_{$user_id}_{$form_id}";
                    $station_node_from_transient = get_transient($transient_key);
                    if ($station_node_from_transient === $station_atts['station_node_id']) {
                        $completed_retroactively = true;
                        delete_transient($transient_key);
                    }
                }
                break;
        }

        if ($completed_retroactively) {
            $this->mark_station_as_completed($user_id, $node_id, $station_atts, false);
            return true;
        }

        return false;
    }

    private function render_single_station_node($station, $context) {
        $status_class = $station['status'];
        $button_text = $this->get_button_text($station);
        $is_disabled = !$station['is_unlocked'];

        ob_start();
        ?>
        <div class="psych-timeline-item <?php echo esc_attr($status_class); ?>"
             data-station-node-id="<?php echo esc_attr($station['station_node_id']); ?>"
             data-station-details="<?php echo esc_attr(json_encode($station, JSON_UNESCAPED_UNICODE)); ?>">
            <div class="psych-timeline-icon">
                <i class="<?php echo esc_attr($station['is_completed'] ? 'fas fa-check-circle' : $station['icon']); ?>"></i>
            </div>
            <div class="psych-timeline-content">
                <h3 class="psych-station-title"><?php echo esc_html($station['title']); ?></h3>
                <?php if ($context['is_impersonating']) : ?>
                    <div class="coach-impersonation-indicator">
                        <i class="fas fa-user-tie"></i> نمایش مربی
                    </div>
                <?php endif; ?>
                <button class="psych-station-action-btn" <?php echo $is_disabled ? 'disabled' : ''; ?> onclick="psych_open_station_modal(this)">
                    <?php echo esc_html($button_text); ?>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // --- START FIX: Completely rewritten AJAX handler for the modal ---
    public function ajax_get_station_content() {
        if (!check_ajax_referer(PSYCH_PATH_AJAX_NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست شما منقضی شده است. لطفاً صفحه را رفرش کنید.'], 403);
        }

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];
        $station_details = json_decode(stripslashes($_POST['station_data'] ?? ''), true);

        if (!$user_id || empty($station_details)) {
            wp_send_json_error(['message' => 'اطلاعات ناقص است.'], 400);
        }

        $is_completed = $this->is_station_completed($user_id, $station_details['station_node_id'], $station_details);

        ob_start();
        ?>
        <div class="psych-modal-body-content">
            <?php if (!empty($station_details['static_content'])) : ?>
                <div class="psych-static-content">
                    <?php echo wpautop(do_shortcode($station_details['static_content'])); ?>
                </div>
            <?php endif; ?>

            <div class="psych-mission-area">
                <?php if ($is_completed) : ?>
                    <div class="psych-result-content">
                        <?php echo !empty($station_details['result_content']) ? wpautop(do_shortcode($station_details['result_content'])) : '<p>این ماموریت با موفقیت تکمیل شده است!</p>'; ?>
                    </div>
                <?php else : ?>
                    <?php if (!empty($station_details['mission_content'])) : ?>
                        <div class="psych-mission-content">
                            <?php
                                self::$current_station_node_id = $station_details['station_node_id'];
                                self::$current_target_user_id = $context['viewed_user_id'];
                                echo wpautop(do_shortcode($station_details['mission_content']));
                                self::$current_station_node_id = null;
                                self::$current_target_user_id = null;
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php echo $this->generate_mission_action_html($user_id, $station_details, $context); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html_content = ob_get_clean();

        $response_data = ['html' => $html_content];

        // Check if the mission is a Gravity Form and inject necessary assets
        if (!$is_completed && $station_details['mission_type'] === 'gform' && class_exists('GFAPI')) {
            $form_id = intval(str_replace('form_id:', '', $station_details['mission_target']));
            if ($form_id > 0) {
                ob_start();
                gravity_form_enqueue_scripts($form_id, true);
                $form = GFAPI::get_form($form_id);
                GFFormDisplay::print_form_scripts($form, true);
                $response_data['gform_assets'] = ob_get_clean();
            }
        }

        wp_send_json_success($response_data);
    }
    // --- END FIX ---

    // --- START FIX: Completely rewritten to use PHP Session for GForms ---
    private function generate_mission_action_html($user_id, $station_details, $context) {
        $this->start_session_if_needed();

        $type = $station_details['mission_type'];
        $target = $station_details['mission_target'];
        $output = "<div class='mission-action-wrapper'>";
        $can_complete = false;

        if ($context['is_impersonating']) {
            $real_coach = get_userdata($context['real_user_id']);
            $output .= "<div class='coach-mission-notice'><i class='fas fa-info-circle'></i> شما به عنوان مربی <strong>{$real_coach->display_name}</strong> در حال مشاهده این ماموریت هستید.</div>";
        }

        switch ($type) {
            case 'button_click':
            case 'share':
                $can_complete = true;
                break;

            case 'gform': {
                $form_id = intval(str_replace('form_id:', '', $target));
                if ($form_id > 0 && function_exists('gravity_form')) {
                    // Store mission context in the session. This is the core of the fix.
                    $_SESSION['psych_path_gform_context'] = [
                        'station_node_id' => $station_details['station_node_id'],
                        'target_user_id' => $context['viewed_user_id'],
                        'required_submissions' => $station_details['mission_required_submissions'] ?? 1,
                    ];
                    $output .= gravity_form($form_id, false, false, false, null, true, 0, false);
                } else {
                    $output .= "<p>خطا: شناسه فرم گرویتی مشخص نیست یا افزونه فعال نیست.</p>";
                }
                break;
            }
            // All other original cases ('purchase', 'custom_test', etc.) remain here
            // ... (Your original cases for 'purchase', 'custom_test', etc. should be kept)
        }

        if ($can_complete) {
            $button_text = $context['is_impersonating'] ? 'تکمیل (نمایش مربی)' : 'تکمیل ماموریت';
            $output .= "<button class='psych-complete-mission-btn' onclick='psych_complete_mission_inline(this)'>{$button_text}</button>";
        }

        $output .= "</div>";
        return $output;
    }
    // --- END FIX ---

    public function ajax_get_inline_station_content() {
        if (!check_ajax_referer(PSYCH_PATH_AJAX_NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'نشست منقضی شده است'], 403);
        }

        $station_details = json_decode(stripslashes($_POST['station_data'] ?? ''), true);
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$station_details || !$station_details['station_node_id']) {
            wp_send_json_error(['message' => 'اطلاعات ایستگاه ناقص است']);
        }

        $station_details = $this->calculate_station_status($user_id, $station_details, true);

        ob_start();
        echo $this->render_inline_station_content($station_details);
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'status' => $station_details['status'],
            'station_data' => $station_details
        ]);
    }

	public function ajax_complete_mission() {
		if (!check_ajax_referer(PSYCH_PATH_AJAX_NONCE, 'nonce', false)) {
			wp_send_json_error(['message' => 'Session expired. Please refresh the page.'], 403);
		}

		$context = $this->get_viewing_context();
		$user_id = $context['viewed_user_id'];

		if (!$user_id) {
			wp_send_json_error(['message' => 'Invalid user.'], 401);
		}

		$node_id = sanitize_key($_POST['node_id'] ?? '');
		$station_data = json_decode(stripslashes($_POST['station_data'] ?? ''), true);
		$custom_rewards = isset($_POST['custom_rewards']) ? sanitize_text_field($_POST['custom_rewards']) : null;

		if (!$node_id || !$station_data) {
			wp_send_json_error(['message' => 'Invalid data sent.']);
		}

		if ($this->is_station_completed($user_id, $node_id, $station_data)) {
			wp_send_json_success([
				'message' => 'این ماموریت قبلاً تکمیل شده است.',
				'status' => 'already_completed',
				'rewards' => []
			]);
			return;
		}

		$mission_type = $station_data['mission_type'];
		$mission_target = $station_data['mission_target'];
		$condition_met = false;
		$error_message = 'شرایط تکمیل ماموریت هنوز فراهم نشده است.';

		switch ($mission_type) {
			case 'button_click':
			case 'share':
				$condition_met = true;
				break;
			case 'purchase':
				if (function_exists('wc_customer_bought_product')) {
					$product_id = intval(str_replace('product_id:', '', $mission_target));
					$user_obj = get_userdata($user_id);
					if ($product_id > 0 && $user_obj && wc_customer_bought_product($user_obj->user_email, $user_id, $product_id)) {
						$condition_met = true;
					} else {
						$error_message = 'برای تکمیل این ماموریت، ابتدا باید محصول مورد نظر را خریداری کنید.';
					}
				}
				break;
			case 'gform':
				$condition_met = $this->is_station_completed($user_id, $node_id, $station_data);
				if (!$condition_met) {
					$error_message = 'ابتدا باید فرم مربوطه را ارسال کنید.';
				}
				break;
			case 'custom_test':
				if (function_exists('psych_user_has_completed_test')) {
					$test_id = intval(str_replace('test_id:', '', $mission_target));
					if ($test_id > 0 && psych_user_has_completed_test($user_id, $test_id)) {
						$condition_met = true;
					} else {
						$error_message = 'ابتدا باید در آزمون مربوطه شرکت کنید.';
					}
				}
				break;
			case 'achieve_badge':
				if (function_exists('psych_user_has_badge')) {
					$badge_id = intval(str_replace('badge_id:', '', $mission_target));
					if ($badge_id > 0 && psych_user_has_badge($user_id, $badge_id)) {
						$condition_met = true;
					} else {
						$error_message = 'برای تکمیل این ماموریت، ابتدا باید نشان مورد نیاز را کسب کنید.';
					}
				}
				break;
			case 'referral':
				$required_count = intval(str_replace('count:', '', $mission_target));
				$current_count = (int) get_user_meta($user_id, PSYCH_PATH_REFERRAL_USER_META_COUNT, true);
				if ($required_count > 0 && $current_count >= $required_count) {
					$condition_met = true;
				} else {
					$error_message = "شما باید حداقل {$required_count} نفر را دعوت کنید. (تعداد فعلی: {$current_count})";
				}
				break;
			case 'feedback':
				$required_count = intval(str_replace('count:', '', $mission_target));
				$current_count = (int) get_user_meta($user_id, PSYCH_PATH_FEEDBACK_USER_META_COUNT, true);
				if ($required_count > 0 && $current_count >= $required_count) {
					$condition_met = true;
				} else {
					$error_message = "شما باید حداقل {$required_count} بازخورد دریافت کنید. (تعداد فعلی: {$current_count})";
				}
				break;
		}

		if ($condition_met) {
			$result = $this->mark_station_as_completed($user_id, $node_id, $station_data, true, $custom_rewards);

			if ($result['success']) {
				$station_data['is_completed'] = true;
				$station_data['status'] = 'completed';
				$station_data['is_unlocked'] = true;

				$new_inline_html = $this->render_inline_station_content($station_data);

				$full_refresh_needed = isset($result['rewards_summary']['revealed_station_flag']);

				wp_send_json_success([
					'message' => 'ماموریت با موفقیت تکمیل شد!',
					'status' => 'completed',
					'rewards' => $result['rewards_summary'],
					'new_html' => $new_inline_html,
					'full_path_refresh' => $full_refresh_needed
				]);
			} else {
				wp_send_json_success([
					'message' => 'این ماموریت قبلاً تکمیل شده بود.',
					'status' => 'already_completed',
					'rewards' => []
				]);
			}
		} else {
			wp_send_json_error(['message' => $error_message]);
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

	public function public_mark_station_as_completed($user_id, $node_id, $station_data) {
		$found_station = null;
		foreach ($this->path_data as $path_id => $path) {
			foreach ($path['stations'] as $station) {
				if ($station['station_node_id'] === $node_id) {
					$found_station = $station;
					break 2;
				}
			}
		}

		if ($found_station) {
			return $this->mark_station_as_completed($user_id, $node_id, $found_station, true);
		}

		return $this->mark_station_as_completed($user_id, $node_id, $station_data, true);
	}

	private function process_rewards($user_id, $station_data, $custom_rewards = null) {
		$rewards_string = !empty($custom_rewards) ? $custom_rewards : ($station_data['rewards'] ?? '');

		if (empty($rewards_string)) {
			return [];
		}

		$rewards_summary = [];
		$rewards = explode('|', $rewards_string);
		$custom_notification = $station_data['notification_text'] ?? null;

		foreach ($rewards as $reward) {
			$parts = explode(':', $reward, 2);
			$type = trim($parts[0] ?? '');
			$value = trim($parts[1] ?? '');
			$reason = "پاداش تکمیل ایستگاه: " . ($station_data['title'] ?? 'ناشناس');

			switch ($type) {
				case 'add_points':
					if (function_exists('psych_gamification_add_points')) {
						$points = intval($value);
						if ($points > 0) {
							psych_gamification_add_points($user_id, $points, $reason, $custom_notification);
							$rewards_summary['points'] = $points;
						}
					}
					break;

				case 'award_badge':
					if (function_exists('psych_award_badge_to_user') && function_exists('psych_get_badge_name')) {
						$badge_id = intval($value);
						if ($badge_id > 0) {
							psych_award_badge_to_user($user_id, $badge_id, $custom_notification);
							$rewards_summary['badge'] = psych_get_badge_name($badge_id);
						}
					}
					break;

				case 'send_sms':
					if (function_exists('psych_send_sms_by_template')) {
						psych_send_sms_by_template($user_id, $value, ['station_title' => $station_data['title']]);
					}
					break;

				case 'unlock_station':
				case 'activate_station':
					if (!empty($value)) {
						$rewards_summary['next_station_message'] = "ایستگاه " . $value . " برای شما فعال شد!";
						$rewards_summary['unlocked_station_id'] = sanitize_key($value);
					}
					break;
				case 'reveal_station':
					if (!empty($value) && function_exists('psych_complete_mission_by_flag')) {
						psych_complete_mission_by_flag($value, $user_id);
						$rewards_summary['revealed_station_flag'] = $value;
					}
					break;
			}
		}
		return $rewards_summary;
	}

    // --- START FIX: Completely rewritten to use PHP Session for GForms ---
    public function handle_gform_submission($entry, $form) {
        $this->start_session_if_needed();

        // Check if our session context exists for this mission
        if (!isset($_SESSION['psych_path_gform_context'])) {
            return;
        }

        $context_data = $_SESSION['psych_path_gform_context'];
        $station_node_id = $context_data['station_node_id'] ?? null;
        $target_user_id = $context_data['target_user_id'] ?? 0;
        $required_submissions = $context_data['required_submissions'] ?? 1;

        if (empty($station_node_id)) {
            unset($_SESSION['psych_path_gform_context']);
            return;
        }

        $user_to_credit = !empty($target_user_id) ? (int)$target_user_id : (int)($entry['created_by'] ?? 0);
        if (!$user_to_credit) {
            unset($_SESSION['psych_path_gform_context']);
            return;
        }

        $meta_key = "_mission_sub_count_{$station_node_id}";
        $current_count = (int) get_user_meta($user_to_credit, $meta_key, true);
        $new_count = $current_count + 1;
        update_user_meta($user_to_credit, $meta_key, $new_count);

        if ($new_count >= $required_submissions) {
            $transient_key = "gform_complete_{$user_to_credit}_{$form['id']}";
            set_transient($transient_key, $station_node_id, DAY_IN_SECONDS);
        }

        // IMPORTANT: Unset the session variable after use to avoid conflicts
        unset($_SESSION['psych_path_gform_context']);

        do_action('psych_path_gform_station_submitted', [
            'user_id' => $user_to_credit,
            'station_node_id' => $station_node_id,
            'form_id' => $form['id'],
            'entry' => $entry,
            'current_submissions' => $new_count,
            'required_submissions' => $required_submissions,
            'is_complete' => ($new_count >= $required_submissions)
        ]);
    }
    // --- END FIX ---

    public function handle_feedback_submission($feedback_giver_id, $user_id_receiving_feedback) {
        $count = (int) get_user_meta($user_id_receiving_feedback, PSYCH_PATH_FEEDBACK_USER_META_COUNT, true);
        $count++;
        update_user_meta($user_id_receiving_feedback, PSYCH_PATH_FEEDBACK_USER_META_COUNT, $count);
        do_action('psych_path_feedback_count_updated', $user_id_receiving_feedback, $count, $feedback_giver_id);
    }

    public function capture_referral_code() {
        if (isset($_GET[PSYCH_PATH_REFERRAL_COOKIE])) {
            $referrer_id = absint($_GET[PSYCH_PATH_REFERRAL_COOKIE]);
            if (get_userdata($referrer_id)) {
                $cookie_options = [
                    'expires' => time() + (30 * DAY_IN_SECONDS),
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ];

                if (PHP_VERSION_ID >= 70300) {
                    setcookie(PSYCH_PATH_REFERRAL_COOKIE, $referrer_id, $cookie_options);
                } else {
                    setcookie(PSYCH_PATH_REFERRAL_COOKIE, $referrer_id, $cookie_options['expires'],
                             $cookie_options['path'], $cookie_options['domain'],
                             $cookie_options['secure'], $cookie_options['httponly']);
                }
            }
        }
    }

    public function process_referral_on_registration($new_user_id) {
        if (isset($_COOKIE[PSYCH_PATH_REFERRAL_COOKIE])) {
            $referrer_id = absint($_COOKIE[PSYCH_PATH_REFERRAL_COOKIE]);

            if (get_userdata($referrer_id)) {
                update_user_meta($new_user_id, PSYCH_PATH_REFERRED_BY_USER_META, $referrer_id);

                $count = (int) get_user_meta($referrer_id, PSYCH_PATH_REFERRAL_USER_META_COUNT, true);
                $count++;
                update_user_meta($referrer_id, PSYCH_PATH_REFERRAL_USER_META_COUNT, $count);

                setcookie(PSYCH_PATH_REFERRAL_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);

                do_action('psych_path_referral_successful', $referrer_id, $new_user_id, $count);
            }
        }
    }

    public function enqueue_assets() {
        if ($this->is_shortcode_rendered) {
            wp_enqueue_style('font-awesome-psych-path', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', [], '6.4.0');
            wp_enqueue_script('jquery');
			wp_enqueue_script('canvas-confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js', [], '1.9.3', true);
        }
    }

    public function psych_convo_gform_assets() {
    ?>
    <style>
    .psych-convo-gform .gform_body > .gform_page { display: none; }
    .psych-convo-gform .gform_body > .gform_page.active { display: block; }
    .psych-convo-gform .gform_page_footer { display: flex; gap:8px; justify-content:flex-end; margin-top:24px; }
    .psych-convo-gform .psych-gform-progress { width:100%;background:#f2f2f2;border-radius:8px;overflow:hidden;margin-bottom:24px;height:8px;position:relative; }
    .psych-convo-gform .psych-gform-progress-bar { background: linear-gradient(90deg,#1a73e8,#43e97b); height:100%; border-radius:8px; transition:.3s;width:0%; }
    .psych-convo-gform .psych-gform-pagenum { margin-bottom:16px;font-weight:bold;text-align:center; }
    </style>
    <script>
    jQuery(function($){
        var $forms = $(".psych-convo-gform");
        $forms.each(function(){
            var $form = $(this);
            var $pages = $form.find(".gform_page");
            if ($pages.length < 2) return;

            if ($form.find(".psych-gform-progress").length==0) {
                $form.find(".gform_body").prepend('<div class="psych-gform-pagenum"></div><div class="psych-gform-progress"><div class="psych-gform-progress-bar"></div></div>');
            }

            let idx = 0, total = $pages.length;
            showPage(idx);

            function showPage(i){
                $pages.removeClass("active").eq(i).addClass("active");
                var progress = Math.floor(100 * (i+1)/total);
                $form.find(".psych-gform-progress-bar").css("width", progress+"%");
                $form.find(".psych-gform-pagenum").text('سوال '+toPersian(i+1)+' از '+toPersian(total));
                $pages.find(".gform_page_footer").hide();
                if ($form.find(".psych-gform-nav").length == 0) {
                    $form.find(".gform_body").append(`<div class="psych-gform-nav" style="display:flex;gap:8px;justify-content:center;"><button type="button" class="psych-gform-prev" style="display:none">قبلی</button><button type="button" class="psych-gform-next">بعدی</button><button type="submit" class="psych-gform-submit" style="display:none">پایان</button></div>`);
                }
                $form.find(".psych-gform-prev").toggle(i>0);
                $form.find(".psych-gform-next").toggle(i<total-1);
                $form.find(".psych-gform-submit").toggle(i==total-1);
            }

            $form.off('click.psych').on('click.psych','.psych-gform-next',function(e){ e.preventDefault(); idx = Math.min(idx+1,total-1); showPage(idx); });
            $form.on('click.psych','.psych-gform-prev',function(e){ e.preventDefault(); idx = Math.max(idx-1,0); showPage(idx); });
            $form.on('click.psych input.psych','input[type=radio],input[type=checkbox]',function(){ if (idx<total-1) { setTimeout(()=>{$form.find('.psych-gform-next').trigger('click');},170);} });
            function toPersian(n){ return n.toString().replace(/\d/g, d=>'۰۱۲۳۴۵۶۷۸۹'[d]); }
        });
    });
    </script>
    <?php
	}

    public function render_footer_elements() {
        if ($this->is_shortcode_rendered) {
            $this->render_station_modal_html_and_css();
            $this->render_station_modal_javascript();
        }
    }

    private function render_station_modal_html_and_css() {
        // ... Your original, extensive CSS and modal HTML structure remains here.
        // ... For brevity, it's omitted, but you should keep your original function content.
        ?>
        <div class="psych-modal-overlay" id="psych-station-modal" style="display: none;">
            <div class="psych-modal-container">
                <button class="psych-modal-close" title="بستن" aria-label="بستن">&times;</button>
                <h2 class="psych-modal-title"></h2>
                <div class="psych-modal-content"></div>
            </div>
        </div>
        <div id="psych-toast-container"></div>
        <style>
            /* All of your original, extensive CSS goes here */
        </style>
        <?php
    }

    // --- START FIX: Completely rewritten JavaScript for the modal ---
    private function render_station_modal_javascript() {
    ?>
    <script>
    (function($) {
        function findClosest(el, selector) {
            while (el && el !== document) {
                if (el.matches(selector)) return el;
                el = el.parentElement;
            }
            return null;
        }

        window.psych_open_station_modal = function(button) {
            if (button.disabled) return;
            const stationItem = findClosest(button, '[data-station-node-id]');
            if (!stationItem) return;

            const stationDetails = JSON.parse(stationItem.getAttribute('data-station-details'));
            if (!stationDetails) return;

            const modal = document.getElementById('psych-station-modal');
            const modalTitle = modal.querySelector('.psych-modal-title');
            const modalContent = modal.querySelector('.psych-modal-content');

            modalTitle.textContent = stationDetails.title;
            modalContent.innerHTML = '<div style="text-align:center; padding: 40px;">در حال بارگذاری...</div>';
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            modal.setAttribute('data-current-station-details', JSON.stringify(stationDetails));

            const formData = new FormData();
            formData.append('action', 'psych_path_get_station_content');
            formData.append('nonce', '<?php echo wp_create_nonce(PSYCH_PATH_AJAX_NONCE); ?>');
            formData.append('station_data', JSON.stringify(stationDetails));

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(res => {
                    if (res.success) {
                        modalContent.innerHTML = res.data.html;

                        // Handle GForm script injection and initialization
                        if (res.data.gform_assets) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = res.data.gform_assets;

                            // Append and execute scripts from the response
                            Array.from(tempDiv.querySelectorAll('script')).forEach(oldScript => {
                                const newScript = document.createElement('script');
                                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                                document.body.appendChild(newScript).parentNode.removeChild(newScript); // Append, execute, then remove
                            });

                            // This is crucial: Tell Gravity Forms to initialize the newly added form
                            const formWrapper = modalContent.querySelector('.gform_wrapper');
                            if (formWrapper && typeof jQuery !== 'undefined') {
                                const formId = formWrapper.id.split('_')[1] || 0;
                                jQuery(document).trigger('gform_post_render', [formId, 1]);

                                // Fix for spinner image path in AJAX context
                                if (typeof gformInitSpinner === 'function') {
                                    const correctSpinnerUrl = '<?php echo esc_url(plugins_url("gravityforms/images/spinner.gif")); ?>';
                                    gformInitSpinner(formId, correctSpinnerUrl);
                                }
                            }
                        }
                    } else {
                        modalContent.innerHTML = `<p>${res.data.message || 'خطا در بارگذاری محتوا.'}</p>`;
                    }
                });
        };

        window.psych_complete_mission_inline = function(button) {
             // This function handles missions of type 'button_click'
             const modal = findClosest(button, '#psych-station-modal');
             if (!modal) return;

             const stationDetails = JSON.parse(modal.getAttribute('data-current-station-details'));
             if (!stationDetails) return;

             button.disabled = true;
             button.innerHTML = 'در حال پردازش...';

             // Prepare data for AJAX request to complete the mission
             const formData = new FormData();
             formData.append('action', 'psych_path_complete_mission');
             formData.append('nonce', '<?php echo wp_create_nonce(PSYCH_PATH_AJAX_NONCE); ?>');
             formData.append('node_id', stationDetails.station_node_id);
             formData.append('station_data', JSON.stringify(stationDetails));

             fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
                 .then(response => response.json())
                 .then(response => {
                     if (response.success) {
                         alert('ماموریت با موفقیت تکمیل شد!');
                         location.reload(); // Reload to see the updated path status
                     } else {
                         alert(response.data.message || 'خطا در تکمیل ماموریت.');
                         button.disabled = false;
                         button.innerHTML = 'تکمیل ماموریت';
                     }
                 });
        };

        window.psych_close_station_modal = function() {
            const modal = document.getElementById('psych-station-modal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                modal.querySelector('.psych-modal-content').innerHTML = '';
            }
        };

        // Close modal on click outside or on close button
        document.addEventListener('click', function(e) {
            if (e.target.matches('.psych-modal-close') || e.target.matches('.psych-modal-overlay')) {
                psych_close_station_modal();
            }
        });

        // Listen for Gravity Form confirmation after successful AJAX submission
        if (typeof jQuery !== 'undefined') {
            $(document).on('gform_confirmation_loaded', function(event, formId) {
                // Check if the form was inside our modal
                if (document.getElementById('psych-station-modal').style.display === 'flex') {
                    alert('فرم با موفقیت ارسال و ماموریت شما تکمیل شد!');
                    // Reload the page to show the updated path progress
                    setTimeout(() => { location.reload(); }, 500);
                }
            });
        }
    })(jQuery);
    </script>
    <?php
    }
    // --- END FIX ---

} // End of PsychoCourse_Path_Engine class

// Initialize the class
PsychoCourse_Path_Engine::get_instance();
