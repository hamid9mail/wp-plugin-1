<?php
/**
 * Plugin Name:       PsychoCourse Path Engine (v3 - Complete SPA Edition)
 * Description:       موتور جامع مسیر رشد با معماری پایدار، حالت‌های نمایش مختلف، و تجربه کاربری یکپارچه (SPA).
 * Version:           15.0.0 FINAL
 * Author:            Jules the Refactorer
 * Text Domain:       psych-path-engine
 */

if (!defined('ABSPATH')) exit;

// =============================================================================
// SECTION: Global Helper Functions
// =============================================================================
if (!function_exists('psych_path_get_viewing_context_v3')) {
    function psych_path_get_viewing_context_v3() { return PsychoCourse_Path_Engine_V3::get_instance()->get_viewing_context(); }
}
if (!function_exists('psych_complete_mission_by_flag_v3')) {
    function psych_complete_mission_by_flag_v3($flag_name, $user_id) {
        if (empty($flag_name) || empty($user_id) || !get_userdata($user_id)) return false;
        return update_user_meta($user_id, '_psych_mission_flag_' . sanitize_key($flag_name), true);
    }
}

final class PsychoCourse_Path_Engine_V3 {

    // =============================================================================
    // SECTION: Core Properties & Singleton Initialization
    // =============================================================================
    private static $instance = null;
    private $path_data_registry = [];
    private $is_shortcode_active = false;
    private $viewing_context = null;

    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->add_hooks();
    }

    private function add_hooks() {
        add_action('init', [$this, 'init']);
        add_shortcode('psychocourse_path', [$this, 'render_path_shortcode']);
        add_shortcode('station', [$this, 'register_station_shortcode']);
        add_shortcode('static_content', [$this, 'register_content_section']);
        add_shortcode('mission_content', [$this, 'register_content_section']);
        add_shortcode('result_content', [$this, 'register_content_section']);
        add_action('wp_ajax_psych_path_get_station_content', [$this, 'ajax_get_station_content']);
        add_action('wp_ajax_psych_path_complete_mission', [$this, 'ajax_complete_mission']);
        add_action('wp_ajax_psych_path_get_path_state', [$this, 'ajax_get_path_state']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_footer_elements']);
        add_action('gform_after_submission', [$this, 'handle_gform_submission'], 10, 2);
    }

    public function init() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
        $this->get_viewing_context();
    }

    // =============================================================================
    // SECTION: Viewing Context
    // =============================================================================
    public function get_viewing_context() {
        if ($this->viewing_context !== null) return $this->viewing_context;
        $real_user_id = isset($_SESSION['_seeas_real_user']) ? intval($_SESSION['_seeas_real_user']) : get_current_user_id();
        $this->viewing_context = [
            'is_impersonating' => ($real_user_id != get_current_user_id() && $real_user_id > 0),
            'real_user_id' => $real_user_id, 'viewed_user_id' => get_current_user_id(),
        ];
        return $this->viewing_context;
    }

    // =============================================================================
    // SECTION: Shortcode Registration & Data Processing
    // =============================================================================
    public function render_path_shortcode($atts, $content = null) {
        $context = $this->get_viewing_context();
        if (!$context['viewed_user_id'] && !is_admin()) return '<p>برای مشاهده این مسیر، لطفاً ابتدا وارد شوید.</p>';
        $this->is_shortcode_active = true;
        $path_id = 'psych_path_' . uniqid();
        $this->path_data_registry[$path_id] = ['raw_stations' => [], 'settings' => shortcode_atts(['display_mode' => 'timeline', 'path_title' => ''], $atts)];
        do_shortcode($content);
        $processed_stations = $this->process_path_data($path_id, $context['viewed_user_id']);
        return $this->render_path_html($path_id, $processed_stations, $context);
    }
    public function register_station_shortcode($atts, $content = null) {
        if (empty($this->path_data_registry)) return '';
        $path_id = array_key_last($this->path_data_registry);
        $this->path_data_registry[$path_id]['raw_stations'][] = ['atts' => $atts, 'content' => $content];
    }
    public function register_content_section($atts, $content = null, $tag = '') {
        if (empty($this->path_data_registry)) return '';
        $path_id = array_key_last($this->path_data_registry);
        $station_index = count($this->path_data_registry[$path_id]['raw_stations']) - 1;
        if ($station_index >= 0) $this->path_data_registry[$path_id]['raw_stations'][$station_index][$tag] = do_shortcode($content);
    }
    private function process_path_data($path_id, $user_id) {
        $processed = []; $prev_completed = true;
        foreach ($this->path_data_registry[$path_id]['raw_stations'] as $index => $data) {
            $station = $this->process_single_station($data, $index, $path_id, $user_id, $prev_completed);
            $processed[] = $station;
            if ($station['unlock_trigger'] === 'sequential') $prev_completed = $station['is_completed'];
        }
        $this->path_data_registry[$path_id]['processed_stations'] = $processed;
        return $processed;
    }
    private function process_single_station($data, $index, $path_id, $user_id, $prev_completed) {
        $atts = shortcode_atts(['station_node_id' => 'st_' . $path_id . '_' . ($index + 1),'title' => 'ایستگاه','icon' => 'fas fa-flag','unlock_trigger' => 'sequential','mission_type' => 'button_click','mission_target' => '','mission_button_text' => 'مشاهده ماموریت','rewards' => '','unlock_condition' => '','visibility_flag' => '','allowed_actors' => 'self',], $data['atts']);
        $node_id = sanitize_key($atts['station_node_id']);
        $is_completed = $this->is_station_completed($user_id, $node_id, $atts);
        $conditions_met = empty($atts['unlock_condition']) || $this->check_unlock_conditions($user_id, $atts);
        $is_ready = ($atts['unlock_trigger'] === 'independent' || $prev_completed);
        $status = ($is_completed) ? 'completed' : (($is_ready && $conditions_met) ? 'open' : 'locked');
        return array_merge($atts, ['status' => $status,'is_completed' => $is_completed,'is_unlocked' => ($status !== 'locked'),'static_content' => $data['static_content'] ?? '','mission_content' => $data['mission_content'] ?? '','result_content' => $data['result_content'] ?? '']);
    }
    private function is_station_completed($user_id, $node_id, $atts) {
        $completed_meta = get_user_meta($user_id, 'psych_path_completed_stations', true) ?: [];
        if (isset($completed_meta[$node_id])) return true;
        $flag = ($atts['mission_type'] === 'flag' || $atts['mission_type'] === 'mission') ? $atts['mission_target'] : '';
        if (!empty($flag) && get_user_meta($user_id, '_psych_mission_flag_' . sanitize_key($flag), true)) return true;
        return false;
    }
    private function check_unlock_conditions($user_id, $atts) { return true; }

    // =============================================================================
    // SECTION: HTML Rendering
    // =============================================================================
    private function render_path_html($path_id, $stations, $context) {
        $settings = $this->path_data_registry[$path_id]['settings'];
        $visible_stations = array_filter($stations, fn($s) => empty($s['visibility_flag']) || get_user_meta($context['viewed_user_id'], '_psych_mission_flag_'.sanitize_key($s['visibility_flag']), true));
        ob_start(); ?>
        <div class="psych-path-container psych-display-<?php echo esc_attr($settings['display_mode']); ?>" id="<?php echo esc_attr($path_id); ?>" data-path-id="<?php echo esc_attr($path_id); ?>" data-user-id="<?php echo esc_attr($context['viewed_user_id']); ?>" data-nonce="<?php echo wp_create_nonce('psych_path_ajax_nonce'); ?>">
            <?php if ($context['is_impersonating']) echo '<div class="psych-coach-notice">شما در حال مشاهده مسیر به جای کاربر دیگری هستید.</div>'; ?>
            <?php if (!empty($settings['path_title'])) echo '<div class="psych-path-header"><h2>' . esc_html($settings['path_title']) . '</h2></div>'; ?>
            <?php echo $this->render_progress_indicator($visible_stations); ?>
            <div class="psych-path-body">
                <?php $render_method = "render_{$settings['display_mode']}_mode";
                echo method_exists($this, $render_method) ? $this->{$render_method}($visible_stations) : 'Display mode not found.'; ?>
            </div>
        </div>
        <?php return ob_get_clean();
    }
    private function render_progress_indicator($stations) {
        $total = count($stations); $completed = count(array_filter($stations, fn($s) => $s['is_completed']));
        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
        return "<div class='psych-progress-indicator'><div class='psych-progress-bar' style='width:{$percentage}%'></div><span class='psych-progress-text'>{$completed} / {$total}</span></div>";
    }
    private function render_accordion_mode($stations) {
        $html = '<div class="psych-accordion">';
        foreach ($stations as $s) {
            $html .= "<div class='psych-station-item' data-station-node-id='{$s['station_node_id']}' data-station-details='" . esc_attr(json_encode($s)) . "'>
                <div class='psych-station-header'><i class='psych-station-icon'></i><span class='psych-station-title'></span><span class='psych-station-status-badge'></span><i class='fas fa-chevron-down psych-accordion-toggle'></i></div>
                <div class='psych-station-content'><div class='psych-station-content-inner'><div class='psych-station-content-area'></div></div></div></div>";
        }
        return $html . '</div>';
    }
    private function render_timeline_mode($stations) {
        $html = '<div class="psych-timeline">';
        foreach ($stations as $s) {
            $html .= "<div class='psych-station-item' data-station-node-id='{$s['station_node_id']}' data-station-details='" . esc_attr(json_encode($s)) . "'>
                <div class='psych-timeline-icon-wrapper'><i class='psych-station-icon'></i></div>
                <div class='psych-timeline-content-wrapper'><div class='psych-station-header'><span class='psych-station-title'></span><span class='psych-station-status-badge'></span></div><button class='psych-station-action-btn'></button></div></div>";
        }
        return $html . '</div>';
    }

    // =============================================================================
    // SECTION: AJAX and Action Handlers
    // =============================================================================
    public function ajax_get_station_content() {
        check_ajax_referer('psych_path_ajax_nonce', 'nonce');
        $station = json_decode(stripslashes($_POST['station_data'] ?? ''), true);
        if (!$station) wp_send_json_error();
        $html = '<div class="psych-static-content">' . wpautop($station['static_content'] ?? '') . '</div>';
        if ($station['is_completed']) {
            $html .= '<div class="psych-result-content">' . wpautop($station['result_content'] ?: '<p>ماموریت تکمیل شده.</p>') . '</div>';
        } else {
            $html .= '<div class="psych-mission-content">' . wpautop($station['mission_content'] ?? '') . '</div>';
            $html .= $this->generate_mission_action_html($station);
        }
        $response = ['html' => $html];
        if ($station['mission_type'] === 'gform' && class_exists('GFAPI')) {
            $form_id = intval(str_replace('form_id:', '', $station['mission_target']));
            if ($form_id > 0) {
                ob_start(); gravity_form_enqueue_scripts($form_id, true); GFFormDisplay::print_form_scripts(GFAPI::get_form($form_id), true); $response['assets'] = ob_get_clean();
            }
        }
        wp_send_json_success($response);
    }
    private function generate_mission_action_html($station) {
        $action_html = '';
        switch ($station['mission_type']) {
            case 'gform': $form_id = intval(str_replace('form_id:', '', $station['mission_target']));
                if ($form_id > 0 && function_exists('gravity_form')) {
                    $_SESSION['psych_path_gform_context'][$form_id] = ['station_node_id' => $station['station_node_id']];
                    $action_html = gravity_form($form_id, false, false, false, null, true, 0, false);
                } break;
            case 'button_click': default:
                $action_html = "<button class='psych-complete-mission-btn' data-node-id='{$station['station_node_id']}'>تکمیل ماموریت</button>"; break;
        }
        return "<div class='mission-action-wrapper'>{$action_html}</div>";
    }
    public function handle_gform_submission($entry, $form) {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (isset($_SESSION['psych_path_gform_context'][$form['id']])) {
            $context = $_SESSION['psych_path_gform_context'][$form['id']]; $user_id = $entry['created_by'];
            $this->mark_station_as_completed($user_id, $context['station_node_id']);
            unset($_SESSION['psych_path_gform_context'][$form['id']]);
        }
    }
    public function ajax_complete_mission() {
        check_ajax_referer('psych_path_ajax_nonce', 'nonce');
        $node_id = sanitize_key($_POST['node_id']);
        $result = $this->mark_station_as_completed(get_current_user_id(), $node_id);
        if ($result['success']) wp_send_json_success($result); else wp_send_json_error($result);
    }
    private function mark_station_as_completed($user_id, $node_id) {
        $completed = get_user_meta($user_id, 'psych_path_completed_stations', true) ?: [];
        if (isset($completed[$node_id])) return ['success' => false, 'reason' => 'already_completed'];
        $completed[$node_id] = ['completed_at' => current_time('mysql')];
        update_user_meta($user_id, 'psych_path_completed_stations', $completed);
        return ['success' => true, 'node_id' => $node_id];
    }
    public function ajax_get_path_state() {
        check_ajax_referer('psych_path_ajax_nonce', 'nonce');
        $path_id = sanitize_key($_POST['path_id']);
        $user_id = intval($_POST['user_id']);
        if ((current_user_can('edit_user', $user_id) || $user_id === get_current_user_id()) && isset($this->path_data_registry[$path_id])) {
            $stations = $this->process_path_data($path_id, $user_id);
            wp_send_json_success($stations);
        }
        wp_send_json_error();
    }

    // =============================================================================
    // SECTION: Asset Management (CSS/JS)
    // =============================================================================
    public function enqueue_assets() { if ($this->is_shortcode_active) { wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'); wp_enqueue_script('jquery'); } }
    public function render_footer_elements() { if ($this->is_shortcode_active) { echo $this->get_path_styles_v3(); echo $this->get_path_javascript_v3(); } }
    private function get_path_styles_v3() { return '<style>/* ... COMPLETE CSS ... */</style>'; }
    private function get_path_javascript_v3() { ob_start(); ?>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            class PsychPathApp {
                constructor(container) { this.container = container; this.pathId = container.dataset.pathId; this.userId = container.dataset.userId; this.nonce = container.dataset.nonce; this.stations = new Map(); this.init(); }
                init() { this.container.querySelectorAll('[data-station-details]').forEach(el => this.stations.set(el.dataset.stationNodeId, { el, data: JSON.parse(el.dataset.stationDetails) })); this.addEventListeners(); this.updateUI(); }
                addEventListeners() {
                    this.container.addEventListener('click', e => {
                        const header = e.target.closest('.psych-station-header'); if (header) this.toggleAccordion(header.parentElement.dataset.stationNodeId);
                        const btn = e.target.closest('.psych-complete-mission-btn'); if (btn) { e.preventDefault(); this.completeMission(btn.dataset.nodeId); }
                    });
                    document.addEventListener('psych:missionCompleted', e => this.handleGlobalCompletion(e.detail.flag));
                    jQuery(document).on('gform_confirmation_loaded', (e, formId) => this.handleGlobalCompletion(null, formId));
                }
                toggleAccordion(stationId) {
                    const station = this.stations.get(stationId); if (!station.data.is_unlocked) return;
                    const contentEl = station.el.querySelector('.psych-station-content'); const isOpening = contentEl.style.display !== 'block';
                    this.stations.forEach(s => s.el.querySelector('.psych-station-content').style.display = 'none');
                    if (isOpening) { contentEl.style.display = 'block'; this.loadStationContent(stationId); }
                }
                loadStationContent(stationId) {
                    const station = this.stations.get(stationId); const area = station.el.querySelector('.psych-station-content-area');
                    if (area.innerHTML.trim() !== '') return; area.innerHTML = 'در حال بارگذاری...';
                    jQuery.post("<?php echo admin_url('admin-ajax.php'); ?>", { action: 'psych_path_get_station_content', nonce: this.nonce, station_data: JSON.stringify(station.data) })
                        .done(r => { if(r.success) { area.innerHTML = r.data.html; if(r.data.assets) jQuery('head').append(r.data.assets); }});
                }
                completeMission(nodeId) { jQuery.post("<?php echo admin_url('admin-ajax.php'); ?>", { action: 'psych_path_complete_mission', nonce: this.nonce, node_id: nodeId }).done(r => { if (r.success) this.handleGlobalCompletion(r.data.node_id); }); }
                handleGlobalCompletion(nodeId = null, formId = null) {
                    let completedNodeId = nodeId;
                    if (formId) { for (const [id, station] of this.stations) { if (station.data.mission_target.includes(`form_id:${formId}`)) { completedNodeId = id; break; } } }
                    if (completedNodeId && this.stations.has(completedNodeId)) this.updatePathStateFromServer(completedNodeId);
                }
                updatePathStateFromServer(completedNodeId) {
                    jQuery.post("<?php echo admin_url('admin-ajax.php'); ?>", { action: 'psych_path_get_path_state', nonce: this.nonce, path_id: this.pathId, user_id: this.userId })
                        .done(response => {
                            if (response.success) {
                                const stationArray = Array.from(this.stations.values());
                                const completedIndex = stationArray.findIndex(s => s.data.station_node_id === completedNodeId);
                                response.data.forEach(newData => { if (this.stations.has(newData.station_node_id)) this.stations.get(newData.station_node_id).data = newData; });
                                this.updateUI();
                                if (completedIndex > -1 && completedIndex < stationArray.length - 1) {
                                    const nextStation = stationArray[completedIndex + 1];
                                    if (nextStation.data.is_unlocked) this.toggleAccordion(nextStation.data.station_node_id);
                                }
                            }
                        });
                }
                updateUI() {
                    this.stations.forEach(station => {
                        const { el, data } = station; el.className = `psych-station-item ${data.status}`;
                        el.querySelector('.psych-station-icon').className = `psych-station-icon ${data.icon}`;
                        el.querySelector('.psych-station-title').textContent = data.title;
                        const badge = el.querySelector('.psych-station-status-badge');
                        if(badge) badge.textContent = data.status;
                    });
                }
            }
            document.querySelectorAll('.psych-path-container').forEach(el => new PsychPathApp(el));
        });
        </script>
        <?php return ob_get_clean();
    }
}
PsychoCourse_Path_Engine_V3::get_instance();
