<?php
/**
 * Plugin Name: Psych Complete System - Report Card (Enhanced Integration Edition)
 * Description: ماژول جامع گزارش‌گیری و کارنامه با یکپارچگی کامل
 * Version: 5.0.0 (Enhanced Integration Edition)
 * Author: Enhanced Integration Team
 *
 * فایل: report-card.php
 * این نسخه Enhanced شامل:
 * - هماهنگی کامل با Coach Module , Path Engine .2, Interactive Content .3, Gamification Center .5
 * - پشتیبانی کامل از Coach Impersonation
 * - نمایش گزارش‌های دقیق پیشرفت مسیر
 * - سیستم اعلانات پیشرفته
 * - گزارش‌گیری چندبعدی
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Prevent double loading
if (class_exists('Psych_Unified_Report_Card_Enhanced')) {
    return;
}

/**
 * Enhanced Report Card Class with full integration support
 */
final class Psych_Unified_Report_Card_Enhanced {

    private static $instance = null;
    const VERSION = '5.0.0';

    // Meta keys
    const TEST_RESULTS_META_KEY   = 'psych_test_results';
    const PARENT_MOBILE_META      = 'psych_parent_mobile';
    const USER_NOTES_META         = 'psych_user_notes';
    const GOALS_META              = 'psych_user_goals';

    // SMS Settings
    const ACTIVE_SMS_SYSTEM       = 'FarazSMS'; // FarazSMS, IPPanel, or None

