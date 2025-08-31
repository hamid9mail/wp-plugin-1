<?php
/**
 * Plugin Name:       PsychoCourse Path Engine (Complete Display Modes Edition)
 * Description:       موتور جامع مسیر رشد با حالت‌های نمایش مختلف: آکاردئون، نقشه گنج، کارت و تایم‌لاین
 * Version:           12.6.0 (نسخه نهایی و کاملاً اصلاح شده)
 * Author:            Hamid Hashem Matouri (Complete Display Modes)
 * Text Domain:       psych-path-engine
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
     * This is the primary way for external code to integrate with the path engine.
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
 * Enhanced Path Engine Class with Multiple Display Modes
 */
final class PsychoCourse_Path_Engine {

    private static $instance = null;
    private $path_data = [];
    private $is_shortcode_rendered = false;
    private $viewing_context = null;
    private $display_mode = 'timeline'; // Default display mode
	// FLAG SYSTEM: Static properties to hold flag data from wrapper shortcodes
	private static $gform_flags_to_set = [];
	private static $quiz_flags_to_set = [];
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
        define('PSYCH_PATH_VERSION', '12.6.0');
        define('PSYCH_PATH_META_COMPLETED', 'psych_path_completed_stations');
        define('PSYCH_PATH_META_UNLOCK_TIME', 'psych_path_station_unlock_time');
        define('PSYCH_PATH_AJAX_NONCE', 'psych_path_ajax_nonce');

        // Referral System Constants
        define('PSYCH_PATH_REFERRAL_COOKIE', 'psych_referral_user_id');
        define('PSYCH_PATH_REFERRAL_USER_META_COUNT', 'psych_referral_count');
        define('PSYCH_PATH_REFERRED_BY_USER_META', 'referred_by_user_id');