    private $viewing_context = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_viewing_context();
        $this->add_hooks();
        $this->register_shortcodes();
    }

    private function init_viewing_context() {
        // Get viewing context from path engine if available
        if (function_exists('psych_path_get_viewing_context')) {
            $this->viewing_context = psych_path_get_viewing_context();
        } else {
            // Fallback to basic context
            $this->viewing_context = [
                'is_impersonating' => false,
                'real_user_id' => get_current_user_id(),
                'viewed_user_id' => get_current_user_id(),
            ];
        }
    }

    private function get_viewing_context() {
        if ($this->viewing_context === null) {
            $this->init_viewing_context();
        }
        return $this->viewing_context;
    }

    private function add_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_psych_save_user_notes', [$this, 'ajax_save_user_notes']);
        add_action('wp_ajax_psych_save_user_goals', [$this, 'ajax_save_user_goals']);
        add_action('wp_ajax_psych_send_parent_report', [$this, 'ajax_send_parent_report']);
        add_action('psych_send_user_report_sms', [$this, 'trigger_report_sms']);

        // Handle form submissions
        add_action('init', [$this, 'handle_form_submissions']);

        // Cron job for logging points
        add_action('psych_log_daily_points_hook', [$this, 'log_all_users_daily_points']);
    }

    public function enqueue_assets() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'psych_report_card')) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);

            wp_enqueue_script('psych-report-card', false, ['jquery', 'chart-js'], self::VERSION, true);
            wp_add_inline_script('psych-report-card', $this->get_inline_js());

            wp_localize_script('psych-report-card', 'psych_report_card', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('psych_report_card_nonce'),
                'viewing_context' => $this->get_viewing_context()
            ]);

            add_action('wp_head', [$this, 'print_styles']);
        }
    }

    public function print_styles() {
        ?>
        <style>
            .psych-report-card-container {
                font-family: 'Vazirmatn', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                border-radius: 15px;
                padding: 30px;
                margin: 20px auto;
                max-width: 1000px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                position: relative;
                overflow: hidden;
            }

            .psych-report-card-container::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                pointer-events: none;
            }

            .psych-report-card-header {
                display: flex;
                align-items: center;
                background: rgba(255,255,255,0.9);
                backdrop-filter: blur(10px);
                border-radius: 12px;
                padding: 25px;
                margin-bottom: 30px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                position: relative;
                z-index: 1;
            }

            .psych-report-card-header .avatar {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                margin-left: 25px;
                border: 4px solid #fff;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }

            .psych-report-card-header h2 {
                margin: 0;
                font-size: 28px;
                color: #2c3e50;
                font-weight: 700;
            }

            .psych-user-status {
                font-size: 14px;
                color: #7f8c8d;
                margin-top: 5px;
            }

            .psych-impersonation-notice {
                background: linear-gradient(135deg, #f39c12, #e67e22);
                color: white;
                padding: 15px 20px;
                border-radius: 10px;
                margin-bottom: 20px;
                font-weight: 600;
                box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
            }

            .psych-report-card-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
                margin-bottom: 30px;
                background: rgba(255,255,255,0.7);
                backdrop-filter: blur(10px);
                padding: 8px;
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }

            .psych-report-card-tabs .tab-link {
                padding: 12px 20px;
                cursor: pointer;
                border: none;
                background: transparent;
                font-size: 15px;
                color: #555;
                border-radius: 8px;
                transition: all 0.3s ease;
                font-weight: 500;
                flex: 1;
                text-align: center;
                min-width: 120px;
            }

            .psych-report-card-tabs .tab-link:hover {
                background: rgba(52, 152, 219, 0.1);
                color: #3498db;
            }

            .psych-report-card-tabs .tab-link.active {
                background: linear-gradient(135deg, #3498db, #2980b9);
                color: white;
                font-weight: 600;
                box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            }

            .psych-tab-content {
                display: none;
                animation: fadeIn 0.3s ease-in;
            }

            .psych-tab-content.active {
                display: block;
            }

            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .psych-section {
                margin-bottom: 30px;
                background: rgba(255,255,255,0.9);
                backdrop-filter: blur(10px);
                padding: 25px;
                border-radius: 12px;
                border: 1px solid rgba(255,255,255,0.2);
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                position: relative;
                z-index: 1;
            }

            .psych-section h3 {
                font-size: 22px;
                color: #2c3e50;
                margin-top: 0;
                margin-bottom: 20px;
                padding-bottom: 12px;
                border-bottom: 2px solid #ecf0f1;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .psych-section h3 i {
                color: #3498db;
            }

            .psych-summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 25px;
            }

            .psych-summary-item {
                text-align: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 25px 20px;
                border-radius: 12px;
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
                transition: transform 0.3s ease;
            }

            .psych-summary-item:hover {
                transform: translateY(-5px);
            }

            .psych-summary-item .value {
                font-size: 32px;
                font-weight: 700;
                margin-bottom: 8px;
                text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }

            .psych-summary-item .label {
                font-size: 14px;
                opacity: 0.9;
                font-weight: 500;
            }

            .psych-badges-list {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            .psych-badge-item {
                text-align: center;
                background: rgba(255,255,255,0.8);
                padding: 20px 15px;
                border-radius: 12px;
                transition: all 0.3s ease;
                border: 2px solid transparent;
            }

            .psych-badge-item:hover {
                transform: translateY(-3px);
                border-color: #3498db;
                box-shadow: 0 8px 25px rgba(52, 152, 219, 0.2);
            }

            .psych-badge-item img,
            .psych-badge-item i {
                width: 64px;
                height: 64px;
                margin-bottom: 10px;
            }

            .psych-badge-item i {
                font-size: 64px;
                line-height: 64px;
            }

            .psych-badge-item .badge-name {
                font-size: 13px;
                font-weight: 600;
                color: #2c3e50;
                margin: 0;
            }

            .psych-test-results-table,
            .psych-leaderboard-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }

            .psych-test-results-table th,
            .psych-test-results-table td,
            .psych-leaderboard-table th,
            .psych-leaderboard-table td {
                padding: 15px 20px;
                text-align: right;
                border-bottom: 1px solid #ecf0f1;
            }

            .psych-test-results-table th,
                        .psych-leaderboard-table th {
                background: linear-gradient(135deg, #34495e, #2c3e50);
                color: white;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 12px;
                letter-spacing: 0.5px;
            }

            .psych-test-results-table tbody tr:hover,
            .psych-leaderboard-table tbody tr:hover {
                background: #f8f9fa;
            }

            .psych-score-cell {
                font-weight: 600;
                font-size: 16px;
            }

            .psych-score-excellent { color: #27ae60; }
            .psych-score-good { color: #f39c12; }
            .psych-score-needs-improvement { color: #e74c3c; }

            .psych-progress-bar {
                width: 100%;
                height: 8px;
                background: #ecf0f1;
                border-radius: 4px;
                overflow: hidden;
                margin: 8px 0;
            }

            .psych-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #3498db, #2980b9);
                border-radius: 4px;
                transition: width 0.8s ease;
            }

            .psych-chart-container {
                position: relative;
                height: 400px;
                margin: 20px 0;
                background: white;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }

            .psych-notes-section textarea {
                width: 100%;
                min-height: 120px;
                padding: 15px;
                border: 2px solid #ecf0f1;
                border-radius: 8px;
                font-family: inherit;
                font-size: 14px;
                line-height: 1.6;
                resize: vertical;
                transition: border-color 0.3s ease;
            }

            .psych-notes-section textarea:focus {
                outline: none;
                border-color: #3498db;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            }

            .psych-button {
                background: linear-gradient(135deg, #3498db, #2980b9);
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                margin-top: 15px;
            }

            .psych-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(52, 152, 219, 0.3);
            }

            .psych-button.success {
                background: linear-gradient(135deg, #27ae60, #229954);
            }

            .psych-button.warning {
                background: linear-gradient(135deg, #f39c12, #e67e22);
            }

            .psych-path-progress {
                margin: 20px 0;
            }

            .psych-path-station {
                display: flex;
                align-items: center;
                padding: 15px;
                margin: 10px 0;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                transition: all 0.3s ease;
            }

            .psych-path-station:hover {
                transform: translateX(5px);
            }

            .psych-path-station.completed {
                background: linear-gradient(135deg, #d5f4e6, #a8e6cf);
                border-right: 4px solid #27ae60;
            }

            .psych-path-station.current {
                background: linear-gradient(135deg, #fff3cd, #ffeaa7);
                border-right: 4px solid #f39c12;
                box-shadow: 0 4px 15px rgba(243, 156, 18, 0.2);
            }

            .psych-path-station.locked {
                background: #f8f9fa;
                color: #95a5a6;
                border-right: 4px solid #bdc3c7;
            }

            .psych-station-icon {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-left: 15px;
                font-size: 18px;
                color: white;
            }

            .psych-path-station.completed .psych-station-icon {
                background: #27ae60;
            }

            .psych-path-station.current .psych-station-icon {
                background: #f39c12;
            }

            .psych-path-station.locked .psych-station-icon {
                background: #95a5a6;
            }

            .psych-station-details h4 {
                margin: 0 0 5px 0;
                color: #2c3e50;
                font-size: 16px;
            }

            .psych-station-details p {
                margin: 0;
                font-size: 13px;
                color: #7f8c8d;
            }

            .psych-alert {
                padding: 15px 20px;
                border-radius: 8px;
                margin: 15px 0;
                border-right: 4px solid;
            }

            .psych-alert.success {
                background: #d4edda;
                color: #155724;
                border-right-color: #28a745;
            }

            .psych-alert.error {
                background: #f8d7da;
                color: #721c24;
                border-right-color: #dc3545;
            }

            .psych-alert.info {
                background: #cce7ff;
                color: #004085;
                border-right-color: #007bff;
            }

            .psych-loading {
                text-align: center;
                padding: 40px;
                color: #7f8c8d;
            }

            .psych-loading::after {
                content: '';
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 2px solid #3498db;
                border-radius: 50%;
                border-top-color: transparent;
                animation: spin 1s linear infinite;
                margin-right: 10px;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            @media (max-width: 768px) {
                .psych-report-card-container {
                    padding: 20px;
                    margin: 10px;
                }

                .psych-report-card-header {
                    flex-direction: column;
                    text-align: center;
                }

                .psych-report-card-header .avatar {
                    margin: 0 0 15px 0;
                }

                .psych-summary-grid {
                    grid-template-columns: 1fr;
                    gap: 15px;
                }

                .psych-report-card-tabs {
                    flex-direction: column;
                }

                .psych-report-card-tabs .tab-link {
                    flex: none;
                    min-width: auto;
                }

                .psych-badges-list {
                    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                    gap: 15px;
                }

                .psych-test-results-table,
                .psych-leaderboard-table {
                    font-size: 14px;
                }

                .psych-test-results-table th,
                .psych-test-results-table td,
                .psych-leaderboard-table th,
                .psych-leaderboard-table td {
                    padding: 10px 8px;
                }

                .psych-chart-container {
                    height: 300px;
                }
            }
        </style>
        <?php
    }

    public function get_inline_js() {
        return '
        window.PsychReportCard = {
            init: function() {
                this.initTabs();
                this.initCharts();
                this.initProgressBars();
                this.initNotesAutoSave();
            },

            initTabs: function() {
                jQuery(".psych-report-card-tabs .tab-link").on("click", function(e) {
                    e.preventDefault();
                    var targetTab = jQuery(this).data("tab");

                    // Update active tab
                    jQuery(".psych-report-card-tabs .tab-link").removeClass("active");
                    jQuery(this).addClass("active");

                    // Update active content
                    jQuery(".psych-tab-content").removeClass("active");
                    jQuery("#" + targetTab).addClass("active");

                    // Trigger chart redraw if needed
                    if (targetTab === "psych-tab-analytics") {
                        setTimeout(() => this.initCharts(), 100);
                    }
                }.bind(this));
            },

            initCharts: function() {
                // Progress Chart
                var progressCtx = document.getElementById("psych-progress-chart");
                if (progressCtx && typeof Chart !== "undefined") {
                    var progressData = JSON.parse(progressCtx.dataset.chartData || "{}");

                    new Chart(progressCtx, {
                        type: "line",
                        data: {
                            labels: progressData.labels || [],
                            datasets: [{
                                label: "پیشرفت امتیازات",
                                data: progressData.points || [],
                                borderColor: "#3498db",
                                backgroundColor: "rgba(52, 152, 219, 0.1)",
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: "top"
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: "rgba(0,0,0,0.1)"
                                    }
                                },
                                x: {
                                    grid: {
                                        color: "rgba(0,0,0,0.1)"
                                    }
                                }
                            }
                        }
                    });
                }

                // Badges Donut Chart
                var badgesCtx = document.getElementById("psych-badges-chart");
                if (badgesCtx && typeof Chart !== "undefined") {
                    var badgesData = JSON.parse(badgesCtx.dataset.chartData || "{}");

                    new Chart(badgesCtx, {
                        type: "doughnut",
                        data: {
                            labels: ["کسب شده", "باقی‌مانده"],
                            datasets: [{
                                data: [badgesData.earned || 0, badgesData.remaining || 0],
                                backgroundColor: ["#27ae60", "#ecf0f1"],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: "bottom"
                                }
                            }
                        }
                    });
                }
            },

            initProgressBars: function() {
                jQuery(".psych-progress-bar").each(function() {
                    var percent = jQuery(this).data("percent") || 0;
                    var $fill = jQuery(this).find(".psych-progress-fill");

                    setTimeout(() => {
                        $fill.css("width", percent + "%");
                    }, 500);
                });
            },

            initNotesAutoSave: function() {
                var saveTimeout;
                jQuery("#psych-user-notes, #psych-user-goals").on("input", function() {
                    clearTimeout(saveTimeout);
                    var $this = jQuery(this);

                    saveTimeout = setTimeout(() => {
                        this.saveUserData($this);
                    }, 2000);
                }.bind(this));
            },

            saveUserData: function($element) {
                var fieldType = $element.attr("id") === "psych-user-notes" ? "notes" : "goals";
                var content = $element.val();

                jQuery.post(psych_report_card.ajax_url, {
                    action: "psych_save_user_" + fieldType,
                    nonce: psych_report_card.nonce,
                    content: content
                }).done(function(response) {
                    if (response.success) {
                        $element.next(".save-indicator").remove();
                        $element.after("<span class=\"save-indicator\" style=\"color: #27ae60; font-size: 12px; margin-right: 10px;\">✓ ذخیره شد</span>");
                        setTimeout(() => {
                            jQuery(".save-indicator").fadeOut();
                        }, 2000);
                    }
                });
            },

            sendParentReport: function() {
                var mobile = jQuery("#parent-mobile").val();
                if (!mobile) {
                    alert("لطفاً شماره موبایل والدین را وارد کنید.");
                    return;
                }

                jQuery("#send-parent-report").prop("disabled", true).text("در حال ارسال...");

                jQuery.post(psych_report_card.ajax_url, {
                    action: "psych_send_parent_report",
                    nonce: psych_report_card.nonce,
                    mobile: mobile
                }).done(function(response) {
                    if (response.success) {
                        alert("گزارش با موفقیت ارسال شد.");
                    } else {
                        alert("خطا در ارسال گزارش: " + (response.data?.message || "خطای نامشخص"));
                    }
                }).fail(function() {
                    alert("خطا در ارتباط با سرور");
                }).always(function() {
                    jQuery("#send-parent-report").prop("disabled", false).text("ارسال گزارش");
                });
            }
        };

        jQuery(document).ready(function() {
            PsychReportCard.init();
        });
        ';
    }


    private function register_shortcodes() {
        add_shortcode('psych_report_card', [$this, 'render_report_card_shortcode']);
        add_shortcode('psych_user_summary', [$this, 'render_user_summary_shortcode']);
        add_shortcode('psych_path_progress', [$this, 'render_path_progress_shortcode']);
    }

    // =====================================================================
    // MAIN SHORTCODE IMPLEMENTATION
    // =====================================================================

    public function render_report_card_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'show_tabs' => 'overview,gamification,path,analytics,notes',
            'compact' => 'false'
        ], $atts);

        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

        if (!$user_id) {
            return '<div class="psych-alert error">کاربر مشخص نشده است.</div>';
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return '<div class="psych-alert error">کاربر مورد نظر یافت نشد.</div>';
        }

        $tabs = array_map('trim', explode(',', $atts['show_tabs']));
        $is_compact = $atts['compact'] === 'true';

        ob_start();
        ?>
        <div class="psych-report-card-container <?php echo $is_compact ? 'compact' : ''; ?>">
            <?php if ($context['is_impersonating']): ?>
                <div class="psych-impersonation-notice">
                    <i class="fas fa-eye"></i>
                    شما در حال مشاهده کارنامه <strong><?php echo esc_html($user->display_name); ?></strong> هستید
                </div>
            <?php endif; ?>

            <?php $this->render_header($user, $context); ?>

            <?php if (!$is_compact): ?>
                <?php $this->render_tabs($tabs); ?>
                <?php $this->render_tab_contents($user_id, $tabs, $context); ?>
            <?php else: ?>
                <?php $this->render_compact_summary($user_id, $context); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_header($user, $context) {
        $avatar_url = get_avatar_url($user->ID, ['size' => 80]);
        $level_info = $this->get_user_level_info($user->ID);
        $total_points = $this->get_user_total_points($user->ID);
        ?>
        <div class="psych-report-card-header">
            <img src="<?php echo esc_url($avatar_url); ?>" alt="آواتار" class="avatar">
            <div class="user-info">
                <h2><?php echo esc_html($user->display_name); ?></h2>
                <div class="psych-user-status">
                    <span class="level" style="color: <?php echo esc_attr($level_info['color']); ?>">
                        <i class="<?php echo esc_attr($level_info['icon']); ?>"></i>
                        <?php echo esc_html($level_info['name']); ?>
                    </span>
                    •
                    <span class="points"><?php echo number_format_i18n($total_points); ?> امتیاز</span>
                    •
                    <span class="join-date">عضویت: <?php echo wp_date('j F Y', strtotime($user->user_registered)); ?></span>
                </div>
                <?php if ($level_info['points_to_next'] > 0): ?>
                    <div class="psych-progress-bar" data-percent="<?php echo esc_attr($level_info['progress_percentage']); ?>">
                        <div class="psych-progress-fill"></div>
                    </div>
                    <small><?php echo number_format_i18n($level_info['points_to_next']); ?> امتیاز تا سطح بعدی</small>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_tabs($tabs) {
        $tab_labels = [
            'overview' => ['title' => 'نمای کلی', 'icon' => 'fas fa-tachometer-alt'],
            'gamification' => ['title' => 'گیمیفیکیشن', 'icon' => 'fas fa-trophy'],
            'path' => ['title' => 'مسیر یادگیری', 'icon' => 'fas fa-route'],
            'analytics' => ['title' => 'تحلیل‌ها', 'icon' => 'fas fa-chart-line'],
            'notes' => ['title' => 'یادداشت‌ها', 'icon' => 'fas fa-sticky-note']
        ];
        ?>
        <div class="psych-report-card-tabs">
            <?php foreach ($tabs as $index => $tab): ?>
                <?php if (isset($tab_labels[$tab])): ?>
                    <button class="tab-link <?php echo $index === 0 ? 'active' : ''; ?>"
                            data-tab="psych-tab-<?php echo esc_attr($tab); ?>">
                        <i class="<?php echo esc_attr($tab_labels[$tab]['icon']); ?>"></i>
                        <?php echo esc_html($tab_labels[$tab]['title']); ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_tab_contents($user_id, $tabs, $context) {
        foreach ($tabs as $index => $tab) {
            $is_active = $index === 0;
            echo '<div id="psych-tab-' . esc_attr($tab) . '" class="psych-tab-content' . ($is_active ? ' active' : '') . '">';

            switch ($tab) {
                case 'overview':
                    $this->render_overview_tab($user_id, $context);
                    break;
                case 'gamification':
                    $this->render_gamification_tab($user_id, $context);
                    break;
                case 'path':
                    $this->render_path_tab($user_id, $context);
                    break;
                case 'analytics':
                    $this->render_analytics_tab($user_id, $context);
                    break;
                case 'notes':
                    $this->render_notes_tab($user_id, $context);
                    break;
            }

            echo '</div>';
        }
    }

    // =====================================================================
    // TAB CONTENT RENDERERS
    // =====================================================================

    private function render_overview_tab($user_id, $context) {
        $summary_data = $this->get_user_summary_data($user_id);
        ?>
        <div class="psych-section">
            <h3><i class="fas fa-chart-pie"></i> خلاصه عملکرد</h3>
            <div class="psych-summary-grid">
                <div class="psych-summary-item">
                    <div class="value"><?php echo number_format_i18n($summary_data['total_points']); ?></div>
                    <div class="label">کل امتیازات</div>
                </div>
                <div class="psych-summary-item">
                    <div class="value"><?php echo number_format_i18n($summary_data['badges_count']); ?></div>
                    <div class="label">نشان‌های کسب شده</div>
                </div>
                <div class="psych-summary-item">
                    <div class="value"><?php echo number_format_i18n($summary_data['completed_stations']); ?></div>
                    <div class="label">ایستگاه‌های تکمیل شده</div>
                </div>
                <div class="psych-summary-item">
                    <div class="value"><?php echo $summary_data['progress_percentage']; ?>%</div>
                    <div class="label">درصد پیشرفت</div>
                </div>
            </div>
        </div>

        <?php if (!empty($summary_data['recent_achievements'])): ?>
        <div class="psych-section">
            <h3><i class="fas fa-star"></i> دستاوردهای اخیر</h3>
            <div class="psych-achievements-list">
                <?php foreach ($summary_data['recent_achievements'] as $achievement): ?>
                    <div class="psych-achievement-item">
                        <i class="<?php echo esc_attr($achievement['icon']); ?>" style="color: <?php echo esc_attr($achievement['color']); ?>"></i>
                        <span><?php echo esc_html($achievement['title']); ?></span>
                        <small><?php echo human_time_diff(strtotime($achievement['date'])); ?> پیش</small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($context['is_impersonating'] || current_user_can('manage_options')): ?>
        <div class="psych-section">
            <h3><i class="fas fa-sms"></i> ارسال گزارش به والدین</h3>
            <div class="psych-parent-report">
                <input type="text" id="parent-mobile" placeholder="شماره موبایل والدین"
                       value="<?php echo esc_attr(get_user_meta($user_id, self::PARENT_MOBILE_META, true)); ?>">
                <button type="button" class="psych-button" onclick="PsychReportCard.sendParentReport()">
                    ارسال گزارش
                </button>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_gamification_tab($user_id, $context) {
        $badges_data = $this->get_user_badges_data($user_id);
        $leaderboard_data = $this->get_leaderboard_data($user_id);
        ?>
        <div class="psych-section">
            <h3><i class="fas fa-trophy"></i> نشان‌های کسب شده</h3>
            <?php if (!empty($badges_data['earned'])): ?>
                <div class="psych-badges-list">
                    <?php foreach ($badges_data['earned'] as $badge): ?>
                        <div class="psych-badge-item">
                            <i class="<?php echo esc_attr($badge['icon']); ?>"
                               style="color: <?php echo esc_attr($badge['color']); ?>"></i>
                            <p class="badge-name"><?php echo esc_html($badge['name']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="psych-alert info">هنوز نشانی کسب نشده است.</div>
            <?php endif; ?>
        </div>

        <?php if (!empty($badges_data['available'])): ?>
        <div class="psych-section">
            <h3><i class="fas fa-target"></i> نشان‌های در دسترس</h3>
            <div class="psych-badges-list">
                <?php foreach ($badges_data['available'] as $badge): ?>
                    <div class="psych-badge-item" style="opacity: 0.6;">
                        <i class="<?php echo esc_attr($badge['icon']); ?>"
                           style="color: <?php echo esc_attr($badge['color']); ?>"></i>
                        <p class="badge-name"><?php echo esc_html($badge['name']); ?></p>
                        <small><?php echo esc_html($badge['description']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="psych-section">
            <h3><i class="fas fa-list-ol"></i> جایگاه در لیدربورد</h3>
            <div class="psych-user-rank">
                <p>رتبه شما: <strong><?php echo number_format_i18n($leaderboard_data['user_rank']); ?></strong> از <?php echo number_format_i18n($leaderboard_data['total_users']); ?> کاربر</p>
            </div>

            <?php if (!empty($leaderboard_data['top_users'])): ?>
            <table class="psych-leaderboard-table">
                <thead>
                    <tr>
                        <th>رتبه</th>
                        <th>نام</th>
                        <th>امتیاز</th>
                        <th>سطح</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard_data['top_users'] as $index => $user_data): ?>
                        <tr <?php echo $user_data['ID'] == $user_id ? 'class="current-user"' : ''; ?>>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo esc_html($user_data['display_name']); ?></td>
                            <td><?php echo number_format_i18n($user_data['points']); ?></td>
                            <td><?php echo esc_html($user_data['level']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_path_tab($user_id, $context) {
        $path_data = $this->get_user_path_data($user_id);
        ?>
        <div class="psych-section">
            <h3><i class="fas fa-route"></i> پیشرفت مسیر یادگیری</h3>

            <?php if (!empty($path_data['current_path'])): ?>
                <div class="psych-path-info">
                    <h4>مسیر فعلی: <?php echo esc_html($path_data['current_path']['title']); ?></h4>
                    <p><?php echo esc_html($path_data['current_path']['description']); ?></p>

                    <div class="psych-progress-bar" data-percent="<?php echo esc_attr($path_data['completion_percentage']); ?>">
                        <div class="psych-progress-fill"></div>
                    </div>
                    <p><?php echo $path_data['completion_percentage']; ?>% تکمیل شده
                       (<?php echo $path_data['completed_stations']; ?> از <?php echo $path_data['total_stations']; ?> ایستگاه)</p>
                </div>
            <?php endif; ?>

            <div class="psych-path-progress">
                <?php if (!empty($path_data['stations'])): ?>
                    <?php foreach ($path_data['stations'] as $station): ?>
                        <div class="psych-path-station <?php echo esc_attr($station['status']); ?>">
                            <div class="psych-station-icon">
                                <i class="<?php echo esc_attr($station['icon']); ?>"></i>
                            </div>
                            <div class="psych-station-details">
                                <h4><?php echo esc_html($station['title']); ?></h4>
                                <p><?php echo esc_html($station['description']); ?></p>
                                <?php if ($station['status'] === 'completed' && $station['completion_date']): ?>
                                    <small>تکمیل شده در: <?php echo wp_date('j F Y', strtotime($station['completion_date'])); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="psych-alert info">هنوز مسیری انتخاب نشده است.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_analytics_tab($user_id, $context) {
        $analytics_data = $this->get_user_analytics_data($user_id);
        ?>
        <div class="psych-section">
            <h3><i class="fas fa-chart-line"></i> نمودارهای پیشرفت</h3>

            <div class="psych-chart-container">
                <canvas id="psych-progress-chart"
                        data-chart-data="<?php echo esc_attr(json_encode($analytics_data['progress_chart'])); ?>"></canvas>
            </div>
        </div>

        <div class="psych-section">
            <h3><i class="fas fa-chart-pie"></i> توزیع نشان‌ها</h3>

            <div class="psych-chart-container">
                <canvas id="psych-badges-chart"
                        data-chart-data="<?php echo esc_attr(json_encode($analytics_data['badges_chart'])); ?>"></canvas>
            </div>
        </div>

        <?php if (!empty($analytics_data['test_results'])): ?>
        <div class="psych-section">
            <h3><i class="fas fa-clipboard-list"></i> نتایج آزمون‌ها</h3>

            <table class="psych-test-results-table">
                <thead>
                    <tr>
                        <th>عنوان آزمون</th>
                        <th>تاریخ</th>
                        <th>نمره</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analytics_data['test_results'] as $result): ?>
                        <tr>
                            <td><?php echo esc_html($result['title']); ?></td>
                            <td><?php echo wp_date('j F Y', strtotime($result['date'])); ?></td>
                            <td class="psych-score-cell <?php echo esc_attr($result['score_class']); ?>">
                                <?php echo esc_html($result['score']); ?>%
                            </td>
                            <td><?php echo esc_html($result['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_notes_tab($user_id, $context) {
        $can_edit = ($context['real_user_id'] == $user_id) || current_user_can('manage_options');
        $user_notes = get_user_meta($user_id, self::USER_NOTES_META, true);
        $user_goals = get_user_meta($user_id, self::GOALS_META, true);
        ?>
        <div class="psych-section">
            <h3><i class="fas fa-sticky-note"></i> یادداشت‌های شخصی</h3>

            <?php if ($can_edit): ?>
                <div class="psych-notes-section">
                    <textarea id="psych-user-notes" placeholder="یادداشت‌های خود را اینجا بنویسید..."><?php echo esc_textarea($user_notes); ?></textarea>
                </div>
            <?php else: ?>
                <div class="psych-notes-display">
                    <?php echo $user_notes ? nl2br(esc_html($user_notes)) : '<em>یادداشتی وجود ندارد.</em>'; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="psych-section">
            <h3><i class="fas fa-bullseye"></i> اهداف و برنامه‌ها</h3>

            <?php if ($can_edit): ?>
                <div class="psych-notes-section">
                    <textarea id="psych-user-goals" placeholder="اهداف و برنامه‌های خود را اینجا تعریف کنید..."><?php echo esc_textarea($user_goals); ?></textarea>
                </div>
            <?php else: ?>
                <div class="psych-notes-display">
                    <?php echo $user_goals ? nl2br(esc_html($user_goals)) : '<em>هدفی تعریف نشده است.</em>'; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_compact_summary($user_id, $context) {
        $summary_data = $this->get_user_summary_data($user_id);
        ?>
        <div class="psych-section">
            <div class="psych-summary-grid">
                <div class="psych-summary-item">
                    <div class="value"><?php echo number_format_i18n($summary_data['total_points']); ?></div>
                    <div class="label">امتیاز</div>
                </div>
                <div class="psych-summary-item">
                    <div class="value"><?php echo number_format_i18n($summary_data['badges_count']); ?></div>
                    <div class="label">نشان</div>
                </div>
                <div class="psych-summary-item">
                    <div class="value"><?php echo $summary_data['progress_percentage']; ?>%</div>
                    <div class="label">پیشرفت</div>
                </div>
            </div>
        </div>
        <?php
    }

    // =====================================================================
    // DATA RETRIEVAL METHODS (Integration with other modules)
    // =====================================================================

    public function log_all_users_daily_points() {
        $users = get_users(['fields' => 'ID']);
        foreach ($users as $user_id) {
            $this->log_user_daily_points($user_id);
        }
    }

    public function log_user_daily_points($user_id) {
        $history = get_user_meta($user_id, 'psych_points_history', true) ?: [];
        $total_points = $this->get_user_total_points($user_id);

        $today = date('Y-m-d');

        // To prevent duplicate entries if the cron runs multiple times a day
        $history[$today] = $total_points;

        // Keep the last 30 days of data
        if (count($history) > 30) {
            $history = array_slice($history, -30, 30, true);
        }

        update_user_meta($user_id, 'psych_points_history', $history);
    }

    private function get_user_level_info($user_id) {
        if (function_exists('psych_gamification_get_user_level_info')) {
            return psych_gamification_get_user_level_info($user_id);
        }

        // Fallback
        return [
            'name' => 'تازه‌کار',
            'icon' => 'fas fa-seedling',
            'color' => '#95a5a6',
            'points_to_next' => 0,
            'current_points' => 0,
            'progress_percentage' => 0
        ];
    }

    private function get_user_total_points($user_id) {
        return (int) get_user_meta($user_id, 'psych_total_points', true);
    }

    private function get_user_summary_data($user_id) {
        $total_points = $this->get_user_total_points($user_id);
        $badges_count = count(get_user_meta($user_id, 'psych_user_badges', true) ?: []);

        // Get path progress
        $path_data = $this->get_user_path_data($user_id);
        $completed_stations = $path_data['completed_stations'];
        $progress_percentage = $path_data['completion_percentage'];

        // Get recent achievements
        $recent_achievements = $this->get_user_recent_achievements($user_id);

        return [
            'total_points' => $total_points,
            'badges_count' => $badges_count,
            'completed_stations' => $completed_stations,
            'progress_percentage' => $progress_percentage,
            'recent_achievements' => $recent_achievements
        ];
    }

    private function get_user_badges_data($user_id) {
        $earned_badges = [];
        $available_badges = [];

        if (function_exists('psych_gamification_get_instance')) {
            $gamification = psych_gamification_get_instance();
            if (method_exists($gamification, 'get_user_badges_progress')) {
                $badges_progress = $gamification->get_user_badges_progress($user_id);

                foreach ($badges_progress as $badge) {
                    if ($badge['earned']) {
                        $earned_badges[] = $badge;
                    } else {
                        $available_badges[] = $badge;
                    }
                }
            }
        }

        return [
            'earned' => $earned_badges,
            'available' => $available_badges
        ];
    }

    private function get_leaderboard_data($user_id) {
        global $wpdb;

        // Get Top 10 Users
        $top_users_query = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value as points
             FROM {$wpdb->usermeta}
             WHERE meta_key = %s
             ORDER BY CAST(meta_value AS UNSIGNED) DESC
             LIMIT 10",
            'psych_total_points'
        ));

        $top_users = [];
        foreach ($top_users_query as $user_row) {
            $user_data = get_userdata($user_row->user_id);
            if ($user_data) {
                $level_info = $this->get_user_level_info($user_row->user_id);
                $top_users[] = [
                    'ID' => $user_row->user_id,
                    'display_name' => $user_data->display_name,
                    'points' => (int) $user_row->points,
                    'level' => $level_info['name'] ?? 'N/A'
                ];
            }
        }

        // Get Current User's Rank
        $current_user_points = (int) get_user_meta($user_id, 'psych_total_points', true);
        $user_rank = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(user_id) + 1
             FROM {$wpdb->usermeta}
             WHERE meta_key = %s
             AND CAST(meta_value AS UNSIGNED) > %d",
            'psych_total_points',
            $current_user_points
        ));

        return [
            'user_rank'   => (int) $user_rank,
            'total_users' => count_users()['total_users'],
            'top_users'   => $top_users
        ];
    }

    private function get_user_path_data($user_id) {
        $default_data = [
            'current_path' => null,
            'completion_percentage' => 0,
            'completed_stations' => 0,
            'total_stations' => 0,
            'stations' => []
        ];

        // Integration with Path Engine
        if (function_exists('psych_path_get_user_progress')) {
            return array_merge($default_data, psych_path_get_user_progress($user_id));
        }

        return $default_data;
    }

    private function get_user_analytics_data($user_id) {
        $progress_chart = $this->generate_progress_chart_data($user_id);
        $badges_chart = $this->generate_badges_chart_data($user_id);
        $test_results = $this->get_user_test_results($user_id);

        return [
            'progress_chart' => $progress_chart,
            'badges_chart' => $badges_chart,
            'test_results' => $test_results
        ];
    }

    private function generate_progress_chart_data($user_id) {
        $history = get_user_meta($user_id, 'psych_points_history', true) ?: [];

        // Ensure we have data for today if it hasn't been logged yet for immediate view
        if (!isset($history[date('Y-m-d')])) {
            $history[date('Y-m-d')] = $this->get_user_total_points($user_id);
        }

        // Sort by date just in case
        ksort($history);

        // Get the last 30 days
        $history = array_slice($history, -30, 30, true);

        $labels = [];
        $points = [];
        foreach ($history as $date => $value) {
            $labels[] = wp_date('j M', strtotime($date));
            $points[] = intval($value);
        }

        return [
            'labels' => $labels,
            'points' => $points
        ];
    }

    private function generate_badges_chart_data($user_id) {
        $badges_data = $this->get_user_badges_data($user_id);

        return [
            'earned' => count($badges_data['earned']),
            'remaining' => count($badges_data['available'])
        ];
    }

    private function get_user_test_results($user_id) {
        $test_results = get_user_meta($user_id, self::TEST_RESULTS_META_KEY, true) ?: [];

        // Process results for display
        foreach ($test_results as &$result) {
            $score = floatval($result['score']);

            if ($score >= 80) {
                $result['score_class'] = 'psych-score-excellent';
                $result['status'] = 'عالی';
            } elseif ($score >= 60) {
                $result['score_class'] = 'psych-score-good';
                $result['status'] = 'خوب';
            } else {
                $result['score_class'] = 'psych-score-needs-improvement';
                $result['status'] = 'نیاز به بهبود';
            }
        }

        return array_slice($test_results, -10); // Last 10 results
    }

    private function get_user_recent_achievements($user_id) {
        $achievements = [];

        // Get recent badges (last 5)
        $badge_log = get_option('psych_badge_log', []);
        $user_badge_logs = array_filter($badge_log, function($log) use ($user_id) {
            return $log['user_id'] == $user_id;
        });

        $user_badge_logs = array_slice($user_badge_logs, -5);

        foreach ($user_badge_logs as $log) {
            $badge_name = function_exists('psych_get_badge_name') ?
                         psych_get_badge_name($log['badge_slug']) : 'نشان جدید';

            $achievements[] = [
                'title' => "کسب نشان: {$badge_name}",
                'icon' => 'fas fa-trophy',
                'color' => '#f39c12',
                'date' => $log['timestamp']
            ];
        }

        // Get recent points (last 5)
        $points_log = get_option('psych_points_log', []);
        $user_points_logs = array_filter($points_log, function($log) use ($user_id) {
            return $log['user_id'] == $user_id;
        });

        $user_points_logs = array_slice($user_points_logs, -5);

        foreach ($user_points_logs as $log) {
            $achievements[] = [
                'title' => "کسب {$log['points']} امتیاز: {$log['reason']}",
                'icon' => 'fas fa-star',
                'color' => '#3498db',
                'date' => $log['timestamp']
            ];
        }

        // Sort by date
        usort($achievements, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return array_slice($achievements, 0, 8);
    }


    // =====================================================================
    // AJAX HANDLERS
    // =====================================================================

    public function ajax_save_user_notes() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_report_card_nonce')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$user_id || ($context['real_user_id'] != $user_id && !current_user_can('manage_options'))) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $content = sanitize_textarea_field($_POST['content']);
        update_user_meta($user_id, self::USER_NOTES_META, $content);

        wp_send_json_success(['message' => 'یادداشت ذخیره شد.']);
    }

    public function ajax_save_user_goals() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_report_card_nonce')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }

        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$user_id || ($context['real_user_id'] != $user_id && !current_user_can('manage_options'))) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $content = sanitize_textarea_field($_POST['content']);
        update_user_meta($user_id, self::GOALS_META, $content);

        wp_send_json_success(['message' => 'اهداف ذخیره شد.']);
    }

    public function ajax_send_parent_report() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_report_card_nonce')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }

        $context = $this->get_viewing_context();

        if (!$context['is_impersonating'] && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $user_id = $context['viewed_user_id'];
        $mobile = sanitize_text_field($_POST['mobile']);

        if (empty($mobile)) {
            wp_send_json_error(['message' => 'شماره موبایل ضروری است.']);
        }

        // Save parent mobile
        update_user_meta($user_id, self::PARENT_MOBILE_META, $mobile);

        // Send SMS report
        $result = $this->send_parent_report_sms($user_id, $mobile);

        if ($result) {
            wp_send_json_success(['message' => 'گزارش با موفقیت ارسال شد.']);
        } else {
            wp_send_json_error(['message' => 'خطا در ارسال پیامک.']);
        }
    }

    // =====================================================================
    // SMS FUNCTIONALITY
    // =====================================================================

    private function send_parent_report_sms($user_id, $mobile) {
        $user = get_userdata($user_id);
        if (!$user) return false;

        $summary_data = $this->get_user_summary_data($user_id);
        $level_info = $this->get_user_level_info($user_id);

        $message = sprintf(
            "گزارش پیشرفت %s:\n🎯 امتیاز: %s\n🏆 نشان‌ها: %s\n📊 پیشرفت: %s%%\n🎖️ سطح: %s\n\n%s",
            $user->display_name,
            number_format_i18n($summary_data['total_points']),
            number_format_i18n($summary_data['badges_count']),
            $summary_data['progress_percentage'],
            $level_info['name'],
            get_bloginfo('name')
        );

        return $this->send_sms($mobile, $message);
    }

    public function trigger_report_sms($user_id) {
        $parent_mobile = get_user_meta($user_id, self::PARENT_MOBILE_META, true);
        if ($parent_mobile) {
            $this->send_parent_report_sms($user_id, $parent_mobile);
        }
    }

    private function send_sms($mobile, $message) {
        switch (self::ACTIVE_SMS_SYSTEM) {
            case 'FarazSMS':
                return $this->send_faraz_sms($mobile, $message);
            case 'IPPanel':
                return $this->send_ippanel_sms($mobile, $message);
            default:
                // Log the SMS instead of sending
                error_log("SMS to {$mobile}: {$message}");
                return true;
        }
    }

    private function send_faraz_sms($mobile, $message) {
        // Implementation for FarazSMS
        // This would need actual API credentials and implementation
        return true;
    }

    private function send_ippanel_sms($mobile, $message) {
        // Implementation for IPPanel
        // This would need actual API credentials and implementation
        return true;
    }

    // =====================================================================
    // FORM HANDLERS
    // =====================================================================

    public function handle_form_submissions() {
        if (!isset($_POST['psych_report_action'])) return;

        if (!wp_verify_nonce($_POST['psych_report_nonce'], 'psych_report_action')) {
            wp_die('نشست منقضی شده است.');
        }

        $action = sanitize_key($_POST['psych_report_action']);

        switch ($action) {
            case 'save_test_result':
                $this->handle_save_test_result();
                break;
            case 'update_parent_mobile':
                $this->handle_update_parent_mobile();
                break;
        }
    }

    private function handle_save_test_result() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز.');
        }

        $user_id = intval($_POST['user_id']);
        $test_title = sanitize_text_field($_POST['test_title']);
        $test_score = floatval($_POST['test_score']);

        $test_results = get_user_meta($user_id, self::TEST_RESULTS_META_KEY, true) ?: [];

        $test_results[] = [
            'title' => $test_title,
            'score' => $test_score,
            'date' => current_time('mysql')
        ];

        // Keep only last 50 results
        $test_results = array_slice($test_results, -50);

        update_user_meta($user_id, self::TEST_RESULTS_META_KEY, $test_results);

        wp_redirect(add_query_arg('message', 'test_saved'));
        exit;
    }

    private function handle_update_parent_mobile() {
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$user_id || ($context['real_user_id'] != $user_id && !current_user_can('manage_options'))) {
            wp_die('دسترسی غیرمجاز.');
        }

        $mobile = sanitize_text_field($_POST['parent_mobile']);
        update_user_meta($user_id, self::PARENT_MOBILE_META, $mobile);

        wp_redirect(add_query_arg('message', 'mobile_updated'));
        exit;
    }

    // =====================================================================
    // ADDITIONAL SHORTCODES
    // =====================================================================

    public function render_user_summary_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'show_avatar' => 'true',
            'show_progress' => 'true'
        ], $atts);

        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

        if (!$user_id) return '';

        $user = get_userdata($user_id);
        if (!$user) return '';

        $summary_data = $this->get_user_summary_data($user_id);
        $level_info = $this->get_user_level_info($user_id);

        ob_start();
        ?>
        <div class="psych-user-summary-widget">
            <?php if ($atts['show_avatar'] === 'true'): ?>
                <img src="<?php echo esc_url(get_avatar_url($user_id, ['size' => 60])); ?>"
                     alt="آواتار" class="psych-summary-avatar">
            <?php endif; ?>

            <div class="psych-summary-info">
                <h4><?php echo esc_html($user->display_name); ?></h4>
                <div class="psych-summary-stats">
                    <span class="level" style="color: <?php echo esc_attr($level_info['color']); ?>">
                        <i class="<?php echo esc_attr($level_info['icon']); ?>"></i>
                        <?php echo esc_html($level_info['name']); ?>
                    </span>
                    <span class="points">
                        <?php echo number_format_i18n($summary_data['total_points']); ?> امتیاز
                    </span>
                    <span class="badges">
                        <?php echo number_format_i18n($summary_data['badges_count']); ?> نشان
                    </span>
                </div>

                <?php if ($atts['show_progress'] === 'true' && $level_info['points_to_next'] > 0): ?>
                    <div class="psych-progress-bar" data-percent="<?php echo esc_attr($level_info['progress_percentage']); ?>">
                        <div class="psych-progress-fill"></div>
                    </div>
                    <small><?php echo number_format_i18n($level_info['points_to_next']); ?> امتیاز تا سطح بعدی</small>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_path_progress_shortcode($atts) {
        $atts = shortcode_atts([
            'user_id' => 0,
            'compact' => 'false'
        ], $atts);

        $context = $this->get_viewing_context();
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

        if (!$user_id) return '';

        $path_data = $this->get_user_path_data($user_id);
        $is_compact = $atts['compact'] === 'true';

        if (empty($path_data['current_path'])) {
            return '<div class="psych-alert info">هنوز مسیری انتخاب نشده است.</div>';
        }

        ob_start();
        ?>
        <div class="psych-path-progress-widget <?php echo $is_compact ? 'compact' : ''; ?>">
            <div class="psych-path-header">
                <h4><?php echo esc_html($path_data['current_path']['title']); ?></h4>
                <div class="psych-progress-bar" data-percent="<?php echo esc_attr($path_data['completion_percentage']); ?>">
                    <div class="psych-progress-fill"></div>
                </div>
                <p><?php echo $path_data['completion_percentage']; ?>% تکمیل شده</p>
            </div>

            <?php if (!$is_compact && !empty($path_data['stations'])): ?>
                <div class="psych-path-stations">
                    <?php foreach (array_slice($path_data['stations'], 0, 5) as $station): ?>
                        <div class="psych-station-mini <?php echo esc_attr($station['status']); ?>">
                            <i class="<?php echo esc_attr($station['icon']); ?>"></i>
                            <span><?php echo esc_html($station['title']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize the enhanced report card
if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Unified_Report_Card_Enhanced::get_instance();
    });
}

// Hook for other modules to trigger report SMS
if (!function_exists('psych_trigger_user_report_sms')) {
    function psych_trigger_user_report_sms($user_id) {
        do_action('psych_send_user_report_sms', $user_id);
    }
}

// Integration hooks for other modules
add_action('init', function() {
    // Register integration hooks after all plugins are loaded
    do_action('psych_report_card_loaded');
});

// Activation hook - create default data
register_activation_hook(__FILE__, function() {
    // Set default options if they don't exist
    if (!get_option('psych_report_card_version')) {
        update_option('psych_report_card_version', Psych_Unified_Report_Card_Enhanced::VERSION);
    }

    if (!wp_next_scheduled('psych_log_daily_points_hook')) {
        wp_schedule_event(time(), 'daily', 'psych_log_daily_points_hook');
    }
});

// Deactivation hook - cleanup
register_deactivation_hook(__FILE__, function() {
    // Clean up any temporary data if needed
    wp_clear_scheduled_hook('psych_report_card_daily_cleanup');
    wp_clear_scheduled_hook('psych_log_daily_points_hook');
});

// Schedule daily cleanup
if (!wp_next_scheduled('psych_report_card_daily_cleanup')) {
    wp_schedule_event(time(), 'daily', 'psych_report_card_daily_cleanup');
}

add_action('psych_report_card_daily_cleanup', function() {
    // Clean up old temporary data
    global $wpdb;

    // Clean up old notification logs (keep last 30 days)
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'psych_temp_%'
         AND option_name < %s",
        'psych_temp_' . date('Y-m-d', strtotime('-30 days'))
    ));
});

// =====================================================================
// COMPATIBILITY FUNCTIONS FOR OLDER INTEGRATIONS
// =====================================================================

if (!function_exists('psych_get_user_report_data')) {
    /**
     * Compatibility function for older integrations
     * @param int $user_id
     * @return array
     */
    function psych_get_user_report_data($user_id) {
        $instance = Psych_Unified_Report_Card_Enhanced::get_instance();
        return [
            'points' => $instance->get_user_total_points($user_id),
            'level' => $instance->get_user_level_info($user_id),
            'badges' => $instance->get_user_badges_data($user_id),
            'path' => $instance->get_user_path_data($user_id)
        ];
    }
}

if (!function_exists('psych_add_test_result')) {
    /**
     * Add a test result for a user
     * @param int $user_id
     * @param string $test_title
     * @param float $score
     * @return bool
     */
    function psych_add_test_result($user_id, $test_title, $score) {
        $test_results = get_user_meta($user_id, Psych_Unified_Report_Card_Enhanced::TEST_RESULTS_META_KEY, true) ?: [];

        $test_results[] = [
            'title' => sanitize_text_field($test_title),
            'score' => floatval($score),
            'date' => current_time('mysql')
        ];

        // Keep only last 50 results
        $test_results = array_slice($test_results, -50);

        update_user_meta($user_id, Psych_Unified_Report_Card_Enhanced::TEST_RESULTS_META_KEY, $test_results);

        return true;
    }
}

if (!function_exists('psych_get_user_test_average')) {
    /**
     * Get user's test average score
     * @param int $user_id
     * @return float
     */
    function psych_get_user_test_average($user_id) {
        $test_results = get_user_meta($user_id, Psych_Unified_Report_Card_Enhanced::TEST_RESULTS_META_KEY, true) ?: [];

        if (empty($test_results)) {
            return 0;
        }

        $total_score = 0;
        foreach ($test_results as $result) {
            $total_score += floatval($result['score']);
        }

        return round($total_score / count($test_results), 2);
    }
}

// =====================================================================
// ADMIN INTEGRATION (Optional Dashboard Widget)
// =====================================================================

add_action('wp_dashboard_setup', function() {
    if (current_user_can('manage_options')) {
        wp_add_dashboard_widget(
            'psych_report_card_overview',
            'گزارش سیستم یادگیری',
            function() {
                $total_users = count_users()['total_users'];
                $active_paths = 0; // This would come from path engine
                $total_badges_awarded = 0;

                // Get total badges awarded
                global $wpdb;
                $total_badges_awarded = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->usermeta}
                     WHERE meta_key = 'psych_user_badges'"
                );

                ?>
                <div class="psych-dashboard-widget">
                    <div class="psych-stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; text-align: center;">
                        <div class="stat-item">
                            <h3 style="margin: 0; color: #3498db;"><?php echo number_format_i18n($total_users); ?></h3>
                            <p style="margin: 5px 0 0 0; color: #666;">کل کاربران</p>
                        </div>
                        <div class="stat-item">
                            <h3 style="margin: 0; color: #27ae60;"><?php echo number_format_i18n($active_paths); ?></h3>
                            <p style="margin: 5px 0 0 0; color: #666;">مسیرهای فعال</p>
                        </div>
                        <div class="stat-item">
                            <h3 style="margin: 0; color: #f39c12;"><?php echo number_format_i18n($total_badges_awarded); ?></h3>
                            <p style="margin: 5px 0 0 0; color: #666;">نشان‌های اعطا شده</p>
                        </div>
                    </div>

                    <p style="margin-top: 15px; text-align: center;">
                        <a href="<?php echo admin_url('admin.php?page=psych-gamification-center'); ?>" class="button">
                            مشاهده جزئیات بیشتر
                        </a>
                    </p>
                </div>
                <?php
            }
        );
    }
});

// =====================================================================
// REST API ENDPOINTS (for external integrations)
// =====================================================================

add_action('rest_api_init', function() {
    register_rest_route('psych/', '/report-card/(?P<user_id>\d+)', [
        'methods' => 'GET',
        'callback' => function($request) {
            $user_id = intval($request['user_id']);

            if (!current_user_can('manage_options') && get_current_user_id() !== $user_id) {
                return new WP_Error('unauthorized', 'دسترسی غیرمجاز', ['status' => 403]);
            }

            $instance = Psych_Unified_Report_Card_Enhanced::get_instance();

            return [
                'user_id' => $user_id,
                'summary' => $instance->get_user_summary_data($user_id),
                'level' => $instance->get_user_level_info($user_id),
                'badges' => $instance->get_user_badges_data($user_id),
                'path' => $instance->get_user_path_data($user_id),
                'test_average' => psych_get_user_test_average($user_id)
            ];
        },
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('psych/', '/report-card/(?P<user_id>\d+)/test', [
        'methods' => 'POST',
        'callback' => function($request) {
            $user_id = intval($request['user_id']);

            if (!current_user_can('manage_options')) {
                return new WP_Error('unauthorized', 'دسترسی غیرمجاز', ['status' => 403]);
            }

            $test_title = sanitize_text_field($request['title']);
            $test_score = floatval($request['score']);

            if (empty($test_title) || $test_score < 0 || $test_score > 100) {
                return new WP_Error('invalid_data', 'داده‌های نامعتبر', ['status' => 400]);
            }

            $result = psych_add_test_result($user_id, $test_title, $test_score);

            if ($result) {
                return ['success' => true, 'message' => 'نتیجه آزمون ثبت شد'];
            } else {
                return new WP_Error('save_failed', 'خطا در ثبت نتیجه', ['status' => 500]);
            }
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

// =====================================================================
// ADDITIONAL CSS FOR WIDGET STYLES
// =====================================================================

add_action('wp_head', function() {
    ?>
    <style>
        .psych-user-summary-widget {
            display: flex;
            align-items: center;
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin: 15px 0;
        }

        .psych-summary-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-left: 15px;
            border: 3px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .psych-summary-info h4 {
            margin: 0 0 8px 0;
            color: #2c3e50;
            font-size: 18px;
        }

        .psych-summary-stats {
            display: flex;
            gap: 15px;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .psych-summary-stats span {
            background: rgba(52, 152, 219, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
            color: #34495e;
            font-weight: 500;
        }

        .psych-path-progress-widget {
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin: 15px 0;
        }

        .psych-path-progress-widget.compact {
            padding: 15px;
        }

        .psych-path-header h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 16px;
        }

        .psych-path-stations {
            margin-top: 15px;
        }

        .psych-station-mini {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .psych-station-mini.completed {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border-right: 3px solid #27ae60;
        }

        .psych-station-mini.current {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
            border-right: 3px solid #f39c12;
        }

        .psych-station-mini.locked {
            background: rgba(149, 165, 166, 0.1);
            color: #95a5a6;
            border-right: 3px solid #95a5a6;
        }

        @media (max-width: 768px) {
            .psych-user-summary-widget {
                flex-direction: column;
                text-align: center;
            }

            .psych-summary-avatar {
                margin: 0 0 15px 0;
            }

            .psych-summary-stats {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
    <?php
}, 20);

// =====================================================================
// PERFORMANCE OPTIMIZATION
// =====================================================================

// Cache user summary data for 5 minutes
if (!function_exists('psych_get_cached_user_summary')) {
    function psych_get_cached_user_summary($user_id) {
        $cache_key = "psych_user_summary_{$user_id}";
        $cached_data = get_transient($cache_key);

        if ($cached_data === false) {
            $instance = Psych_Unified_Report_Card_Enhanced::get_instance();
            $cached_data = $instance->get_user_summary_data($user_id);
            set_transient($cache_key, $cached_data, 300); // 5 minutes
        }

        return $cached_data;
    }
}

// Clear cache when user data changes
add_action('psych_points_awarded', function($user_id) {
    delete_transient("psych_user_summary_{$user_id}");
});

add_action('psych_user_earned_badge', function($user_id) {
    delete_transient("psych_user_summary_{$user_id}");
});

// =====================================================================
// DEBUGGING AND LOGGING
// =====================================================================

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('psych_report_card_loaded', function() {
        error_log('Psych Report Card Enhanced Integration Edition loaded successfully');
    });
}

// Log SMS sending attempts
add_action('psych_send_user_report_sms', function($user_id) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Report SMS triggered for user ID: {$user_id}");
    }
});

?>