        // Feedback System Constants
        define('PSYCH_PATH_FEEDBACK_USER_META_COUNT', 'psych_feedback_received_count');
    }

    private function add_hooks() {
        // Core Shortcodes
        add_shortcode('psychocourse_path', [$this, 'render_path_shortcode']);
        add_shortcode('station', [$this, 'register_station_shortcode']);

        // Content Section Shortcodes
		add_shortcode('static_content', [$this, 'register_static_content']);
		add_shortcode('mission_content', [$this, 'register_mission_content']);
		add_shortcode('result_content', [$this, 'register_result_content']);

        // Conditional Content Shortcodes
        add_shortcode('student_only', [$this, 'handle_student_only_shortcode']);
        add_shortcode('coach_only', [$this, 'handle_coach_only_shortcode']);
        add_shortcode('mission_submission_count', [$this, 'handle_submission_count_shortcode']);


        // AJAX Handlers
        add_action('wp_ajax_psych_path_get_station_content', [$this, 'ajax_get_station_content']);
        add_action('wp_ajax_psych_path_get_inline_station_content', [$this, 'ajax_get_inline_station_content']);
        add_action('wp_ajax_psych_path_complete_mission', [$this, 'ajax_complete_mission']);

        // Assets and Footer Elements
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_footer_elements']);

        // Integration Hooks
        add_action('gform_after_submission', [$this, 'handle_gform_submission'], 10, 2);
        add_action('psych_feedback_submitted', [$this, 'handle_feedback_submission'], 10, 2);

        // Referral System Hooks
        add_action('init', [$this, 'capture_referral_code']);
        add_action('user_register', [$this, 'process_referral_on_registration'], 10, 1);

        // Coach Module Integration
        add_action('init', [$this, 'sync_with_coach_module'], 5);
    }

    private function init_viewing_context() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
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
	public function register_static_content($atts, $content = null) {
		if (!empty($this->path_data)) {
			$path_id = array_key_last($this->path_data);
			$station_index = count($this->path_data[$path_id]['stations']) - 1;
			if ($station_index >= 0) {
				$this->path_data[$path_id]['stations'][$station_index]['static_content'] = $content;
			}
		}
		return '';
	}

	public function register_mission_content($atts, $content = null) {
		if (!empty($this->path_data)) {
			$path_id = array_key_last($this->path_data);
			$station_index = count($this->path_data[$path_id]['stations']) - 1;
			if ($station_index >= 0) {
				$this->path_data[$path_id]['stations'][$station_index]['mission_content'] = $content;
			}
		}
		return '';
	}

	public function register_result_content($atts, $content = null) {
		if (!empty($this->path_data)) {
			$path_id = array_key_last($this->path_data);
			$station_index = count($this->path_data[$path_id]['stations']) - 1;
			if ($station_index >= 0) {
				$this->path_data[$path_id]['stations'][$station_index]['result_content'] = $content;
			}
		}
		return '';
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
				'station_node_id'     => 'st_' . $path_id . '_' . ($index + 1),
				'title'               => 'ایستگاه بدون عنوان',
				'icon'                => 'fas fa-flag',
				'unlock_trigger'      => 'sequential',
				'mission_type'        => 'button_click',
				'mission_target'      => '',
				'mission_button_text' => 'مشاهده ماموریت',
				'rewards'             => '',
				'notification_text'   => '',
				'unlock_condition'    => '',
				'relation'            => 'AND',
				'user_meta_value'     => '',
				'gform_mode'          => '',
				'mission_required_submissions' => '1',
				'visibility_flag'     => '',
                'allowed_actors'      => 'self',
                'required_actors'     => 1,
                'activity_type'       => ''
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

    private function get_assigned_coach($user_id) {
        if (!$user_id) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s LIMIT 1",
            $user_id,
            'psych_assigned_coach_for_product_%'
        ));
    }

    private function check_actor_permissions($station_atts) {
        $context = $this->get_viewing_context();
        $target_user_id = $context['viewed_user_id'];
        $actor_user_id = $context['real_user_id'];
        $allowed_actors = $station_atts['allowed_actors'] ?? 'self';

        // Mission engine missions have their own check, so we bypass this for them.
        if (($station_atts['mission_type'] ?? '') === 'mission') {
            return true;
        }

        $allowed = array_map('trim', explode(',', $allowed_actors));

        // Self
        if ($target_user_id == $actor_user_id && in_array('self', $allowed)) return true;

        // Coach
        $coach_id = $this->get_assigned_coach($target_user_id);
        if ($coach_id && $coach_id == $actor_user_id && in_array('coach', $allowed)) return true;

        // Any logged-in user (but not self)
        if ($actor_user_id > 0 && $target_user_id != $actor_user_id && in_array('users', $allowed)) return true;

        // Guests
        if ($actor_user_id == 0 && in_array('guests', $allowed)) return true;

        return false;
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
            case 'mission':
                $flag_name = $station_atts['station_node_id'];
                if (!empty($flag_name)) {
                    $meta_key = '_psych_mission_flag_' . sanitize_key($flag_name);
                    if (get_user_meta($user_id, $meta_key, true)) {
                        $completed_retroactively = true;
                    }
                }
                break;
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

    public function ajax_get_station_content() {
    if (!check_ajax_referer(PSYCH_PATH_AJAX_NONCE, 'nonce', false)) {
        wp_send_json_error(['message' => 'نشست شما منقضی شده است. لطفاً صفحه را رفرش کنید.'], 403);
    }

    $context = $this->get_viewing_context();
    $user_id = $context['viewed_user_id'];
    if (!$user_id) {
        wp_send_json_error(['message' => 'کاربر نامعتبر.'], 401);
    }

    $station_details = json_decode(stripslashes($_POST['station_data'] ?? ''), true);
    if (empty($station_details)) {
        wp_send_json_error(['message' => 'اطلاعات ایستگاه ناقص است.']);
    }

    $content = base64_decode($station_details['raw_content_b64']);
    $is_completed = $this->is_station_completed($user_id, $station_details['station_node_id'], $station_details);

    $form_id = 0;
    if (isset($station_details['mission_type']) && $station_details['mission_type'] === 'gform') {
        $form_id = intval(str_replace('form_id:', '', $station_details['mission_target']));
    }

    ob_start();
    $this->render_modal_content($content, $is_completed, $user_id, $station_details, $context);
    $html_content = ob_get_clean();

    $response_data = ['html' => $html_content];

    if ($form_id > 0 && class_exists('GFAPI') && class_exists('GFFormDisplay')) {
        // --- START CORRECTED PHP FIX ---
        ob_start();

        // 1. اسکریپت‌ها و استایل‌های اصلی فرم را در صف قرار می‌دهیم
        gravity_form_enqueue_scripts($form_id, true);

        // 2. استایل‌ها را چاپ می‌کنیم
        wp_print_styles(array('gform_theme_foundation', 'gform_theme_orbital'));

        // 3. اسکریپت‌های در صف قرار گرفته را چاپ می‌کنیم
        wp_print_scripts('gform_gravityforms_theme');

        // 4. مهم‌ترین بخش: اسکریپت‌های راه‌انداز (init scripts) را که شامل nonce و تنظیمات است، استخراج می‌کنیم
        $form = GFAPI::get_form($form_id);
        GFFormDisplay::print_form_scripts($form, true); // پارامتر true برای حالت AJAX است

        $scripts_and_styles = ob_get_clean();

        $response_data['assets'] = $scripts_and_styles;
        // --- END CORRECTED PHP FIX ---
    }

    wp_send_json_success($response_data);


}
	// --- START FIX: تابع اصلاح شده render_modal_content ---
	// این نسخه دیگر نیازی به افزودن فیلترهای گرویتی فرم ندارد
	private function render_modal_content($content, $is_completed, $user_id, $station_details, $context) {
    // --- START FIX: بازگشت به منطق اصلی و صحیح برای استخراج محتوا ---
    preg_match('/(?:\$\$static_content\$\$|\[static_content\])(.*?)(?:\$\$\/static_content\$\$|\[\/static_content\])/s', $content, $static_match);
    preg_match('/(?:\$\$mission_content\$\$|\[mission_content\])(.*?)(?:\$\$\/mission_content\$\$|\[\/mission_content\])/s', $content, $mission_match);
    preg_match('/(?:\$\$result_content\$\$|\[result_content\])(.*?)(?:\$\$\/result_content\$\$|\[\/result_content\])/s', $content, $result_match);

    // بخش استاتیک (توضیحات اولیه) همیشه نمایش داده می‌شود
    if (!empty($static_match[1])) {
        echo '<div class="psych-static-content">' . wpautop(do_shortcode($static_match[1])) . '</div>';
    }

    echo '<div class="psych-mission-area">';
    // اگر ماموریت قبلاً تکمیل شده باشد، محتوای نتیجه را نمایش بده
    if ($is_completed) {
        echo '<div class="psych-result-content">';
        if (!empty($result_match[1])) {
            echo wpautop(do_shortcode($result_match[1]));
        } else {
            echo '<p>این ماموریت با موفقیت تکمیل شده است!</p>';
        }
        echo '</div>';
    }
    // در غیر این صورت، محتوای ماموریت و فرم/دکمه را نمایش بده
    else {
        if (!empty($mission_match[1])) {
            self::$current_station_node_id = $station_details['station_node_id'];
            self::$current_target_user_id = $context['viewed_user_id'];

            echo '<div class="psych-mission-content">' . wpautop(do_shortcode($mission_match[1])) . '</div>';

            self::$current_station_node_id = null;
            self::$current_target_user_id = null;
        }
        // این تابع مسئول نمایش فرم گرویتی یا دکمه تکمیل ماموریت است
        echo $this->generate_mission_action_html($user_id, $station_details, $context);
    }
    echo '</div>';
    // --- END FIX ---
}


	// --- START FIX: تابع اصلاح شده render_inline_station_content ---
	// این نسخه دیگر نیازی به افزودن فیلترهای گرویتی فرم ندارد
	private function render_inline_station_content($station) {
		$content = base64_decode($station['raw_content_b64']);
		preg_match('/(?:\$\$static_content\$\$|\[static_content\])(.*?)(?:\$\$\/static_content\$\$|\[\/static_content\])/s', $content, $static_matches);
		preg_match('/(?:\$\$mission_content\$\$|\[mission_content\])(.*?)(?:\$\$\/mission_content\$\$|\[\/mission_content\])/s', $content, $mission_matches);
		preg_match('/(?:\$\$result_content\$\$|\[result_content\])(.*?)(?:\$\$\/result_content\$\$|\[\/result_content\])/s', $content, $result_matches);
		$static_content = isset($static_matches[1]) ? trim($static_matches[1]) : '';
		$mission_content = isset($mission_matches[1]) ? trim($mission_matches[1]) : '';
		$result_content  = isset($result_matches[1]) ? trim($result_matches[1]) : '';
		$context = $this->get_viewing_context();
		$user_id = $context['viewed_user_id'];
		ob_start();
		?>
		<div class="psych-inline-station-content">
			<?php if (!$station['is_unlocked']) : ?>
				<div class="psych-locked-station-inline">
					<div class="psych-lock-icon"><i class="fas fa-lock"></i></div>
					<div class="psych-lock-message">
						<h4>ایستگاه قفل است</h4>
						<p>برای باز کردن این ایستگاه، ابتدا ایستگاه‌های قبلی را تکمیل کنید.</p>
					</div>
				</div>
			<?php else : ?>
				<?php if (!empty($static_content)) : ?>
					<div class="psych-static-section"><?php echo do_shortcode($static_content); ?></div>
				<?php endif; ?>
				<?php if ($station['is_completed']) : ?>
					<div class="psych-result-section">
						<div class="psych-result-badge">
							<i class="fas fa-check-circle"></i>
							<span>تکمیل شده</span>
						</div>
						<?php echo !empty($result_content) ? do_shortcode($result_content) : '<p>این ماموریت تکمیل شده است.</p>'; ?>
					</div>
				<?php else : ?>
					<?php if (!empty($mission_content)) : ?>
						<div class="psych-mission-section">
							<div class="psych-mission-instructions">
								<?php
								self::$current_station_node_id = $station['station_node_id'];
								self::$current_target_user_id = $context['viewed_user_id'];
								echo do_shortcode($mission_content);
								self::$current_station_node_id = null;
								self::$current_target_user_id = null;
								?>
							</div>
						</div>
					<?php endif; ?>
					<div class="psych-mission-actions">
						<?php echo $this->generate_mission_action_html($user_id, $station, $context); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
	// --- END FIX ---

	// --- START FIX: تابع کاملاً بازنویسی شده برای مدیریت گرویتی فرم با PHP Session ---
	private function generate_mission_action_html($user_id, $station_details, $context) {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			@session_start();
		}

		$type = $station_details['mission_type'];
		$target = $station_details['mission_target'];
		$output = "<div class='mission-action-wrapper'>";
		$can_complete = false;

		if ($context['is_impersonating']) {
			$real_coach = get_userdata($context['real_user_id']);
			$output .= "<div class='coach-mission-notice'><i class='fas fa-info-circle'></i> شما به عنوان مربی <strong>{$real_coach->display_name}</strong> در حال مشاهده این ماموریت هستید.</div>";
		}

		switch ($type) {
			case 'mission':
				$mission_id = $station_details['mission_target'];
				$activity_type = $station_details['activity_type'];

				if (empty($mission_id) || empty($activity_type)) {
					$output .= "<p>خطا: شناسه ماموریت یا نوع فعالیت برای این ایستگاه مشخص نشده است.</p>";
				} else {
					$rewards_for_mission = $station_details['rewards'] ?? '';
					$flag_on_complete = $station_details['station_node_id']; // The flag is the station ID itself

					$mission_shortcode = sprintf(
						'[psych_mission id="%s" mission_type="%s" allowed_actors="%s" required_actors="%d" sets_flag_on_complete="%s" rewards="%s"]',
						esc_attr($mission_id),
						esc_attr($activity_type),
						esc_attr($station_details['allowed_actors']),
						absint($station_details['required_actors']),
						esc_attr($flag_on_complete),
						esc_attr($rewards_for_mission)
					);
					$output .= do_shortcode($mission_shortcode);
				}
				break;

			case 'button_click':
			case 'share':
				$can_complete = true;
				break;

			case 'gform': {
				$form_id = intval(str_replace('form_id:', '', $target));
				if ($form_id > 0 && function_exists('gravity_form')) {
					// ذخیره اطلاعات ایستگاه در سشن برای استفاده پس از ارسال فرم
					if (!isset($_SESSION['psych_path_gform_context'])) {
						$_SESSION['psych_path_gform_context'] = [];
					}
					$_SESSION['psych_path_gform_context'][$form_id] = [
						'station_node_id' => $station_details['station_node_id'],
						'target_user_id' => $context['viewed_user_id'],
						'required_submissions' => $station_details['mission_required_submissions'] ?? 1,
					];

					// رندر فرم
					$output .= gravity_form($form_id, false, false, false, null, true, 0, false);

				} else {
					$output .= "<p>خطا: شناسه فرم گرویتی مشخص نیست یا افزونه فعال نیست.</p>";
				}
				break;
			}

			case 'purchase':
				if (function_exists('wc_get_product')) {
					$product_id = intval(str_replace('product_id:', '', $target));
					$product = wc_get_product($product_id);
					if ($product) {
						$user_obj = get_userdata($user_id);
						if ($user_obj && wc_customer_bought_product($user_obj->user_email, $user_id, $product_id)) {
							 $output .= "<p>این محصول قبلاً خریداری شده است.</p>";
							 $can_complete = true;
						} else {
							 $output .= "<p>برای تکمیل ماموریت، باید محصول زیر را خریداری کنید:</p>";
							 $output .= "<a href='" . esc_url($product->get_permalink()) . "' target='_blank' class='psych-mission-link-btn'>خرید " . esc_html($product->get_name()) . "</a>";
							 if ($context['is_impersonating']) {
								 $coach_ref_url = add_query_arg('coach_ref', $context['real_user_id'], $product->get_permalink());
								 $output .= "<p><small>لینک با ارجاع مربی: <a href='" . esc_url($coach_ref_url) . "' target='_blank'>کلیک کنید</a></small></p>";
							 }
						}
					} else {
						$output .= "<p>خطا: محصول با شناسه مشخص شده یافت نشد.</p>";
					}
				}
				break;

			case 'custom_test':
				if (function_exists('psych_user_has_completed_test') && function_exists('psych_get_test_link')) {
					$test_id = intval(str_replace('test_id:', '', $target));
					if ($test_id > 0) {
						if (psych_user_has_completed_test($user_id, $test_id)) {
							$output .= "<p>این آزمون با موفقیت تکمیل شده است.</p>";
							$can_complete = true;
						} else {
							$test_link = psych_get_test_link($test_id);
							$output .= "<p>برای تکمیل این ماموریت، باید در آزمون زیر شرکت کنید:</p>";
							$output .= "<a href='" . esc_url($test_link) . "' target='_blank' class='psych-mission-link-btn'>شروع آزمون</a>";
						}
					} else {
						$output .= "<p>خطا: شناسه آزمون مشخص نشده است.</p>";
					}
				} else {
					$output .= "<p>خطا: ماژول آزمون‌های روانشناسی به درستی پیکربندی نشده است.</p>";
				}
				break;

			case 'achieve_badge':
				if (function_exists('psych_user_has_badge') && function_exists('psych_get_badge_name')) {
					$badge_id = intval(str_replace('badge_id:', '', $target));
					if ($badge_id > 0) {
						if (psych_user_has_badge($user_id, $badge_id)) {
							$output .= "<p>تبریک! نشان مورد نیاز کسب شده است.</p>";
							$can_complete = true;
						} else {
							$badge_name = psych_get_badge_name($badge_id);
							$gamification_center_url = home_url('/gamification-center/');
							$output .= "<p>برای تکمیل این ماموریت، باید نشان <strong>'" . esc_html($badge_name) . "'</strong> را کسب کنید.</p>";
							$output .= "<a href='" . esc_url($gamification_center_url) . "' target='_blank' class='psych-mission-link-btn'>بررسی نحوه کسب نشان</a>";
						}
					} else {
						 $output .= "<p>خطا: شناسه نشان مشخص نشده است.</p>";
					}
				} else {
					$output .= "<p>خطا: ماژول گیمیفیکیشن (نشان‌ها) به درستی پیکربندی نشده است.</p>";
				}
				break;

			case 'referral':
				$required_count = intval(str_replace('count:', '', $target));
				$current_count = (int) get_user_meta($user_id, PSYCH_PATH_REFERRAL_USER_META_COUNT, true);
				$referral_link = add_query_arg(PSYCH_PATH_REFERRAL_COOKIE, $user_id, home_url('/'));

				$output .= "<p>تعداد دعوت‌های موفق: <strong>{$current_count} از {$required_count}</strong></p>";
				$output .= "<p>لینک دعوت اختصاصی:</p>";
				$output .= "<div class='psych-referral-box'>";
				$output .= "<input type='text' readonly value='" . esc_url($referral_link) . "' id='psych-referral-link-input-{$station_details['station_node_id']}'>";
				$output .= "<button class='psych-copy-btn' data-target='#psych-referral-link-input-{$station_details['station_node_id']}'>کپی</button>";
				$output .= "</div>";

				if ($current_count >= $required_count) {
					$output .= "<p style='color:green;'>شما به حد نصاب دعوت رسیده‌اید!</p>";
					$can_complete = true;
				}
				break;

			case 'feedback':
				 $required_count = intval(str_replace('count:', '', $target));
				 $current_count = (int) get_user_meta($user_id, PSYCH_PATH_FEEDBACK_USER_META_COUNT, true);
				 $feedback_link = add_query_arg(['for_user' => $user_id], home_url('/feedback-form/'));

				 $output .= "<p>تعداد بازخوردهای دریافت شده: <strong>{$current_count} از {$required_count}</strong></p>";
				 $output .= "<p>این لینک را برای دوستانی که می‌خواهید از آنها بازخورد بگیرید، ارسال کنید:</p>";
				 $output .= "<div class='psych-referral-box'>";
				 $output .= "<input type='text' readonly value='" . esc_url($feedback_link) . "' id='psych-feedback-link-input-{$station_details['station_node_id']}'>";
				 $output .= "<button class='psych-copy-btn' data-target='#psych-feedback-link-input-{$station_details['station_node_id']}'>کپی</button>";
				 $output .= "</div>";

				 if ($current_count >= $required_count) {
					 $output .= "<p style='color:green;'>شما به تعداد بازخورد مورد نیاز رسیده‌اید!</p>";
					 $can_complete = true;
				 }
				 break;
		}

		if ($can_complete) {
			$disabled_attr = ($type === 'share') ? 'disabled' : '';
			$button_text = $context['is_impersonating'] ? 'تکمیل (نمایش مربی)' : 'تکمیل ماموریت';
			$output .= "<button class='psych-complete-mission-btn' {$disabled_attr} onclick='psych_complete_mission_inline(this)'>{$button_text}</button>";
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

				$updates = [
                    [
                        'node_id' => $node_id,
                        'html' => $new_inline_html,
                        'status' => 'completed',
						'station_data' => $station_data
                    ]
                ];

				wp_send_json_success([
					'message' => 'ماموریت با موفقیت تکمیل شد!',
					'status' => 'completed',
					'rewards' => $result['rewards_summary'],
					'updates' => $updates,
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

    // --- START DEPRECATION: این تابع دیگر استفاده نمی‌شود و منطق آن با روش مطمئن‌تر PHP Session جایگزین شده است ---
    // طبق درخواست شما حذف نمی‌شود و فقط کامنت‌گذاری شده است.
    /*
    public function add_mission_hidden_fields($form) {
        if (self::$current_station_node_id && self::$current_target_user_id) {
            // Hidden field for Station Node ID
            $station_field = new GF_Field_Hidden([
                'id' => 998,
                'inputName' => 'station_node_id_hidden',
                'defaultValue' => self::$current_station_node_id,
            ]);

            // Hidden field for Target User ID
            $user_field = new GF_Field_Hidden([
                'id' => 997,
                'inputName' => 'psych_target_user_id',
                'defaultValue' => self::$current_target_user_id,
            ]);

			$required_submissions = 1;
			foreach ($this->path_data as $path) {
				foreach ($path['stations'] as $station) {
					if ($station['station_node_id'] === self::$current_station_node_id) {
						$required_submissions = $station['mission_required_submissions'] ?? 1;
						break 2;
					}
				}
			}

			$submissions_field = new GF_Field_Hidden([
				'id' => 996,
				'inputName' => 'psych_required_submissions',
				'defaultValue' => $required_submissions,
			]);

            $form['fields'][] = $station_field;
            $form['fields'][] = $user_field;
			$form['fields'][] = $submissions_field;
        }
        return $form;
    }
    */
    // --- END DEPRECATION ---

	// --- START FIX: تابع کاملاً بازنویسی شده برای مدیریت گرویتی فرم با PHP Session ---
    public function handle_gform_submission($entry, $form) {
        if (!class_exists('GFForms')) return;

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        $station_node_id = null;
        $target_user_id = 0;
        $required_submissions = 1;
        $form_id = $form['id'];

        // خواندن اطلاعات ایستگاه از سشن که در زمان رندر فرم ذخیره شده است
        if (isset($_SESSION['psych_path_gform_context'][$form_id])) {
            $context_data = $_SESSION['psych_path_gform_context'][$form_id];
            $station_node_id = $context_data['station_node_id'] ?? null;
            $target_user_id = $context_data['target_user_id'] ?? 0;
            $required_submissions = $context_data['required_submissions'] ?? 1;
            // پس از خواندن، متغیر را پاک می‌کنیم تا در ارسال‌های بعدی تداخل ایجاد نکند
            unset($_SESSION['psych_path_gform_context'][$form_id]);
        }

        if (empty($station_node_id)) {
            return; // این یک فرم ماموریت متصل به مسیر نیست
        }

        $user_to_credit = !empty($target_user_id) && get_userdata($target_user_id) !== false
            ? (int) $target_user_id
            : (int) ($entry['created_by'] ?? 0);

        if (!$user_to_credit) {
            return;
        }

        $meta_key = "_mission_sub_count_{$station_node_id}";
        $current_count = (int) get_user_meta($user_to_credit, $meta_key, true);
        $new_count = $current_count + 1;
        update_user_meta($user_to_credit, $meta_key, $new_count);

        if ($new_count >= $required_submissions) {
            // استفاده از Transient برای اینکه موتور مسیر بتواند تکمیل شدن را تشخیص دهد
            $transient_key = "gform_complete_{$user_to_credit}_{$form['id']}";
            set_transient($transient_key, $station_node_id, DAY_IN_SECONDS);
        }

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
    ?>
    <div class="psych-modal-overlay" id="psych-station-modal" style="display: none;">
    <div class="psych-modal-container">
        <button class="psych-modal-close" title="بستن" aria-label="بستن">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 6L6 18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M6 6L18 18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
        <h2 class="psych-modal-title"></h2>
        <div class="psych-modal-content"></div>
    </div>
</div>
<div id="psych-toast-container"></div>
        <style>
            /* ================================================================= */
            /* Enhanced CSS with Mobile-First Approach & Multiple Display Modes */
            /* ================================================================= */

            /* CSS Variables for Theming (Modern Standard) */
            /* ================================================================= */
/* PSYCH PATH ENGINE - COMPLETE CSS STYLES */
/* ================================================================= */

/* CSS Variables for Theming */
:root {
    --psych-primary-color: #007bff;
    --psych-primary-hover: #0056b3;
    --psych-success-color: #28a745;
    --psych-success-hover: #1e7e34;
    --psych-danger-color: #dc3545;
    --psych-danger-hover: #c82333;
    --psych-warning-color: #ffc107;
    --psych-warning-hover: #e0a800;
    --psych-info-color: #17a2b8;
    --psych-info-hover: #138496;
    --psych-gray-100: #f8f9fa;
    --psych-gray-200: #e9ecef;
    --psych-gray-300: #dee2e6;
    --psych-gray-400: #ced4da;
    --psych-gray-500: #adb5bd;
    --psych-gray-600: #6c757d;
    --psych-gray-700: #495057;
    --psych-gray-800: #343a40;
    --psych-gray-900: #212529;
    --psych-white: #ffffff;
    --psych-black: #000000;
    --psych-font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    --psych-border-radius: 8px;
    --psych-border-radius-lg: 12px;
    --psych-border-radius-xl: 16px;
    --psych-box-shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
    --psych-box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    --psych-box-shadow-lg: 0 8px 25px rgba(0,0,0,0.2);
    --psych-transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --psych-animation-slide-up: slideUpFadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}
/* فایل: path-engine.php، داخل تگ <style> */

.psych-rewards-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000002;
    animation: fadeIn 0.3s ease-out;
}
.psych-rewards-popup {
    background: white;
    border-radius: 15px;
    padding: 0;
    width: 90%;
    max-width: 400px;
    text-align: center;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    animation: slideUpFadeIn 0.4s ease-out;
}
.psych-rewards-header {
    background: linear-gradient(135deg, #8E44AD, #9B59B6);
    color: white;
    padding: 20px;
    border-radius: 15px 15px 0 0;
}
.psych-rewards-header i { font-size: 48px; margin-bottom: 10px; }
.psych-rewards-header h3 { margin: 0; font-size: 22px; }
.psych-rewards-body { padding: 25px; }
.psych-rewards-body ul { list-style: none; padding: 0; margin: 0; text-align: right; }
.psych-rewards-body li {
    font-size: 16px;
    color: #34495e;
    padding: 10px 0;
    border-bottom: 1px solid #ecf0f1;
}
.psych-rewards-body li:last-child { border-bottom: none; }
.psych-rewards-body li i { color: #27ae60; margin-left: 10px; }
.psych-rewards-close {
    background: #27ae60;
    color: white;
    border: none;
    width: 100%;
    padding: 15px;
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    border-radius: 0 0 15px 15px;
    transition: background 0.2s;
}
.psych-rewards-close:hover { background: #229954; }
#psych-toast-container {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 1000001;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .psych-toast {
            display: none; /* Initially hidden */
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .psych-toast.success { background: linear-gradient(135deg, #28a745, #20a038); }
        .psych-toast.error { background: linear-gradient(135deg, #dc3545, #e04353); }
        .psych-toast i { font-size: 20px; margin-right: 8px; }

/* ================================================================= */
/* ANIMATIONS */
/* ================================================================= */

@keyframes slideUpFadeIn {
    0% {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes fadeIn {
    0% { opacity: 0; }
    100% { opacity: 1; }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

@keyframes timelineSlideIn {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ================================================================= */
/* BASE CONTAINER STYLES */
/* ================================================================= */

.psych-path-container {
    font-family: var(--psych-font-family);
    max-width: 100%;
    width: 100%;
    margin: 20px auto;
    padding: 16px;
    box-sizing: border-box;
}

/* Coach Notice */
.coach-path-notice {
    background: linear-gradient(135deg, #8E44AD, #9B59B6);
    color: var(--psych-white);
    padding: 16px 20px;
    border-radius: var(--psych-border-radius);
    margin-bottom: 24px;
    text-align: center;
    font-weight: 600;
    box-shadow: var(--psych-box-shadow-sm);
    font-size: 14px;
}
.coach-path-notice i {
    margin-right: 8px;
    font-size: 16px;
}

/* ================================================================= */
/* PROGRESS INDICATOR - نشانگر پیشرفت زیبا */
/* ================================================================= */

.psych-progress-indicator {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
    margin: 20px 0 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.psych-progress-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.psych-progress-text {
    font-size: 14px;
    color: #495057;
    font-weight: 500;
}

.psych-progress-percentage {
    font-size: 18px;
    font-weight: 700;
    color: #007bff;
}

.psych-progress-bar {
    width: 100%;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.psych-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #28a745);
    border-radius: 4px;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.psych-progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
}

/* ================================================================= */
/* PATH HEADER - هدر مسیر */
/* ================================================================= */

.psych-path-header {
    text-align: center;
    margin: 0 0 32px;
    padding: 24px;
    background: linear-gradient(135deg, #f8f9fa, #ffffff);
    border-radius: 12px;
    border: 1px solid #e9ecef;
}

.psych-path-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #212529;
    text-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

/* ================================================================= */
/* TIMELINE MODE - حالت تایم‌لاین کلاسیک */
/* ================================================================= */

.psych-timeline {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding: 20px 0;
    position: relative;
}

/* Timeline Connection Line */
.psych-timeline::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(to bottom, var(--psych-gray-300), var(--psych-primary-color));
    transform: translateX(-50%);
    z-index: 0;
}

.psych-timeline-item {
    background: var(--psych-white);
    border-radius: var(--psych-border-radius-lg);
    padding: 24px;
    box-shadow: var(--psych-box-shadow-sm);
    border: 1px solid var(--psych-gray-200);
    transition: var(--psych-transition);
    position: relative;
    margin: 0 auto;
    max-width: 600px;
    margin-left: calc(50% + 40px); /* Default for odd items */
    width: calc(50% - 40px);
    z-index: 1;
    animation: timelineSlideIn 0.6s ease-out forwards;
}

.psych-timeline-item:nth-child(even) {
    margin-left: 0;
    margin-right: calc(50% + 40px); /* For even items */
}

.psych-timeline-item:nth-child(1) { animation-delay: 0.1s; }
.psych-timeline-item:nth-child(2) { animation-delay: 0.2s; }
.psych-timeline-item:nth-child(3) { animation-delay: 0.3s; }
.psych-timeline-item:nth-child(4) { animation-delay: 0.4s; }
.psych-timeline-item:nth-child(5) { animation-delay: 0.5s; }

/* Timeline Connection Points */
.psych-timeline-item::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 20px;
    width: 20px;
    height: 20px;
    background: var(--psych-white);
    border: 3px solid var(--psych-gray-300);
    border-radius: 50%;
    transform: translateX(-50%);
    z-index: 2;
    transition: var(--psych-transition);
}

.psych-timeline-item.completed::before {
    background: var(--psych-success-color);
    border-color: var(--psych-success-color);
    box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.2);
}

.psych-timeline-item.open::before {
    background: var(--psych-primary-color);
    border-color: var(--psych-primary-color);
    box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.2);
}

.psych-timeline-item.locked::before {
    background: var(--psych-gray-400);
    border-color: var(--psych-gray-400);
}

/* Timeline Icons */
.psych-timeline-icon {
    position: absolute;
    left: -21px;
    top: 4px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--psych-white);
    font-size: 16px;
    font-weight: bold;
    transition: var(--psych-transition);
    box-shadow: var(--psych-box-shadow-sm);
    border: 3px solid var(--psych-white);
}

/* Status-based Icon Colors */
.psych-timeline-item.open .psych-timeline-icon {
    background-color: var(--psych-primary-color);
    animation: pulse 2s infinite;
}
.psych-timeline-item.locked .psych-timeline-icon {
    background-color: var(--psych-gray-600);
}
.psych-timeline-item.completed .psych-timeline-icon {
    background-color: var(--psych-success-color);
    animation: none;
}
.psych-timeline-item.restricted .psych-timeline-icon {
    background-color: var(--psych-danger-color);
}

/* Timeline Content Cards */
.psych-timeline-content {
    background: var(--psych-white);
    padding: 20px;
    border-radius: var(--psych-border-radius-lg);
    box-shadow: var(--psych-box-shadow);
    border: 1px solid var(--psych-gray-200);
    transition: var(--psych-transition);
    position: relative;
    overflow: hidden;
}

.psych-timeline-content::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--psych-primary-color), var(--psych-success-color));
    opacity: 0;
    transition: var(--psych-transition);
}

.psych-timeline-item.open .psych-timeline-content::before,
.psych-timeline-item.completed .psych-timeline-content::before {
    opacity: 1;
}

.psych-timeline-content:hover {
    box-shadow: var(--psych-box-shadow-lg);
    transform: translateY(-2px);
}

/* Station Title */
.psych-station-title {
    margin: 0 0 12px;
    font-size: 18px;
    font-weight: 700;
    color: var(--psych-gray-800);
    line-height: 1.4;
}

/* ================================================================= */
/* ACCORDION MODE - حالت آکاردئون */
/* ================================================================= */

.psych-accordion {
    border-radius: 8px;
    overflow: hidden;
}

.psych-accordion-item {
    background: #fff;
    margin-bottom: 16px;
    border: 1px solid #eee;
    border-radius: 8px;
    transition: var(--psych-transition);
}

.psych-accordion-item:hover {
    box-shadow: var(--psych-box-shadow);
}

.psych-accordion-header {
    display: flex;
    align-items: center;
    cursor: pointer;
    padding: 20px;
    transition: var(--psych-transition);
}

.psych-accordion-header:hover {
    background: rgba(0,123,255,0.02);
}

.psych-accordion-icon {
    margin-left: 12px;
    font-size: 22px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--psych-gray-600);
    color: white;
}

.psych-accordion-item.open .psych-accordion-icon {
    background: var(--psych-primary-color);
}

.psych-accordion-item.completed .psych-accordion-icon {
    background: var(--psych-success-color);
}

.psych-accordion-title {
    flex: 1;
    font-weight: 700;
    font-size: 16px;
    margin: 0;
}

.psych-accordion-toggle {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    color: #888;
    font-size: 18px;
    transition: var(--psych-transition);
}

.psych-accordion-toggle:hover {
    color: var(--psych-primary-color);
}

.psych-accordion-status {
    margin-left: 12px;
}

.psych-accordion-content {
    display: none;
    transition: all .3s;
    border-top: 1px solid #eee;
    background: #fafafa;
}

.psych-accordion-inner {
    padding: 24px 16px;
}

.psych-accordion-item.open .psych-accordion-content {
    display: block;
}

.psych-accordion-action-btn {
    margin-top: 24px;
}

/* ================================================================= */
/* TREASURE MAP MODE - حالت نقشه گنج */
/* ================================================================= */

.psych-treasure-map {
    position: relative;
    height: 370px;
    background: repeating-linear-gradient(135deg, #f6e7c1 0 20px, #ecd999 20px 40px);
    border-radius: 18px;
    overflow: hidden;
    margin: 32px 0;
}

.psych-map-path {
    width: 100%;
    height: 100%;
    position: absolute;
    top: 0;
    right: 0;
}

.psych-treasure-station {
    position: absolute;
    width: 72px;
    height: 72px;
    z-index: 10;
    text-align: center;
    cursor: pointer;
}

.psych-treasure-icon {
    position: relative;
    z-index: 2;
    width: 60px;
    height: 60px;
    border-radius: 100%;
    margin: 0 auto;
    background: linear-gradient(135deg, #ffe59d, #fffbe4, #fffbe4 90%);
    box-shadow: 0 0 8px rgba(0,0,0,.08);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 25px;
    transition: var(--psych-transition);
}

.psych-treasure-station.completed .psych-treasure-icon {
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    color: #8B4513;
}

.psych-treasure-station:hover .psych-treasure-icon {
    transform: scale(1.1);
}

.psych-treasure-glow {
    position: absolute;
    z-index: 1;
    width: 56px;
    height: 56px;
    top: 2px;
    left: 2px;
    box-shadow: 0 0 32px 8px #ffe59999;
    border-radius: 100%;
    pointer-events: none;
    animation: pulse 2s infinite;
}

.psych-treasure-popup {
    display: none;
    position: absolute;
    left: 77px;
    top: 0;
    background: #fff8de;
    border: 1px solid #eeda9a;
    border-radius: 12px;
    padding: 13px 24px 13px 16px;
    min-width: 180px;
    z-index: 100;
    box-shadow: 0 3px 16px 3px #e7e4b7af;
    font-size: 14px;
    text-align: right;
}

.psych-treasure-station:hover .psych-treasure-popup,
.psych-treasure-station:focus-within .psych-treasure-popup {
    display: block;
}

.psych-treasure-path-line {
    position: absolute;
    width: 56px;
    height: 8px;
    top: 25px;
    left: 58px;
    background: repeating-linear-gradient(90deg, #ceb253 8px, #fffbe4 2px, #ceb253 14px);
    border-radius: 8px;
    z-index: 1;
    opacity: .5;
}

.psych-treasure-path-line.completed {
    opacity: 1;
    background: linear-gradient(90deg, #fbd184, #fff176 80%);
}

.psych-treasure-path-line.incomplete {
    opacity: .4;
    filter: grayscale(1);
}

.psych-treasure-action-btn {
    margin-top: 12px;
}

/* ================================================================= */
/* CARDS MODE - حالت کارتی زیبا */
/* ================================================================= */

.psych-cards {
    display: grid;
    gap: 20px;
    margin: 24px 0;
    /* Mobile First: یک ستون */
    grid-template-columns: 1fr;
}

.psych-card-item {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    position: relative;
}

.psych-card-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #dee2e6, #ced4da);
    transition: all 0.3s;
}

.psych-card-item.open::before {
    background: linear-gradient(90deg, #007bff, #0056b3);
}

.psych-card-item.completed::before {
    background: linear-gradient(90deg, #28a745, #1e7e34);
}

.psych-card-item.restricted::before {
    background: linear-gradient(90deg, #dc3545, #c82333);
}

.psych-card-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.psych-card-item.locked:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.psych-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px 12px;
    background: linear-gradient(135deg, #f8f9fa, #ffffff);
}

.psych-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    background: linear-gradient(135deg, #6c757d, #495057);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.psych-card-item.open .psych-card-icon {
    background: linear-gradient(135deg, #007bff, #0056b3);
    animation: pulse 2s infinite;
}

.psych-card-item.completed .psych-card-icon {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    animation: none;
}

.psych-card-item.restricted .psych-card-icon {
    background: linear-gradient(135deg, #dc3545, #c82333);
}

.psych-card-status {
    margin-left: 12px;
}

.psych-card-body {
    padding: 0 20px 16px;
}

.psych-card-title {
    margin: 0 0 12px;
    font-size: 18px;
    font-weight: 700;
    color: #212529;
    line-height: 1.4;
}

.psych-card-footer {
    padding: 16px 20px 20px;
    background: rgba(248, 249, 250, 0.5);
}

.psych-card-action-btn {
    width: 100%;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s;
    cursor: pointer;
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: #fff;
    box-shadow: 0 2px 6px rgba(0,123,255,0.3);
}

.psych-card-action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.4);
}

.psych-card-action-btn:disabled {
    background: #6c757d !important;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

/* ================================================================= */
/* SIMPLE LIST MODE - حالت لیست ساده و تمیز */
/* ================================================================= */

.psych-simple-list {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    overflow: hidden;
    margin: 24px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.psych-list-item {
    display: flex;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #f1f3f4;
    transition: all 0.3s;
    position: relative;
}

.psych-list-item:last-child {
    border-bottom: none;
}

.psych-list-item:hover {
    background: rgba(0,123,255,0.02);
}

.psych-list-item.completed {
    background: rgba(40,167,69,0.05);
}

.psych-list-item.locked {
    opacity: 0.7;
    background: rgba(108,117,125,0.03);
}

.psych-list-item.restricted {
    background: rgba(220,53,69,0.05);
}

.psych-list-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6c757d, #495057);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    margin-left: 16px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.psych-list-item.open .psych-list-number {
    background: linear-gradient(135deg, #007bff, #0056b3);
    animation: pulse 2s infinite;
}

.psych-list-item.completed .psych-list-number {
    background: linear-gradient(135deg, #28a745, #1e7e34);
    animation: none;
}

.psych-list-item.restricted .psych-list-number {
    background: linear-gradient(135deg, #dc3545, #c82333);
}

.psych-list-content {
    flex: 1;
    margin-left: 16px;
}

.psych-list-title {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 600;
    color: #212529;
    line-height: 1.4;
}

.psych-list-status {
    margin-left: 16px;
    flex-shrink: 0;
}

.psych-list-action {
    margin-right: 16px;
    flex-shrink: 0;
}

.psych-list-action-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.3s;
    cursor: pointer;
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: #fff;
    box-shadow: 0 2px 4px rgba(0,123,255,0.2);
    min-width: 120px;
}

.psych-list-action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,123,255,0.3);
}

.psych-list-action-btn:disabled {
    background: #6c757d !important;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

/* ================================================================= */
/* STATUS BADGES - نشان‌های وضعیت */
/* ================================================================= */

.psych-status-badge {
    display: inline-block;
    background: #fafafa;
    color: #888;
    border: 1px solid #eee;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 12px;
    font-weight: 500;
}

.psych-status-badge.completed {
    background: #d6ffd2;
    color: #0c6e22;
    border-color: #a8e1b5;
}

.psych-status-badge.open {
    background: #e3f5ff;
    color: #268dbf;
    border-color: #74bddb;
}

.psych-status-badge.locked {
    background: #f8d6d6;
    color: #a87266;
    border-color: #f5bdbd;
}

.psych-status-badge.restricted {
    background: #ebe6f1;
    color: #5b347e;
    border-color: #8264aa;
}

/* Coach Impersonation Indicator */
.coach-impersonation-indicator {
    background: linear-gradient(135deg, #8E44AD, #9B59B6);
    color: var(--psych-white);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    margin-bottom: 12px;
    display: inline-flex;
    align-items: center;
    font-weight: 500;
}
.coach-impersonation-indicator i {
    margin-right: 6px;
    font-size: 14px;
}

/* Action Buttons */
.psych-station-action-btn {
    padding: 12px 24px;
    border: none;
    border-radius: var(--psych-border-radius);
    cursor: pointer;
    font-weight: 600;
    transition: var(--psych-transition);
    font-size: 14px;
    min-width: 140px;
    position: relative;
    overflow: hidden;
    text-transform: none;
    font-family: inherit;
}

.psych-station-action-btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transition: width 0.6s, height 0.6s, top 0.6s, left 0.6s;
    transform: translate(-50%, -50%);
}

.psych-station-action-btn:active::before {
    width: 300px;
    height: 300px;
    top: 50%;
    left: 50%;
}

/* Button States */
.psych-timeline-item.open .psych-station-action-btn {
    background: linear-gradient(135deg, var(--psych-primary-color), #0c7cd5);
    color: var(--psych-white);
    box-shadow: var(--psych-box-shadow-sm);
}
.psych-timeline-item.open .psych-station-action-btn:hover {
    background: linear-gradient(135deg, var(--psych-primary-hover), #0a6bb8);
    transform: translateY(-1px);
    box-shadow: var(--psych-box-shadow);
}

.psych-timeline-item.completed .psych-station-action-btn {
    background: linear-gradient(135deg, var(--psych-success-color), #20a038);
    color: var(--psych-white);
    box-shadow: var(--psych-box-shadow-sm);
}
.psych-timeline-item.completed .psych-station-action-btn:hover {
    background: linear-gradient(135deg, var(--psych-success-hover), #1b8f32);
    transform: translateY(-1px);
    box-shadow: var(--psych-box-shadow);
}

.psych-timeline-item.restricted .psych-station-action-btn {
    background: linear-gradient(135deg, var(--psych-danger-color), #e04353);
    color: var(--psych-white);
    box-shadow: var(--psych-box-shadow-sm);
}

.psych-station-action-btn:disabled {
    background: var(--psych-gray-300) !important;
    color: var(--psych-gray-600) !important;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

/* ================================================================= */
/* MODAL STYLES - Enhanced with Multiple Themes */
/* ================================================================= */

.psych-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(3px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 999999;
    padding: 16px;
    box-sizing: border-box;
    animation: fadeIn 0.3s ease-out;
}

.psych-modal-container {
    background: var(--psych-white);
    padding: 0;
    border-radius: var(--psych-border-radius-lg);
    width: 100%;
    max-width: 600px;
    position: relative;
    box-shadow: var(--psych-box-shadow-lg);
    max-height: calc(100vh - 32px);
    overflow: visible !important;
    animation: var(--psych-animation-slide-up);
    border: 1px solid var(--psych-gray-200);
}

/* Enhanced Close Button */
.psych-modal-close {
    position: absolute;
    top: 12px;
    left: 12px;
    width: 20px;
    height: 20px;
    border: none;
    background: none;
    cursor: pointer;
    color: #ccc;
    padding: 0;
    z-index: 99999;
    outline: none !important;
    box-shadow: none !important;
}

.psych-modal-close:hover {
    background: none !important;
    color: #999 !important;
    box-shadow: none !important;
    outline: none !important;
}

.psych-modal-close:focus {
    background: none !important;
    color: #999 !important;
    box-shadow: none !important;
    outline: none !important;
}

.psych-modal-close:active {
    background: none !important;
    color: #999 !important;
    box-shadow: none !important;
    outline: none !important;
}

.psych-modal-close svg {
    width: 14px;
    height: 14px;
    transition: var(--psych-transition);
}

/* Modal Title */
.psych-modal-title {
    margin: 0;
    padding: 24px 24px 16px;
    font-size: 20px;
    font-weight: 700;
    color: var(--psych-gray-800);
    line-height: 1.3;
    border-bottom: 1px solid var(--psych-gray-200);
    background: linear-gradient(135deg, var(--psych-gray-100), var(--psych-white));
}

/* Modal Content */
.psych-modal-content {
    padding: 24px;
    overflow-y: auto !important;
    max-height: calc(100vh - 200px);
    scrollbar-width: thin;
    scrollbar-color: var(--psych-gray-400) var(--psych-gray-200);
}

.psych-modal-content::-webkit-scrollbar {
    width: 6px;
}

.psych-modal-content::-webkit-scrollbar-track {
    background: var(--psych-gray-200);
    border-radius: 3px;
}

.psych-modal-content::-webkit-scrollbar-thumb {
    background: var(--psych-gray-400);
    border-radius: 3px;
}

.psych-modal-content::-webkit-scrollbar-thumb:hover {
    background: var(--psych-gray-500);
}

/* Mission Action Wrapper */
.mission-action-wrapper {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--psych-gray-200);
}

/* Coach Mission Notice */
.coach-mission-notice {
    background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
    border: 1px solid var(--psych-primary-color);
    color: var(--psych-primary-color);
    padding: 16px;
    border-radius: var(--psych-border-radius);
    margin-bottom: 20px;
    font-weight: 500;
}
.coach-mission-notice i {
    margin-right: 8px;
    font-size: 16px;
}

/* ================================================================= */
/* RESPONSIVE DESIGN - طراحی واکنش‌گرا */
/* ================================================================= */

/* Tablet Styles */
@media (min-width: 768px) {
    .psych-cards {
        grid-template-columns: repeat(2, 1fr);
    }

    .psych-path-container {
        padding: 32px;
    }
}

/* Desktop Styles */
@media (min-width: 1024px) {
    .psych-cards {
        grid-template-columns: repeat(3, 1fr);
    }

    .psych-path-container {
        padding: 40px;
    }
}

/* Mobile Timeline Adjustments */
@media (max-width: 768px) {
    .psych-timeline::before {
        left: 20px;
    }

    .psych-timeline-item {
        width: calc(100% - 60px);
        margin-left: 60px !important;
        margin-right: 0 !important;
    }

    .psych-timeline-item::before {
        left: -50px !important;
        right: auto !important;
    }

    .psych-timeline-icon {
        left: -70px !important;
        right: auto !important;
    }
}

/* Mobile Modal Adjustments */
@media (max-width: 480px) {
    .psych-modal-container {
        margin: 16px;
        max-width: calc(100vw - 32px);
    }

    .psych-modal-title {
        font-size: 18px;
        padding: 20px 16px 12px;
    }

    .psych-modal-content {
        padding: 16px;
    }
}

/* High DPI Displays */
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
    .psych-progress-fill::after {
        background-size: 200px 100%;
    }
}

/* Reduced Motion Preferences */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
    :root {
        --psych-white: #1a1a1a;
        --psych-gray-100: #2d2d2d;
        --psych-gray-200: #404040;
        --psych-gray-800: #e0e0e0;
        --psych-gray-900: #ffffff;
    }
}

/* Print Styles */
@media print {
    .psych-modal-overlay,
    .psych-treasure-map,
    .psych-station-action-btn {
        display: none !important;
    }

    .psych-timeline-item,
    .psych-card-item,
    .psych-list-item {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ccc !important;
    }
}

        </style>
        <?php
    }

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
						document.querySelectorAll('script[id*="gform_"], link[id*="gform_"]').forEach(el => el.remove());

						modalContent.innerHTML = res.data.html;

						if (res.data.assets) {
							const tempDiv = document.createElement('div');
							tempDiv.innerHTML = res.data.assets;

							// Correctly handle GForm assets by only appending external scripts and links.
							// This prevents the double-execution of inline scripts.
							Array.from(tempDiv.children).forEach(node => {
								if (node.tagName === 'LINK' || (node.tagName === 'SCRIPT' && node.src)) {
									document.head.appendChild(node.cloneNode(true));
								}
							});

							// Now, execute the inline scripts in the global scope.
							// This is safer than eval() and ensures GForm scripts initialize correctly.
							const inlineScripts = tempDiv.querySelectorAll('script:not([src])');
                            inlineScripts.forEach(script => {
                                try {
                                    (new Function(script.innerHTML))();
                                } catch (e) {
                                    console.error("Error executing inline GForm script:", e);
                                }
                            });
						}

						const formWrapper = modalContent.querySelector('.gform_wrapper');
						if (formWrapper) {
							const formId = formWrapper.id.split('_')[1] || 0;

							function initializeGForm() {
								if (typeof window.gform !== 'undefined' && typeof window.jQuery !== 'undefined') {
									const $ = window.jQuery;

                                    // --- START SPINNER.GIF FIX ---
                                    // مسیر صحیح فایل spinner را به صورت دستی تنظیم می‌کنیم
                                    const correctSpinnerUrl = '<?php echo plugins_url("gravityforms/images/spinner.gif"); ?>';
									if (typeof gformInitSpinner === 'function') {
										gformInitSpinner(formId, correctSpinnerUrl);
									}
                                    // --- END SPINNER.GIF FIX ---

									$(document).trigger('gform_post_render', [formId, 1]);
									gform.doAction('gform_post_render', formId, 1);
									$(formWrapper).css('display', 'block');
								} else {
									setTimeout(initializeGForm, 50);
								}
							}

							initializeGForm();
						}
					} else {
						modalContent.innerHTML = `<p>${res.data.message || 'خطا در بارگذاری محتوا.'}</p>`;
					}
				});
		};

		window.psych_complete_mission_inline = function(button) {
			if (button.disabled) return;
			let stationItem, stationDetails, pathContainer;
			const modal = findClosest(button, '#psych-station-modal');
			if (modal) {
				stationDetails = JSON.parse(modal.getAttribute('data-current-station-details'));
				if (!stationDetails) return;
				stationItem = document.querySelector(`[data-station-node-id="${stationDetails.station_node_id}"]`);
				if (!stationItem) return;
				pathContainer = findClosest(stationItem, '.psych-path-container');
			} else {
				stationItem = findClosest(button, '[data-station-node-id]');
				if (!stationItem) return;
				stationDetails = JSON.parse(stationItem.getAttribute('data-station-details'));
				pathContainer = findClosest(button, '.psych-path-container');
			}
			if (!stationItem || !stationDetails || !pathContainer) { return; }

			const originalHtml = button.innerHTML;
			button.disabled = true;
			button.innerHTML = 'در حال پردازش...';
			const formData = new FormData();
			formData.append('action', 'psych_path_complete_mission');
			formData.append('nonce', '<?php echo wp_create_nonce(PSYCH_PATH_AJAX_NONCE); ?>');
			formData.append('node_id', stationDetails.station_node_id);
			formData.append('station_data', JSON.stringify(stationDetails));
			if (button.hasAttribute('data-rewards')) {
				formData.append('custom_rewards', button.getAttribute('data-rewards'));
			}
			fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
				.then(response => response.json())
				.then(response => {
					if (response.success) {
						if (modal && modal.style.display !== 'none') {
							psych_close_station_modal();
						}

						// New logic to handle the 'updates' array
						if (response.data.updates && Array.isArray(response.data.updates)) {
							response.data.updates.forEach(update => {
								const itemToUpdate = document.querySelector(`[data-station-node-id="${update.node_id}"]`);
								if (itemToUpdate) {
									itemToUpdate.classList.remove('open', 'locked', 'restricted');
									itemToUpdate.classList.add(update.status);
									itemToUpdate.setAttribute('data-station-details', JSON.stringify(update.station_data));

									const inlineContent = itemToUpdate.querySelector('.psych-inline-station-content, .psych-accordion-mission-content, .psych-treasure-content, .psych-card-footer, .psych-list-action');
									if (inlineContent) {
										inlineContent.innerHTML = update.html;
									}
								}
							});
						}

						psych_show_rewards_notification(response.data.rewards, () => {
							// After rewards, run the full UI update to catch any chained unlocks or visibility changes
							psych_update_all_ui(pathContainer);
						});
					} else {
						button.disabled = false;
						button.innerHTML = originalHtml;
						alert(response.data.message || 'خطا در تکمیل ماموریت.');
					}
				});
		};

		window.psych_close_station_modal = function() {
			const modal = document.getElementById('psych-station-modal');
			if (modal) {
				modal.style.display = 'none';
				document.body.style.overflow = '';
			}
		};

		function psych_show_rewards_notification(rewards, callback) {
			let rewardsHtml = '<ul>';
			let hasRewards = false;
			if (rewards && rewards.points) { hasRewards = true; rewardsHtml += `<li><i class="fas fa-star"></i> شما <strong>${rewards.points}</strong> امتیاز کسب کردید!</li>`; }
			if (rewards && rewards.badge) { hasRewards = true; rewardsHtml += `<li><i class="fas fa-medal"></i> نشان <strong>"${rewards.badge}"</strong> را دریافت نمودید!</li>`; }
			if (rewardsHtml === '<ul>') rewardsHtml += '<li><i class="fas fa-check-circle"></i> با موفقیت انجام شد!</li>';
			rewardsHtml += '</ul>';
			const notification = document.createElement('div');
			notification.className = 'psych-rewards-overlay';
			notification.innerHTML = `<div class="psych-rewards-popup"><div class="psych-rewards-header"><i class="fas fa-gift"></i><h3>عالی بود!</h3></div><div class="psych-rewards-body">${rewardsHtml}</div><button class="psych-rewards-close">ادامه می‌دهم</button></div>`;
			document.body.appendChild(notification);
			if (hasRewards && typeof confetti === 'function') confetti({ particleCount: 150, spread: 90, origin: { y: 0.6 } });
			const closeHandler = function() {
				notification.style.opacity = '0';
				setTimeout(() => {
					notification.remove();
					if (typeof callback === 'function') callback();
				}, 300);
			};
			notification.querySelector('.psych-rewards-close').addEventListener('click', closeHandler);
		}

		function psych_update_all_ui(pathContainer) {
			if (!pathContainer) return;
			const userCompletedStations = {};
			pathContainer.querySelectorAll('.completed[data-station-node-id], .psych-accordion-item.completed').forEach(el => {
				userCompletedStations[el.getAttribute('data-station-node-id')] = true;
			});
			let previousStationCompleted = true;
			const statusRegex = /\s*\b(open|locked|completed|restricted)\b/g;
			pathContainer.querySelectorAll('[data-station-node-id]').forEach(station => {
				const details = JSON.parse(station.getAttribute('data-station-details')) || {};
				const nodeId = details.station_node_id;
				let newStatus = 'locked';
				let newIsUnlocked = false;
				const isReadyToUnlock = details.unlock_trigger === 'independent' || previousStationCompleted;
				if (userCompletedStations[nodeId]) {
					newStatus = 'completed';
					newIsUnlocked = true;
				} else if (isReadyToUnlock) {
					newStatus = 'open';
					newIsUnlocked = true;
				}
				details.status = newStatus;
				details.is_unlocked = newIsUnlocked;
				details.is_completed = (newStatus === 'completed');
				station.setAttribute('data-station-details', JSON.stringify(details));
				const badge = station.querySelector('.psych-status-badge');
				const icon = station.querySelector('.psych-accordion-icon i, .psych-timeline-icon i');
				station.className = station.className.replace(statusRegex, '').trim() + ' ' + newStatus;
				if (badge) {
					badge.className = 'psych-status-badge ' + newStatus;
					if (newStatus === 'completed') badge.innerHTML = '<i class="fas fa-check"></i> تکمیل شده';
					else if (newStatus === 'open') badge.innerHTML = '<i class="fas fa-unlock"></i> باز';
					else badge.innerHTML = '<i class="fas fa-lock"></i> قفل';
				}
				if(icon) {
					if (newStatus === 'completed') icon.className = 'fas fa-check-circle';
					else icon.className = details.icon || 'fas fa-lock';
				}
				if (details.unlock_trigger === 'sequential') {
					previousStationCompleted = (newStatus === 'completed');
				}
			});
			const total = pathContainer.querySelectorAll('[data-station-node-id]').length;
			const completedCount = Object.keys(userCompletedStations).length;
			const percentage = total > 0 ? Math.round((completedCount / total) * 100) : 0;
			const progressFill = pathContainer.querySelector('.psych-progress-fill');
			const progressText = pathContainer.querySelector('.psych-progress-text');
			const progressPercentage = pathContainer.querySelector('.psych-progress-percentage');
			if(progressText) progressText.textContent = `پیشرفت: ${completedCount} از ${total} ایستگاه`;
			if(progressPercentage) progressPercentage.textContent = `${percentage}%`;
			if(progressFill) progressFill.style.width = `${percentage}%`;
		}

		function psych_refresh_next_station(stationItem) {
			if (!stationItem) return;
			const nextStation = stationItem.nextElementSibling;
			if (nextStation && nextStation.matches('.psych-accordion-item') && nextStation.classList.contains('open')) {
				const stationData = nextStation.getAttribute('data-station-details');
				const formData = new FormData();
				formData.append('action', 'psych_path_get_inline_station_content');
				formData.append('nonce', '<?php echo wp_create_nonce(PSYCH_PATH_AJAX_NONCE); ?>');
				formData.append('station_data', stationData);
				fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
					.then(response => response.json())
					.then(res => {
						if (res.success) {
							const missionContent = nextStation.querySelector('.psych-accordion-mission-content');
							if(missionContent) missionContent.innerHTML = res.data.html;
							const contentArea = nextStation.querySelector('.psych-accordion-content');
							if (contentArea) {
								contentArea.style.display = 'block';
							}
						}
					});
			}
		}

		document.addEventListener('click', function(e) {
			if (e.target.matches('.psych-modal-close') || findClosest(e.target, '.psych-modal-close') || e.target.matches('.psych-modal-overlay')) {
				psych_close_station_modal();
			}
			const header = findClosest(e.target, '.psych-accordion-header');
			if (header && !e.target.matches('button, a')) {
				const content = header.nextElementSibling;
				if (content && content.matches('.psych-accordion-content')) {
					const isOpening = content.style.display !== 'block';
					const container = findClosest(header, '.psych-accordion');
					if(container) {
						container.querySelectorAll('.psych-accordion-content').forEach(el => {
							if (el !== content) el.style.display = 'none';
						});
					}
					content.style.display = isOpening ? 'block' : 'none';
				}
			}
		});

		$(document).on('psych_mission_engine_activity_completed', function(event, payload) {
			const pathContainer = document.querySelector('.psych-path-container');
			if (pathContainer) {
				// Wait a moment for the user to see the success message from the mission engine
				setTimeout(function() {
					psych_update_all_ui(pathContainer);
				}, 1500);
			}
		});
	})(jQuery);
	</script>
	<?php
}

} // End of PsychoCourse_Path_Engine class

// Initialize the class
PsychoCourse_Path_Engine::get_instance();
?>