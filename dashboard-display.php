<?php
/**
 * Plugin Name: Psych Complete System - Dashboard Display (Enhanced Integration Edition)
 * Description: ماژول جامع داشبورد کاربری با یکپارچگی کامل و پشتیبانی از تمام ماژول‌ها
 * Version: 6.0.0 (Enhanced Integration Edition)
 * Author: Enhanced Integration Team
 *
 * فایل: dashboard-display.php  
 * این نسخه Enhanced شامل:
 * - هماهنگی کامل با Coach Module , Path Engine .2, Interactive Content .3, Gamification Center .5, Report Card 
 * - پشتیبانی کامل از Coach Impersonation
 * - استفاده از API Functions استاندارد
 * - داشبورد تعاملی و واکنش‌گرا
 * - سیستم کش پیشرفته
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Prevent double loading
if (class_exists('Psych_Dashboard_Display_Enhanced')) {
    return;
}

/**
 * Enhanced Dashboard Display Class with full integration support
 */
final class Psych_Dashboard_Display_Enhanced {

    const VERSION = '6.0.0';
    private static $instance = null;
    
    private $viewing_context = null;
    private $cache_expiry = 300; // 5 minutes cache
    
    /**
     * Singleton pattern
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_viewing_context();
        $this->add_hooks();
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
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_psych_dashboard_refresh', [$this, 'ajax_refresh_dashboard']);
        add_action('wp_ajax_psych_dashboard_toggle_widget', [$this, 'ajax_toggle_widget']);
        
        // Cache clearing hooks
        add_action('psych_points_awarded', [$this, 'clear_user_cache']);
        add_action('psych_user_earned_badge', [$this, 'clear_user_cache']);
        add_action('psych_user_level_up', [$this, 'clear_user_cache']);
    }

    public function enqueue_frontend_assets() {
    global $post;
    
    // اضافه کردن Font Awesome - آخرین نسخه 6.7.2
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css', [], '6.7.2');
    
    // Only load on pages with dashboard shortcodes
    if (is_a($post, 'WP_Post') && $this->has_dashboard_shortcode($post->post_content)) {
        wp_enqueue_script('jquery');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        
        wp_enqueue_script('psych-dashboard', false, ['jquery', 'chart-js'], self::VERSION, true);
        wp_add_inline_script('psych-dashboard', $this->get_frontend_js());
        
        wp_localize_script('psych-dashboard', 'psych_dashboard', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('psych_dashboard_nonce'),
            'viewing_context' => $this->get_viewing_context(),
            'refresh_interval' => 60000 // 1 minute
        ]);
        
        add_action('wp_head', [$this, 'print_frontend_styles']);
    }
}



    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'psych-dashboard') !== false) {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
            wp_enqueue_script('psych-dashboard-admin', false, ['jquery', 'chart-js'], self::VERSION, true);
            wp_add_inline_script('psych-dashboard-admin', $this->get_admin_js());
            
            wp_localize_script('psych-dashboard-admin', 'psych_dashboard_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('psych_dashboard_admin_nonce')
            ]);
            
            add_action('admin_head', [$this, 'print_admin_styles']);
        }
    }

    private function has_dashboard_shortcode($content) {
        $shortcodes = [
            'psych_dashboard',
            'psych_gamified_header', 
            'psych_user_performance_header',
            'psych_user_points_display',
            'psych_user_level_display',
            'psych_user_badges_collection',
            'psych_progress_path',
            'psych_user_leaderboard',
            'psych_achievement_timeline'
        ];
        
        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($content, $shortcode)) {
                return true;
            }
        }
        
        return false;
    }

    // =====================================================================
    // FRONTEND STYLES
    // =====================================================================

    public function print_frontend_styles() {
        ?>
        <style>
            .psych-admin-dashboard {
                background: #f1f1f1;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
            
            .psych-admin-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            
            .psych-admin-stat {
                background: #fff;
                padding: 15px;
                border-radius: 6px;
                text-align: center;
                border-left: 4px solid #3498db;
            }
            
            .psych-admin-stat h3 {
                margin: 0 0 10px 0;
                color: #3498db;
                font-size: 24px;
            }
            
            .psych-admin-stat p {
                margin: 0;
                color: #666;
                font-size: 14px;
            }
			/* =================================================================== */
/* ENHANCED LEVEL DISPLAY STYLES */
/* =================================================================== */

.psych-level-display-container {
    margin: 20px 0;
}

.psych-level-display {
    background: #fff;
    border: 1px solid #e1e8ed;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.psych-level-display::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--psych-gradient);
}

.psych-level-display:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.psych-level-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: white;
    background: var(--psych-primary);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    flex-shrink: 0;
    position: relative;
}

.psych-level-icon::after {
    content: '';
    position: absolute;
    top: -2px;
    left: -2px;
    right: -2px;
    bottom: -2px;
    border-radius: 50%;
    background: inherit;
    z-index: -1;
    filter: blur(8px);
    opacity: 0.3;
}

.psych-level-content {
    flex: 1;
    min-width: 0;
}

.psych-level-name {
    font-size: 24px;
    font-weight: 700;
    color: var(--psych-secondary);
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.psych-level-points {
    font-size: 16px;
    color: #7f8c8d;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.psych-level-points .current-points {
    font-weight: 700;
    color: var(--psych-primary);
    font-size: 18px;
}

.psych-level-points .separator {
    color: #bdc3c7;
    font-weight: 300;
}

.psych-level-points .next-points {
    font-weight: 600;
    color: #95a5a6;
}

.psych-level-points .label {
    font-size: 14px;
    color: #95a5a6;
    font-weight: 500;
}

.psych-level-progress {
    margin-top: 12px;
}

.psych-level-progress-bar {
    width: 100%;
    height: 10px;
    background: #ecf0f1;
    border-radius: 5px;
    overflow: hidden;
    position: relative;
    margin-bottom: 6px;
}

.psych-level-progress-fill {
    height: 100%;
    border-radius: 5px;
    background: var(--psych-primary);
    transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

.psych-level-progress-fill::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    animation: shimmer 2s infinite;
}

.psych-level-progress-text {
    font-size: 13px;
    color: #7f8c8d;
    font-weight: 500;
    text-align: center;
}

/* Compact Style */
.psych-level-compact .psych-level-display {
    padding: 15px;
    gap: 15px;
}

.psych-level-compact .psych-level-icon {
    width: 60px;
    height: 60px;
    font-size: 24px;
}

.psych-level-compact .psych-level-name {
    font-size: 18px;
    margin-bottom: 5px;
}

.psych-level-compact .psych-level-points {
    font-size: 14px;
    margin-bottom: 10px;
}

/* Minimal Style */
.psych-level-minimal .psych-level-display {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 10px 0;
}

.psych-level-minimal .psych-level-display::before {
    display: none;
}

.psych-level-minimal .psych-level-icon {
    width: 50px;
    height: 50px;
    font-size: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* =================================================================== */
/* ENHANCED BADGES COLLECTION STYLES - نسخه کامل */
/* =================================================================== */

/* =================================================================== */
/* BADGES COLLECTION - نسخه تصحیح شده برای نمایش چندستونی */
/* =================================================================== */

.psych-badges-collection-container {
    margin: 25px 0;
    font-family: var(--psych-font);
    width: 100%;
    box-sizing: border-box;
}

.psych-badges-collection {
    background: #fff;
    border-radius: var(--psych-radius);
    padding: 30px;
    box-shadow: var(--psych-shadow);
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.06);
    width: 100%;
    box-sizing: border-box;
}

.psych-badges-collection::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--psych-gradient);
    border-radius: var(--psych-radius) var(--psych-radius) 0 0;
}

/* Header Stats */
.psych-badges-header {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--psych-light);
}

.psych-badges-stats {
    display: flex;
    justify-content: center;
    gap: 30px;
    flex-wrap: wrap;
}

.psych-badges-stat {
    text-align: center;
    padding: 15px 20px;
    background: linear-gradient(135deg, var(--psych-light), #fff);
    border-radius: var(--psych-radius);
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    min-width: 100px;
}

.psych-badges-stat-value {
    display: block;
    font-size: 24px;
    font-weight: 800;
    color: var(--psych-primary);
    margin-bottom: 5px;
    line-height: 1;
}

.psych-badges-stat-label {
    font-size: 13px;
    color: var(--psych-secondary);
    font-weight: 600;
}

/* =================================================================== */
/* GRID LAYOUT - نمایش چندستونی اصلی */
/* =================================================================== */

.psych-badges-grid {
    display: grid;
    gap: 20px;
    margin-top: 20px;
    width: 100%;
    box-sizing: border-box;
    /* پیش‌فرض: 4 ستون */
    grid-template-columns: repeat(4, 1fr);
}

/* تعیین دقیق تعداد ستون‌ها */
.psych-columns-1 .psych-badges-grid {
    grid-template-columns: 1fr !important;
}

.psych-columns-2 .psych-badges-grid {
    grid-template-columns: repeat(2, 1fr) !important;
}

.psych-columns-3 .psych-badges-grid {
    grid-template-columns: repeat(3, 1fr) !important;
}

.psych-columns-4 .psych-badges-grid {
    grid-template-columns: repeat(4, 1fr) !important;
}

.psych-columns-5 .psych-badges-grid {
    grid-template-columns: repeat(5, 1fr) !important;
}

.psych-columns-6 .psych-badges-grid {
    grid-template-columns: repeat(6, 1fr) !important;
}

/* =================================================================== */
/* BADGE ITEM STYLES */
/* =================================================================== */

.psych-badge-item {
    background: #fff;
    border: 2px solid #e8ecf0;
    border-radius: var(--psych-radius);
    padding: 20px 15px 18px;
    text-align: center;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    cursor: pointer;
    min-height: 160px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    overflow: hidden;
    box-sizing: border-box;
    width: 100%;
}

.psych-badge-item::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.5s;
}

.psych-badge-item:hover::after {
    left: 100%;
}

.psych-badge-item:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    border-color: var(--psych-primary);
}

/* Earned Badge */
.psych-badge-item.earned {
    border-color: var(--psych-success);
    background: linear-gradient(135deg, #fff 0%, #f6fdf8 100%);
    box-shadow: 0 4px 20px rgba(39, 174, 96, 0.15);
}

.psych-badge-item.earned::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--psych-success);
    border-radius: var(--psych-radius) var(--psych-radius) 0 0;
}

.psych-badge-item.earned:hover {
    border-color: var(--psych-success);
    box-shadow: 0 15px 35px rgba(39, 174, 96, 0.25);
}

/* Locked Badge */
.psych-badge-item.locked {
    opacity: 0.65;
    background: #f8f9fa;
    border-color: #dee2e6;
    border-style: dashed;
}

.psych-badge-item.locked:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    border-color: #adb5bd;
}

/* Badge Icon */
.psych-badge-icon {
    font-size: 40px;
    margin-bottom: 12px;
    color: #bdc3c7;
    transition: all 0.3s ease;
    line-height: 1;
    display: block;
}

.psych-badge-item.earned .psych-badge-icon {
    color: var(--psych-success);
    text-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
}

.psych-badge-item.locked .psych-badge-icon {
    color: #adb5bd;
}

.psych-badge-item:hover .psych-badge-icon {
    transform: scale(1.1);
}

/* Badge Content */
.psych-badge-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.psych-badge-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--psych-secondary);
    margin: 0 0 8px 0;
    line-height: 1.3;
    min-height: 18px;
}

.psych-badge-item.earned .psych-badge-name {
    color: var(--psych-success);
}

.psych-badge-description {
    font-size: 12px;
    color: #7f8c8d;
    line-height: 1.4;
    margin: 0 0 8px 0;
    opacity: 0.9;
}

.psych-badge-item.locked .psych-badge-description {
    color: #adb5bd;
}

/* Badge Earned Indicator */
.psych-badge-earned {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 16px;
    color: #fff;
    background: var(--psych-success);
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
    border: 2px solid #fff;
    z-index: 2;
}

/* Badge Date */
.psych-badge-date {
    font-size: 10px;
    color: #95a5a6;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid rgba(149, 165, 166, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

/* Badge Requirement */
.psych-badge-requirement {
    font-size: 10px;
    color: #e67e22;
    margin-top: 6px;
    padding: 4px 8px;
    background: rgba(230, 126, 34, 0.1);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    line-height: 1.3;
}

/* Empty State */
.psych-badges-empty {
    text-align: center;
    padding: 60px 20px 40px;
    color: #95a5a6;
}

.psych-badges-empty .empty-icon {
    font-size: 72px;
    margin-bottom: 20px;
    opacity: 0.4;
    color: #bdc3c7;
}

.psych-badges-empty p {
    font-size: 16px;
    margin: 0 0 8px 0;
    font-weight: 500;
    color: #7f8c8d;
}

.psych-badges-empty small {
    font-size: 13px;
    color: #95a5a6;
    display: block;
}

/* =================================================================== */
/* LIST STYLE - نمایش لیستی */
/* =================================================================== */

.psych-style-list .psych-badges-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 15px;
}

.psych-style-list .psych-badge-item {
    display: flex;
    align-items: center;
    text-align: right;
    padding: 15px 20px;
    gap: 15px;
    min-height: auto;
    flex-direction: row;
}

.psych-style-list .psych-badge-icon {
    font-size: 28px;
    margin-bottom: 0;
    flex-shrink: 0;
}

.psych-style-list .psych-badge-content {
    flex: 1;
    text-align: right;
}

.psych-style-list .psych-badge-name {
    margin-bottom: 4px;
}

/* =================================================================== */
/* RESPONSIVE DESIGN */
/* =================================================================== */

/* Tablet */
@media (max-width: 1024px) {
    .psych-badges-collection {
        padding: 25px 20px;
    }
    
    .psych-badge-item {
        padding: 18px 12px 15px;
        min-height: 145px;
    }
    
    .psych-badge-icon {
        font-size: 36px;
    }
    
    /* تنظیم ستون‌ها برای تبلت */
    .psych-columns-5 .psych-badges-grid,
    .psych-columns-6 .psych-badges-grid {
        grid-template-columns: repeat(4, 1fr) !important;
    }
}

/* Mobile Large */
@media (max-width: 768px) {
    .psych-badges-collection {
        padding: 20px 15px;
    }
    
    .psych-badges-grid {
        gap: 15px;
    }
    
    .psych-badge-item {
        padding: 15px 10px 12px;
        min-height: 130px;
    }
    
    .psych-badge-icon {
        font-size: 32px;
        margin-bottom: 10px;
    }
    
    .psych-badge-name {
        font-size: 13px;
    }
    
    .psych-badge-description {
        font-size: 11px;
    }
    
    .psych-badges-stats {
        gap: 15px;
    }
    
    .psych-badges-stat {
        min-width: 80px;
        padding: 12px 15px;
    }
    
    .psych-badges-stat-value {
        font-size: 20px;
    }
    
    .psych-badges-stat-label {
        font-size: 12px;
    }
    
    /* تنظیم ستون‌ها برای موبایل بزرگ */
    .psych-columns-4 .psych-badges-grid,
    .psych-columns-5 .psych-badges-grid,
    .psych-columns-6 .psych-badges-grid {
        grid-template-columns: repeat(3, 1fr) !important;
    }
}

/* Mobile Small */
@media (max-width: 480px) {
    .psych-badges-collection {
        padding: 15px 10px;
    }
    
    .psych-badges-grid {
        gap: 12px;
    }
    
    .psych-badge-item {
        padding: 12px 8px 10px;
        min-height: 115px;
    }
    
    .psych-badge-icon {
        font-size: 28px;
        margin-bottom: 8px;
    }
    
    .psych-badge-name {
        font-size: 12px;
        margin-bottom: 6px;
    }
    
    .psych-badge-description {
        font-size: 10px;
    }
    
    .psych-badge-earned {
        width: 22px;
        height: 22px;
        font-size: 12px;
        top: 8px;
        right: 8px;
    }
    
    .psych-badges-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .psych-badges-stat {
        min-width: auto;
        padding: 10px 12px;
    }
    
    /* تنظیم ستون‌ها برای موبایل کوچک */
    .psych-columns-3 .psych-badges-grid,
    .psych-columns-4 .psych-badges-grid,
    .psych-columns-5 .psych-badges-grid,
    .psych-columns-6 .psych-badges-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

/* =================================================================== */
/* LOADING & ANIMATION STATES */
/* =================================================================== */

.psych-badges-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: #7f8c8d;
}

.psych-badges-loading::before {
    content: '';
    display: inline-block;
    width: 32px;
    height: 32px;
    border: 3px solid var(--psych-primary);
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s linear infinite;
    margin-left: 15px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Enhanced animations */
@media (prefers-reduced-motion: no-preference) {
    .psych-badge-item {
        animation: fadeInUp 0.5s ease-out;
    }
    
    .psych-badge-item:nth-child(2) { animation-delay: 0.1s; }
    .psych-badge-item:nth-child(3) { animation-delay: 0.2s; }
    .psych-badge-item:nth-child(4) { animation-delay: 0.3s; }
    .psych-badge-item:nth-child(5) { animation-delay: 0.4s; }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
}
        </style>  
        <?php  
    }  

    // =====================================================================  
    // FRONTEND JAVASCRIPT  
    // =====================================================================  

    private function get_frontend_js() {  
        return '  
        window.PsychDashboard = {  
            init: function() {  
                this.initProgressBars();  
                this.initCharts();  
                this.initWidgetToggles();  
                this.initAutoRefresh();  
                this.initScrollAnimations();  
            },  

            initProgressBars: function() {  
                // Animate progress bars  
                setTimeout(() => {  
                    jQuery(".psych-level-progress-fill, .psych-gh-level-progress-bar").each(function() {  
                        var percent = jQuery(this).data("percent") || 0;  
                        jQuery(this).css("width", percent + "%");  
                    });  

                    jQuery(".psych-path-progress-line").each(function() {  
                        var percent = jQuery(this).data("percent") || 0;  
                        jQuery(this).css("width", percent + "%");  
                    });  
                }, 500);  
            },  

            initCharts: function() {  
                // Initialize any charts if Chart.js is available  
                if (typeof Chart !== "undefined") {  
                    this.initProgressChart();  
                    this.initBadgesChart();  
                }  
            },  

            initProgressChart: function() {  
                var chartEl = document.getElementById("psych-progress-chart");  
                if (!chartEl) return;  

                var data = JSON.parse(chartEl.dataset.chartData || "{}");  
                
                new Chart(chartEl, {  
                    type: "line",  
                    data: {  
                        labels: data.labels || [],  
                        datasets: [{  
                            label: "پیشرفت امتیازات",  
                            data: data.points || [],  
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
                                display: false  
                            }  
                        },  
                        scales: {  
                            y: {  
                                beginAtZero: true  
                            }  
                        }  
                    }  
                });  
            },  

            initBadgesChart: function() {  
                var chartEl = document.getElementById("psych-badges-chart");  
                if (!chartEl) return;  

                var data = JSON.parse(chartEl.dataset.chartData || "{}");  
                
                new Chart(chartEl, {  
                    type: "doughnut",  
                    data: {  
                        labels: ["کسب شده", "باقی‌مانده"],  
                        datasets: [{  
                            data: [data.earned || 0, data.remaining || 0],  
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
            },  

            initWidgetToggles: function() {  
                jQuery(".psych-widget-toggle").on("click", function() {  
                    var $widget = jQuery(this).closest(".psych-dashboard-widget");  
                    var $content = $widget.find(".psych-widget-content");  
                    var widgetId = $widget.data("widget-id");  

                    $content.slideToggle(300, function() {  
                        var isCollapsed = !$content.is(":visible");  
                        jQuery(this).toggleClass("collapsed", isCollapsed);  
                        
                        // Save state  
                        if (widgetId) {  
                            PsychDashboard.saveWidgetState(widgetId, isCollapsed);  
                        }  
                    });  
                });  
            },  

            saveWidgetState: function(widgetId, isCollapsed) {  
                jQuery.post(psych_dashboard.ajax_url, {  
                    action: "psych_dashboard_toggle_widget",  
                    nonce: psych_dashboard.nonce,  
                    widget_id: widgetId,  
                    collapsed: isCollapsed  
                });  
            },  

            initAutoRefresh: function() {  
                if (psych_dashboard.refresh_interval > 0) {  
                    setInterval(() => {  
                        this.refreshDashboard();  
                    }, psych_dashboard.refresh_interval);  
                }  
            },  

            refreshDashboard: function() {  
                // Refresh dynamic content without full page reload  
                jQuery.post(psych_dashboard.ajax_url, {  
                    action: "psych_dashboard_refresh",  
                    nonce: psych_dashboard.nonce  
                }).done(function(response) {  
                    if (response.success) {  
                        // Update points display  
                        if (response.data.points !== undefined) {  
                            jQuery(".psych-points-value").text(response.data.points.toLocaleString());  
                        }  
                        
                        // Update level progress  
                        if (response.data.level_progress !== undefined) {  
                            jQuery(".psych-level-progress-fill").css("width", response.data.level_progress + "%");  
                        }  
                        
                        // Update badges count  
                        if (response.data.badges_count !== undefined) {  
                            jQuery(".psych-badges-count").text(response.data.badges_count);  
                        }  
                    }  
                });  
            },  

            initScrollAnimations: function() {  
                // Intersection Observer for animations  
                if ("IntersectionObserver" in window) {  
                    var observer = new IntersectionObserver(function(entries) {  
                        entries.forEach(function(entry) {  
                            if (entry.isIntersecting) {  
                                entry.target.classList.add("psych-animate-in");  
                            }  
                        });  
                    }, {  
                        threshold: 0.1  
                    });  

                    jQuery(".psych-dashboard-widget").each(function() {  
                        observer.observe(this);  
                    });  
                }  
            }  
        };  

        jQuery(document).ready(function() {  
            PsychDashboard.init();  
        });  
        ';  
    }  

    // =====================================================================  
    // SHORTCODE REGISTRATIONS  
    // =====================================================================  

    public function register_shortcodes() {  
        add_shortcode('psych_dashboard', [$this, 'render_main_dashboard']);  
        add_shortcode('psych_gamified_header', [$this, 'render_gamified_header']);  
        add_shortcode('psych_user_performance_header', [$this, 'render_performance_header']);  
        add_shortcode('psych_user_points_display', [$this, 'render_points_display']);  
        add_shortcode('psych_user_level_display', [$this, 'render_level_display']);  
        add_shortcode('psych_user_badges_collection', [$this, 'render_badges_collection']);  
        add_shortcode('psych_progress_path', [$this, 'render_progress_path']);  
        add_shortcode('psych_user_leaderboard', [$this, 'render_leaderboard']);  
        add_shortcode('psych_achievement_timeline', [$this, 'render_achievement_timeline']);  
    }  

    // =====================================================================  
    // MAIN DASHBOARD SHORTCODE  
    // =====================================================================  

    public function render_main_dashboard($atts) {  
        $atts = shortcode_atts([  
            'user_id' => 0,  
            'layout' => 'grid', // grid, list, compact  
            'widgets' => 'points,level,badges,path,leaderboard,timeline',  
            'columns' => 'auto'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];  

        if (!$user_id) {  
            return '<div class="psych-error">کاربر مشخص نشده است.</div>';  
        }  

        $widgets = array_map('trim', explode(',', $atts['widgets']));  
        $columns_class = $this->get_columns_class($atts['columns'], count($widgets));  

        ob_start();  
        ?>  
        <div class="psych-dashboard-container">  
            <?php if ($context['is_impersonating']): ?>  
                <div class="psych-impersonation-notice">  
                    <i class="fas fa-eye"></i>  
                    شما در حال مشاهده داشبورد <strong><?php echo esc_html(get_userdata($user_id)->display_name); ?></strong> هستید  
                </div>  
            <?php endif; ?>  

            <div class="psych-dashboard-grid <?php echo esc_attr($columns_class); ?>">  
                <?php foreach ($widgets as $widget): ?>  
                    <?php $this->render_dashboard_widget($widget, $user_id, $context); ?>  
                <?php endforeach; ?>  
            </div>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    private function get_columns_class($columns, $widget_count) {  
        if ($columns === 'auto') {  
            if ($widget_count <= 2) return 'columns-2';  
            if ($widget_count <= 3) return 'columns-3';  
            return 'columns-4';  
        }  
        
        return 'columns-' . intval($columns);  
    }  

    private function render_dashboard_widget($widget_type, $user_id, $context) {  
        $widget_data = $this->get_widget_data($widget_type, $user_id);  
        
        if (!$widget_data) return;  

        ?>  
        <div class="psych-dashboard-widget" data-widget-id="<?php echo esc_attr($widget_type); ?>">  
            <div class="psych-widget-header">  
                <h3 class="psych-widget-title">  
                    <i class="<?php echo esc_attr($widget_data['icon']); ?>"></i>  
                    <?php echo esc_html($widget_data['title']); ?>  
                </h3>  
                <div class="psych-widget-actions">  
                    <button class="psych-widget-toggle" title="بستن/باز کردن">  
                        <i class="fas fa-chevron-up"></i>  
                    </button>  
                </div>  
            </div>  
            <div class="psych-widget-content">  
                <?php echo $widget_data['content']; ?>  
            </div>  
        </div>  
        <?php  
    }  

    private function get_widget_data($widget_type, $user_id) {  
        $widget_map = [  
            'points' => [  
                'title' => 'امتیازات',  
                'icon' => 'fas fa-star',  
                'content' => $this->render_points_widget($user_id)  
            ],  
            'level' => [  
                'title' => 'سطح',  
                'icon' => 'fas fa-trophy',  
                'content' => $this->render_level_widget($user_id)  
            ],  
            'badges' => [  
                'title' => 'نشان‌ها',  
                'icon' => 'fas fa-medal',  
                'content' => $this->render_badges_widget($user_id)  
            ],  
            'path' => [  
                'title' => 'مسیر یادگیری',  
                'icon' => 'fas fa-route',  
                'content' => $this->render_path_widget($user_id)  
            ],  
            'leaderboard' => [  
                'title' => 'رتبه‌بندی',  
                'icon' => 'fas fa-list-ol',  
                'content' => $this->render_leaderboard_widget($user_id)  
            ],  
            'timeline' => [  
                'title' => 'دستاوردهای اخیر',  
                'icon' => 'fas fa-history',  
                'content' => $this->render_timeline_widget($user_id)  
            ]  
        ];  

        return $widget_map[$widget_type] ?? null;  
    }  

    // =====================================================================  
    // INDIVIDUAL SHORTCODE IMPLEMENTATIONS  
    // =====================================================================  

    public function render_gamified_header($atts) {  
        $atts = shortcode_atts([  
            'user_id' => 0,  
            'show_avatar' => 'true',  
            'show_level_progress' => 'true'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];  

        if (!$user_id) return '';  

        $user_data = $this->get_cached_user_data($user_id);  
        
        ob_start();  
        ?>  
        <div class="psych-gamified-header">  
            <div class="psych-gh-user">  
                <?php if ($atts['show_avatar'] === 'true'): ?>  
                    <img src="<?php echo esc_url(get_avatar_url($user_id, ['size' => 40])); ?>"   
                         alt="آواتار" class="psych-gh-avatar">  
                <?php endif; ?>  
                <div class="psych-gh-user-info">  
                    <h3><?php echo esc_html($user_data['display_name']); ?></h3>  
                    <p><?php echo esc_html($user_data['level']['name']); ?></p>  
                </div>  
            </div>  
            
            <div class="psych-gh-stats">  
                <div class="psych-gh-stat">  
                    <i class="fas fa-star" style="color: #f1c40f;"></i>  
                    <span><?php echo number_format_i18n($user_data['points']); ?></span>  
                </div>  
                <div class="psych-gh-stat">  
                    <i class="fas fa-trophy" style="color: #e67e22;"></i>  
                    <span><?php echo number_format_i18n($user_data['badges_count']); ?></span>  
                </div>  
                <?php if ($atts['show_level_progress'] === 'true' && $user_data['level']['points_to_next'] > 0): ?>  
                    <div class="psych-gh-stat">  
                        <span>تا سطح بعد:</span>  
                        <div class="psych-gh-level-progress">  
                            <div class="psych-gh-level-progress-bar"   
                                 data-percent="<?php echo esc_attr($user_data['level']['progress_percentage']); ?>"></div>  
                        </div>  
                    </div>  
                <?php endif; ?>  
            </div>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    public function render_performance_header($atts) {  
        $atts = shortcode_atts([  
            'user_id' => 0,  
            'show_stats' => 'points,badges,level,progress'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];  

        if (!$user_id) return '';  

        $user_data = $this->get_cached_user_data($user_id);  
        $stats = array_map('trim', explode(',', $atts['show_stats']));  

        ob_start();  
        ?>  
        <div class="psych-perf-header">  
            <div class="psych-perf-user">  
                <img src="<?php echo esc_url(get_avatar_url($user_id, ['size' => 80])); ?>"   
                     alt="آواتار" class="psych-perf-avatar">  
                <div>  
                    <h2 class="psych-perf-user-name"><?php echo esc_html($user_data['display_name']); ?></h2>  
                    <div class="psych-perf-user-level" style="color: <?php echo esc_attr($user_data['level']['color']); ?>">  
                        <i class="<?php echo esc_attr($user_data['level']['icon']); ?>"></i>  
                        <?php echo esc_html($user_data['level']['name']); ?>  
                    </div>  
                </div>  
            </div>  
            
            <div class="psych-perf-stats">  
                <?php if (in_array('points', $stats)): ?>  
                    <div class="psych-perf-stat">  
                        <div class="psych-perf-stat-value"><?php echo number_format_i18n($user_data['points']); ?></div>  
                        <div class="psych-perf-stat-label">امتیاز</div>  
                    </div>  
                <?php endif; ?>  
                
                <?php if (in_array('badges', $stats)): ?>  
                    <div class="psych-perf-stat">  
                        <div class="psych-perf-stat-value"><?php echo number_format_i18n($user_data['badges_count']); ?></div>  
                        <div class="psych-perf-stat-label">نشان</div>  
                    </div>  
                <?php endif; ?>  
                
                <?php if (in_array('progress', $stats) && isset($user_data['path_progress'])): ?>  
                    <div class="psych-perf-stat">  
                        <div class="psych-perf-stat-value"><?php echo $user_data['path_progress']; ?>%</div>  
                        <div class="psych-perf-stat-label">پیشرفت</div>  
                    </div>  
                <?php endif; ?>  
            </div>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    public function render_points_display($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,
        'style' => 'default', // default, minimal, gradient, compact
        'show_label' => 'true',
        'show_trend' => 'false',
        'animated' => 'true'
    ], $atts);

    $context = $this->get_viewing_context();
    $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

    if (!$user_id) return '<div class="psych-error">کاربر مشخص نشده است.</div>';

    $user_data = $this->get_cached_user_data($user_id);
    $points = $user_data['points'];
    
    // محاسبه trend در صورت درخواست
    $trend_data = '';
    if ($atts['show_trend'] === 'true') {
        $previous_points = get_user_meta($user_id, 'psych_points_last_week', true) ?: 0;
        $change = $points - $previous_points;
        $trend_class = $change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral');
        $trend_icon = $change > 0 ? 'fa-arrow-up' : ($change < 0 ? 'fa-arrow-down' : 'fa-minus');
        
        $trend_data = sprintf(
            '<div class="psych-points-trend %s"><i class="fas %s"></i> %s</div>',
            $trend_class,
            $trend_icon,
            $change != 0 ? sprintf('%+d این هفته', $change) : 'بدون تغییر'
        );
    }

    $animated_class = $atts['animated'] === 'true' ? 'animated' : '';

    ob_start();
    ?>
    <div class="psych-points-display style-<?php echo esc_attr($atts['style']); ?> <?php echo esc_attr($animated_class); ?>">
        <div class="psych-points-content">
            <div class="psych-points-value" 
                 data-count="<?php echo esc_attr($points); ?>">
                <?php echo number_format_i18n($points); ?>
            </div>
            <?php if ($atts['show_label'] === 'true'): ?>
                <div class="psych-points-label">امتیاز کسب شده</div>
            <?php endif; ?>
            <?php echo $trend_data; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

    public function render_level_display($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,
        'show_progress' => 'true',
        'show_next_level' => 'true',
        'show_rewards' => 'false',
        'style' => 'default' // default, compact, minimal
    ], $atts);

    $context = $this->get_viewing_context();
    $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

    if (!$user_id) return '<div class="psych-error">کاربر مشخص نشده است.</div>';

    $user_data = $this->get_cached_user_data($user_id);
    $level = $user_data['level'];

    ob_start();
    ?>
    <div class="psych-level-display style-<?php echo esc_attr($atts['style']); ?>">
        <div class="psych-level-header">
            <div class="psych-level-icon" style="background: <?php echo esc_attr($level['color'] ?: 'var(--psych-primary)'); ?>">
                <i class="<?php echo esc_attr($level['icon'] ?: 'fas fa-trophy'); ?>"></i>
            </div>
            <div class="psych-level-info">
                <h3 class="psych-level-name"><?php echo esc_html($level['name'] ?: 'مبتدی'); ?></h3>
                <div class="psych-level-number">سطح <?php echo intval($level['level'] ?: 1); ?></div>
            </div>
        </div>

        <?php if ($atts['show_progress'] === 'true' && ($level['points_to_next'] ?? 0) > 0): ?>
            <div class="psych-level-progress">
                <div class="psych-level-progress-bar">
                    <div class="psych-level-progress-fill"
                         data-percent="<?php echo esc_attr($level['progress_percentage'] ?: 0); ?>"
                         style="width: <?php echo esc_attr($level['progress_percentage'] ?: 0); ?>%"></div>
                </div>
                <div class="psych-level-progress-text">
                    <span class="current"><?php echo number_format_i18n($level['current_points'] ?: 0); ?></span>
                    /
                    <span class="target"><?php echo number_format_i18n($level['points_required'] ?: 100); ?></span>
                    امتیاز
                </div>
                <?php if ($atts['show_next_level'] === 'true'): ?>
                    <div class="psych-level-next">
                        <?php echo number_format_i18n($level['points_to_next']); ?> امتیاز تا 
                        <strong><?php echo esc_html($level['next_level_name'] ?: 'سطح بعدی'); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($atts['show_rewards'] === 'true' && !empty($level['rewards'])): ?>
            <div class="psych-level-rewards">
                <h4>جوایز این سطح:</h4>
                <ul class="psych-rewards-list">
                    <?php foreach ($level['rewards'] as $reward): ?>
                        <li><i class="fas fa-gift"></i> <?php echo esc_html($reward); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}



    public function render_badges_collection($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,
        'show_locked' => 'true',
        'limit' => 12,
        'columns' => '4',
        'style' => 'grid', // grid, list
        'show_progress' => 'true'
    ], $atts);

    $context = $this->get_viewing_context();
    $user_id = $atts['user_id'] ?: $context['viewed_user_id'];

    if (!$user_id) {
        return '<div class="psych-error">کاربر مشخص نشده است.</div>';
    }

    $badges_data = $this->get_user_badges_data($user_id);
    $limit = intval($atts['limit']);
    $columns = max(1, min(6, intval($atts['columns']))); // محدود کردن ستون‌ها بین 1 تا 6
    
    // کلاس‌های مخصوص ستون‌ها و استایل
    $container_classes = [
        'psych-badges-collection-container',
        'psych-columns-' . $columns,
        $atts['style'] === 'list' ? 'psych-style-list' : 'psych-style-grid'
    ];

    ob_start();
    ?>
    <div class="<?php echo esc_attr(implode(' ', $container_classes)); ?>">
        <div class="psych-badges-collection">
            
            <?php if ($atts['show_progress'] === 'true'): ?>
            <div class="psych-badges-header">
                <div class="psych-badges-stats">
                    <div class="psych-badges-stat">
                        <span class="psych-badges-stat-value"><?php echo count($badges_data['earned']); ?></span>
                        <span class="psych-badges-stat-label">کسب شده</span>
                    </div>
                    <div class="psych-badges-stat">
                        <span class="psych-badges-stat-value"><?php echo count($badges_data['available']); ?></span>
                        <span class="psych-badges-stat-label">در دسترس</span>
                    </div>
                    <div class="psych-badges-stat">
                        <span class="psych-badges-stat-value"><?php echo count($badges_data['earned']) + count($badges_data['available']); ?></span>
                        <span class="psych-badges-stat-label">کل نشان‌ها</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($badges_data['earned']) || (!empty($badges_data['available']) && $atts['show_locked'] === 'true')): ?>
                
                <div class="psych-badges-grid">
                    <?php
                    $displayed = 0;
                    $all_badges = array_merge($badges_data['earned'], ($atts['show_locked'] === 'true' ? $badges_data['available'] : []));
                    
                    foreach ($all_badges as $badge):
                        if ($displayed >= $limit) break;
                        $displayed++;
                        
                        $is_earned = in_array($badge, $badges_data['earned'], true);
                        $badge_class = $is_earned ? 'earned' : 'locked';
                    ?>
                        <div class="psych-badge-item <?php echo esc_attr($badge_class); ?>" 
                             data-badge-id="<?php echo esc_attr($badge['id']); ?>">
                            
                            <?php if ($is_earned): ?>
                            <div class="psych-badge-earned">
                                <i class="fas fa-check"></i>
                            </div>
                            <?php endif; ?>
                            
                            <div class="psych-badge-icon">
                                <i class="<?php echo esc_attr($badge['icon'] ?: ($is_earned ? 'fas fa-award' : 'fas fa-lock')); ?>"></i>
                            </div>
                            
                            <div class="psych-badge-content">
                                <h4 class="psych-badge-name"><?php echo esc_html($badge['name']); ?></h4>
                                <p class="psych-badge-description"><?php echo esc_html($badge['description']); ?></p>
                                
                                <?php if ($is_earned && isset($badge['earned_date'])): ?>
                                <div class="psych-badge-date">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo esc_html(date_i18n('j F Y', strtotime($badge['earned_date']))); ?>
                                </div>
                                <?php elseif (!$is_earned && isset($badge['requirement'])): ?>
                                <div class="psych-badge-requirement">
                                    <i class="fas fa-info-circle"></i>
                                    <?php echo esc_html($badge['requirement']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="psych-badges-empty">
                    <div class="empty-icon">
                        <i class="fas fa-medal"></i>
                    </div>
                    <p>هنوز نشانی کسب نشده است.</p>
                    <small>با شرکت در فعالیت‌ها و کسب امتیاز نشان‌های خود را دریافت کنید.</small>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
    <?php
    return ob_get_clean();
}


    public function render_progress_path($atts) {  
        $atts = shortcode_atts([  
            'user_id' => 0,  
            'path_id' => 0,  
            'show_progress_line' => 'true'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];  

        if (!$user_id) return '';  

        $path_data = $this->get_user_path_data($user_id, $atts['path_id']);  

        if (empty($path_data['stations'])) {  
            return '<div class="psych-empty-state">  
                        <i class="fas fa-route"></i>  
                        <p>مسیری انتخاب نشده است.</p>  
                    </div>';  
        }  

        ob_start();  
        ?>  
        <div class="psych-progress-path">  
            <?php if ($atts['show_progress_line'] === 'true'): ?>  
                <div class="psych-path-line">  
                    <div class="psych-path-progress-line"   
                         data-percent="<?php echo esc_attr($path_data['completion_percentage']); ?>"></div>  
                </div>  
            <?php endif; ?>  
            
            <div class="psych-path-stations">  
                <?php foreach ($path_data['stations'] as $station): ?>  
                    <div class="psych-path-station <?php echo esc_attr($station['status']); ?>">  
                        <div class="psych-station-circle">  
                            <i class="<?php echo esc_attr($station['icon']); ?>"></i>  
                        </div>  
                        <h4 class="psych-station-title"><?php echo esc_html($station['title']); ?></h4>  
                        <p class="psych-station-description"><?php echo esc_html($station['description']); ?></p>  
                    </div>  
                <?php endforeach; ?>  
            </div>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    public function render_leaderboard($atts) {  
        $atts = shortcode_atts([  
            'limit' => 10,  
            'show_current_user' => 'true',  
            'highlight_top3' => 'true'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $context['viewed_user_id'];  
        
        $leaderboard_data = $this->get_leaderboard_data(intval($atts['limit']), $user_id);  

        ob_start();  
        ?>  
        <div class="psych-leaderboard">  
            <div class="psych-leaderboard-header">  
                <h3 class="psych-leaderboard-title">جدول امتیازات</h3>  
            </div>  
            
            <ul class="psych-leaderboard-list">  
                <?php foreach ($leaderboard_data['users'] as $index => $user): ?>  
                    <li class="psych-leaderboard-item <?php echo $user['ID'] == $user_id ? 'current-user' : ''; ?>">  
                        <div class="psych-leaderboard-rank <?php echo $this->get_rank_class($index + 1, $atts['highlight_top3']); ?>">  
                            <?php echo $index + 1; ?>  
                        </div>  
                        <img src="<?php echo esc_url(get_avatar_url($user['ID'], ['size' => 40])); ?>"   
                             alt="آواتار" class="psych-leaderboard-avatar">  
                        <div class="psych-leaderboard-user">  
                            <div class="psych-leaderboard-name"><?php echo esc_html($user['display_name']); ?></div>  
                            <div class="psych-leaderboard-level"><?php echo esc_html($user['level_name']); ?></div>  
                        </div>  
                        <div class="psych-leaderboard-points"><?php echo number_format_i18n($user['points']); ?></div>  
                    </li>  
                <?php endforeach; ?>  
            </ul>  
            
            <?php if ($atts['show_current_user'] === 'true' && $leaderboard_data['user_rank'] > intval($atts['limit'])): ?>  
                <div class="psych-leaderboard-item current-user" style="margin-top: 10px; border-top: 2px solid var(--psych-light);">  
                    <div class="psych-leaderboard-rank"><?php echo $leaderboard_data['user_rank']; ?></div>  
                    <img src="<?php echo esc_url(get_avatar_url($user_id, ['size' => 40])); ?>"   
                         alt="آواتار" class="psych-leaderboard-avatar">  
                    <div class="psych-leaderboard-user">  
                        <div class="psych-leaderboard-name"><?php echo esc_html($leaderboard_data['current_user']['display_name']); ?></div>  
                        <div class="psych-leaderboard-level"><?php echo esc_html($leaderboard_data['current_user']['level_name']); ?></div>  
                    </div>  
                    <div class="psych-leaderboard-points"><?php echo number_format_i18n($leaderboard_data['current_user']['points']); ?></div>  
                </div>  
            <?php endif; ?>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    public function render_achievement_timeline($atts) {  
        $atts = shortcode_atts([  
            'user_id' => 0,  
            'limit' => 10,  
            'show_empty' => 'true'  
        ], $atts);  

        $context = $this->get_viewing_context();  
        $user_id = $atts['user_id'] ?: $context['viewed_user_id'];  

        if (!$user_id) return '';  

        $achievements = $this->get_user_achievements($user_id, intval($atts['limit']));  

        ob_start();  
        ?>  
        <div class="psych-achievement-timeline">  
            <?php if (!empty($achievements)): ?>  
                <?php foreach ($achievements as $achievement): ?>  
                    <div class="psych-timeline-item">  
                        <div class="psych-timeline-content">  
                            <h4 class="psych-timeline-title"><?php echo esc_html($achievement['title']); ?></h4>  
                            <p class="psych-timeline-description"><?php echo esc_html($achievement['description']); ?></p>  
                            <div class="psych-timeline-date"><?php echo human_time_diff(strtotime($achievement['date'])); ?> پیش</div>  
                        </div>  
                    </div>  
                <?php endforeach; ?>  
                <div class="psych-timeline-line"></div>  
            <?php else: ?>  
                <?php if ($atts['show_empty'] === 'true'): ?>  
                    <div class="psych-empty-state">  
                        <i class="fas fa-history"></i>  
                        <p>هنوز دستاوردی ثبت نشده است.</p>  
                    </div>  
                <?php endif; ?>  
            <?php endif; ?>  
        </div>  
        <?php  
        return ob_get_clean();  
    }  

    // =====================================================================  
    // WIDGET CONTENT RENDERERS  
    // =====================================================================  

    private function render_points_widget($user_id) {  
        return $this->render_points_display(['user_id' => $user_id]);  
    }  

    private function render_level_widget($user_id) {  
        return $this->render_level_display(['user_id' => $user_id]);  
    }  

    private function render_badges_widget($user_id) {  
        return $this->render_badges_collection(['user_id' => $user_id, 'limit' => 6]);  
    }  

    private function render_path_widget($user_id) {  
        return $this->render_progress_path(['user_id' => $user_id]);  
    }  

    private function render_leaderboard_widget($user_id) {  
        return $this->render_leaderboard(['limit' => 5]);  
    }  

    private function render_timeline_widget($user_id) {  
        return $this->render_achievement_timeline(['user_id' => $user_id, 'limit' => 5]);  
    }  

    // =====================================================================  
    // DATA RETRIEVAL METHODS (Integration with other modules)  
    // =====================================================================  

    private function get_cached_user_data($user_id) {  
        $cache_key = "psych_dashboard_user_data_{$user_id}";  
        $cached_data = get_transient($cache_key);  

        if ($cached_data === false) {  
            $cached_data = $this->fetch_user_data($user_id);  
            set_transient($cache_key, $cached_data, $this->cache_expiry);  
        }  

        return $cached_data;  
    }  

    private function fetch_user_data($user_id) {  
        $user = get_userdata($user_id);  
        if (!$user) return null;  

        // Get data from integrated modules  
        $total_points = $this->get_user_total_points($user_id);  
        $level_info = $this->get_user_level_info($user_id);  
        $badges_count = $this->get_user_badges_count($user_id);  
        $path_progress = $this->get_user_path_progress($user_id);  

        return [  
            'ID' => $user_id,  
            'display_name' => $user->display_name,  
            'points' => $total_points,  
            'level' => $level_info,  
            'badges_count' => $badges_count,  
            'path_progress' => $path_progress  
        ];  
    }  

    private function get_user_total_points($user_id) {  
        // Integration with Gamification Center  
        if (class_exists('Psych_Gamification_Center')) {  
            $instance = Psych_Gamification_Center::get_instance();  
            if (method_exists($instance, 'get_user_total_points')) {  
                return $instance->get_user_total_points($user_id);  
            }  
        }  
        
        // Fallback  
        return (int) get_user_meta($user_id, 'psych_total_points', true);  
    }  

    private function get_user_level_info($user_id) {  
        // Integration with Gamification Center  
        if (function_exists('psych_gamification_get_user_level_info')) {  
            return psych_gamification_get_user_level_info($user_id);  
        }  
        
        // Fallback  
        return [  
            'name' => 'تازه‌کار',  
            'icon' => 'fas fa-seedling',  
            'color' => '#95a5a6',  
            'points_to_next' => 100,  
            'current_points' => $this->get_user_total_points($user_id),  
            'progress_percentage' => 0  
        ];  
    }  

    private function get_user_badges_count($user_id) {  
        // Integration with Gamification Center  
        if (class_exists('Psych_Gamification_Center')) {  
            $instance = Psych_Gamification_Center::get_instance();  
            if (method_exists($instance, 'get_user_badges_count')) {  
                return $instance->get_user_badges_count($user_id);  
            }  
        }  
        
        // Fallback  
        $badges = get_user_meta($user_id, 'psych_user_badges', true) ?: [];  
        return count($badges);  
    }  

    private function get_user_badges_data($user_id) {
    $cache_key = "psych_user_badges_{$user_id}";
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }

    $badges_data = [
        'earned' => [],
        'available' => []
    ];

    // استفاده از Gamification Center API در صورت وجود
    if (function_exists('psych_gamification_get_user_badges')) {
        $user_badges = psych_gamification_get_user_badges($user_id);
        $all_badges = psych_gamification_get_all_badges();
        
        $earned_ids = wp_list_pluck($user_badges, 'badge_id');
        
        foreach ($all_badges as $badge) {
            if (in_array($badge['id'], $earned_ids)) {
                $earned_badge = array_filter($user_badges, function($ub) use ($badge) {
                    return $ub['badge_id'] == $badge['id'];
                });
                $earned_badge = reset($earned_badge);
                
                $badges_data['earned'][] = [
                    'id' => $badge['id'],
                    'name' => $badge['name'],
                    'description' => $badge['description'],
                    'icon' => $badge['icon'] ?: 'fas fa-award',
                    'color' => $badge['color'] ?: '#27ae60',
                    'earned_date' => $earned_badge['earned_date'] ?? null
                ];
            } else {
                $badges_data['available'][] = [
                    'id' => $badge['id'],
                    'name' => $badge['name'],
                    'description' => $badge['description'],
                    'icon' => $badge['icon'] ?: 'fas fa-lock',
                    'color' => $badge['color'] ?: '#95a5a6',
                    'requirement' => $badge['requirement'] ?? 'شرایط کسب این نشان را بررسی کنید'
                ];
            }
        }
    } else {
        // Fallback data برای تست
        $badges_data = [
            'earned' => [
                [
                    'id' => 1,
                    'name' => 'آغازگر',
                    'description' => 'اولین ورود به سیستم',
                    'icon' => 'fas fa-play',
                    'color' => '#3498db',
                    'earned_date' => current_time('mysql')
                ],
                [
                    'id' => 2,
                    'name' => 'فعال',
                    'description' => '10 امتیاز کسب کرده‌اید',
                    'icon' => 'fas fa-star',
                    'color' => '#f1c40f',
                    'earned_date' => current_time('mysql')
                ]
            ],
            'available' => [
                [
                    'id' => 3,
                    'name' => 'ماهر',
                    'description' => 'کسب 50 امتیاز',
                    'icon' => 'fas fa-trophy',
                    'color' => '#e67e22',
                    'requirement' => '40 امتیاز دیگر نیاز دارید'
                ],
                [
                    'id' => 4,
                    'name' => 'استاد',
                    'description' => 'کسب 100 امتیاز',
                    'icon' => 'fas fa-crown',
                    'color' => '#9b59b6',
                    'requirement' => '90 امتیاز دیگر نیاز دارید'
                ]
            ]
        ];
    }

    // Cache for 5 minutes
    set_transient($cache_key, $badges_data, 300);
    
    return $badges_data;
}

    private function get_user_path_progress($user_id) {  
        // Integration with Path Engine  
        if (function_exists('psych_path_get_user_progress_percentage')) {  
            return psych_path_get_user_progress_percentage($user_id);  
        }  
        
        return 0;  
    }  

    private function get_user_path_data($user_id, $path_id = 0) {  
        // Integration with Path Engine  
        if (function_exists('psych_path_get_user_progress')) {  
            return psych_path_get_user_progress($user_id, $path_id);  
        }  
        
        // Fallback  
        return [  
            'completion_percentage' => 0,  
            'stations' => []  
        ];  
    }  

    private function get_leaderboard_data($limit, $user_id) {  
        global $wpdb;  
        
        // Get top users  
        $query = $wpdb->prepare("  
            SELECT u.ID, u.display_name,   
                   COALESCE(um.meta_value, 0) as points  
            FROM {$wpdb->users} u  
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id   
                AND um.meta_key = 'psych_total_points'  
            ORDER BY CAST(COALESCE(um.meta_value, 0) AS UNSIGNED) DESC  
            LIMIT %d  
        ", $limit);  
        
        $top_users = $wpdb->get_results($query, ARRAY_A);  
        
        // Add level info to each user  
        foreach ($top_users as &$user) {  
            $level_info = $this->get_user_level_info($user['ID']);  
            $user['level_name'] = $level_info['name'];  
            $user['points'] = intval($user['points']);  
        }  
        
        // Get current user rank  
        $user_rank_query = $wpdb->prepare("  
            SELECT COUNT(*) + 1 as rank  
            FROM {$wpdb->usermeta} um1  
            JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id   
                AND um2.meta_key = 'psych_total_points'  
            WHERE um1.meta_key = 'psych_total_points'  
                AND CAST(um1.meta_value AS UNSIGNED) >   
                    (SELECT CAST(COALESCE(meta_value, 0) AS UNSIGNED)   
                     FROM {$wpdb->usermeta}   
                     WHERE user_id = %d AND meta_key = 'psych_total_points')  
        ", $user_id);  
        
        $user_rank = $wpdb->get_var($user_rank_query) ?: 1;  
        
        // Get current user data  
        $current_user_data = $this->get_cached_user_data($user_id);  
        
        return [  
            'users' => $top_users,  
            'user_rank' => $user_rank,  
            'current_user' => [  
                'display_name' => $current_user_data['display_name'],  
                'level_name' => $current_user_data['level']['name'],  
                'points' => $current_user_data['points']  
            ]  
        ];  
    }  

    private function get_user_achievements($user_id, $limit) {  
        $achievements = [];  
        
        // Get recent badge achievements  
        $badge_log = get_option('psych_badge_log', []);  
        $user_badge_logs = array_filter($badge_log, function($log) use ($user_id) {  
            return $log['user_id'] == $user_id;  
        });  
        
        foreach ($user_badge_logs as $log) {  
            $badge_name = function_exists('psych_get_badge_name') ?   
                         psych_get_badge_name($log['badge_slug']) : 'نشان جدید';  
            
            $achievements[] = [  
                'title' => "کسب نشان: {$badge_name}",  
                'description' => "شما نشان {$badge_name} را کسب کردید.",  
                'date' => $log['timestamp'],  
                'type' => 'badge'  
            ];  
        }  
        
        // Get recent points achievements  
        $points_log = get_option('psych_points_log', []);  
        $user_points_logs = array_filter($points_log, function($log) use ($user_id) {  
            return $log['user_id'] == $user_id;  
        });  
        
        foreach ($user_points_logs as $log) {  
            $achievements[] = [  
                'title' => "کسب {$log['points']} امتیاز",  
                'description' => $log['reason'],  
                'date' => $log['timestamp'],  
                'type' => 'points'  
            ];  
        }  
        
        // Sort by date and limit  
        usort($achievements, function($a, $b) {  
            return strtotime($b['date']) - strtotime($a['date']);  
        });  
        
        return array_slice($achievements, 0, $limit);  
    }  

    private function get_rank_class($rank, $highlight_top3) {  
        if ($highlight_top3 !== 'true') return '';  
        
        switch ($rank) {  
            case 1: return 'gold';  
            case 2: return 'silver';  
            case 3: return 'bronze';  
            default: return '';  
        }  
    }  

    // =====================================================================  
    // AJAX HANDLERS  
    // =====================================================================  

    public function ajax_refresh_dashboard() {  
        if (!wp_verify_nonce($_POST['nonce'], 'psych_dashboard_nonce')) {  
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);  
        }  
        
        $context = $this->get_viewing_context();  
        $user_id = $context['viewed_user_id'];  
        
        // Clear cache and get fresh data  
        $this->clear_user_cache($user_id);  
        $user_data = $this->get_cached_user_data($user_id);  
        
        wp_send_json_success([  
            'points' => $user_data['points'],  
            'level_progress' => $user_data['level']['progress_percentage'],  
            'badges_count' => $user_data['badges_count'],  
                        'path_progress' => $user_data['path_progress']
        ]);
    }

    public function ajax_toggle_widget() {
        if (!wp_verify_nonce($_POST['nonce'], 'psych_dashboard_nonce')) {
            wp_send_json_error(['message' => 'نشست منقضی شده است.']);
        }
        
        $widget_id = sanitize_key($_POST['widget_id']);
        $collapsed = $_POST['collapsed'] === 'true';
        
        $context = $this->get_viewing_context();
        $user_id = $context['real_user_id'];
        
        // Save widget state
        $widget_states = get_user_meta($user_id, 'psych_dashboard_widget_states', true) ?: [];
        $widget_states[$widget_id] = $collapsed;
        update_user_meta($user_id, 'psych_dashboard_widget_states', $widget_states);
        
        wp_send_json_success(['message' => 'وضعیت ویجت ذخیره شد.']);
    }

    // =====================================================================
    // CACHE MANAGEMENT
    // =====================================================================

    public function clear_user_cache($user_id) {
        delete_transient("psych_dashboard_user_data_{$user_id}");
        delete_transient("psych_user_badges_data_{$user_id}");
        delete_transient("psych_user_path_data_{$user_id}");
        delete_transient("psych_user_achievements_{$user_id}");
    }

    // =====================================================================
    // ADMIN MENU AND SETTINGS
    // =====================================================================

    public function add_admin_menu() {
        add_submenu_page(
            'psych-gamification-center',
            'تنظیمات داشبورد',
            'داشبورد کاربری',
            'manage_options',
            'psych-dashboard-settings',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (isset($_POST['save_settings'])) {
            $this->save_admin_settings();
        }
        
        $settings = get_option('psych_dashboard_settings', $this->get_default_settings());
        ?>
        <div class="wrap">
            <h1>تنظیمات داشبورد کاربری</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('psych_dashboard_settings', 'psych_dashboard_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">فعال‌سازی کش</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_cache" value="1" 
                                       <?php checked($settings['enable_cache']); ?>>
                                استفاده از کش برای بهبود عملکرد
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">مدت زمان کش (ثانیه)</th>
                        <td>
                            <input type="number" name="cache_expiry" 
                                   value="<?php echo esc_attr($settings['cache_expiry']); ?>" 
                                   min="60" max="3600" class="regular-text">
                            <p class="description">مدت زمان نگهداری داده‌ها در کش (60 تا 3600 ثانیه)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">بازه به‌روزرسانی خودکار</th>
                        <td>
                            <select name="auto_refresh">
                                <option value="0" <?php selected($settings['auto_refresh'], 0); ?>>غیرفعال</option>
                                <option value="30000" <?php selected($settings['auto_refresh'], 30000); ?>>30 ثانیه</option>
                                <option value="60000" <?php selected($settings['auto_refresh'], 60000); ?>>1 دقیقه</option>
                                <option value="300000" <?php selected($settings['auto_refresh'], 300000); ?>>5 دقیقه</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">ویجت‌های پیش‌فرض</th>
                        <td>
                            <?php
                            $default_widgets = $settings['default_widgets'];
                            $available_widgets = [
                                'points' => 'امتیازات',
                                'level' => 'سطح',
                                'badges' => 'نشان‌ها',
                                'path' => 'مسیر یادگیری',
                                'leaderboard' => 'رتبه‌بندی',
                                'timeline' => 'دستاوردهای اخیر'
                            ];
                            
                            foreach ($available_widgets as $key => $label):
                            ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="default_widgets[]" value="<?php echo esc_attr($key); ?>"
                                           <?php checked(in_array($key, $default_widgets)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">تعداد ستون‌های پیش‌فرض</th>
                        <td>
                            <select name="default_columns">
                                <option value="auto" <?php selected($settings['default_columns'], 'auto'); ?>>خودکار</option>
                                <option value="1" <?php selected($settings['default_columns'], '1'); ?>>1 ستون</option>
                                <option value="2" <?php selected($settings['default_columns'], '2'); ?>>2 ستون</option>
                                <option value="3" <?php selected($settings['default_columns'], '3'); ?>>3 ستون</option>
                                <option value="4" <?php selected($settings['default_columns'], '4'); ?>>4 ستون</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2>آمار سیستم</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">آمار کاربران</th>
                        <td>
                            <?php
                            $stats = $this->get_system_stats();
                            ?>
                            <p><strong>کل کاربران:</strong> <?php echo number_format_i18n($stats['total_users']); ?></p>
                            <p><strong>کاربران فعال:</strong> <?php echo number_format_i18n($stats['active_users']); ?></p>
                            <p><strong>کل امتیازات اعطا شده:</strong> <?php echo number_format_i18n($stats['total_points']); ?></p>
                            <p><strong>کل نشان‌های کسب شده:</strong> <?php echo number_format_i18n($stats['total_badges']); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="save_settings" class="button-primary" value="ذخیره تنظیمات">
                    <input type="submit" name="clear_cache" class="button" value="پاک کردن کش">
                </p>
            </form>
        </div>
        <?php
    }

    private function save_admin_settings() {
        if (!wp_verify_nonce($_POST['psych_dashboard_nonce'], 'psych_dashboard_settings')) {
            wp_die('نشست منقضی شده است.');
        }
        
        if (isset($_POST['clear_cache'])) {
            $this->clear_all_cache();
            add_settings_error('psych_dashboard', 'cache_cleared', 'کش با موفقیت پاک شد.', 'updated');
            return;
        }
        
        $settings = [
            'enable_cache' => isset($_POST['enable_cache']),
            'cache_expiry' => intval($_POST['cache_expiry']),
            'auto_refresh' => intval($_POST['auto_refresh']),
            'default_widgets' => $_POST['default_widgets'] ?? [],
            'default_columns' => sanitize_text_field($_POST['default_columns'])
        ];
        
        // Validate settings
        if ($settings['cache_expiry'] < 60) $settings['cache_expiry'] = 60;
        if ($settings['cache_expiry'] > 3600) $settings['cache_expiry'] = 3600;
        
        update_option('psych_dashboard_settings', $settings);
        $this->cache_expiry = $settings['cache_expiry'];
        
        add_settings_error('psych_dashboard', 'settings_saved', 'تنظیمات با موفقیت ذخیره شد.', 'updated');
    }

    private function get_default_settings() {
        return [
            'enable_cache' => true,
            'cache_expiry' => 300,
            'auto_refresh' => 60000,
            'default_widgets' => ['points', 'level', 'badges', 'path'],
            'default_columns' => 'auto'
        ];
    }

    private function get_system_stats() {
        global $wpdb;
        
        $stats = [
            'total_users' => count_users()['total_users'],
            'active_users' => 0,
            'total_points' => 0,
            'total_badges' => 0
        ];
        
        // Get active users (users with points)
        $stats['active_users'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'psych_total_points' 
            AND CAST(meta_value AS UNSIGNED) > 0
        ");
        
        // Get total points awarded
        $stats['total_points'] = $wpdb->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED)) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'psych_total_points'
        ") ?: 0;
        
        // Get total badges earned
        $stats['total_badges'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'psych_user_badges'
        ") ?: 0;
        
        return $stats;
    }

    private function clear_all_cache() {
        global $wpdb;
        
        // Delete all dashboard-related transients
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_psych_dashboard_%' 
            OR option_name LIKE '_transient_timeout_psych_dashboard_%'
            OR option_name LIKE '_transient_psych_user_%'
            OR option_name LIKE '_transient_timeout_psych_user_%'
        ");
    }

    // =====================================================================
    // ADMIN JAVASCRIPT
    // =====================================================================

    public function print_admin_styles() {
        ?>
         <style>
        /* =================================================================== */
        /* ADMIN PANEL STYLES */
        /* =================================================================== */
        
        .psych-admin-wrap {
            background: #f1f1f1;
            margin: 20px 0 0 -20px;
            padding: 0;
        }

        .psych-admin-wrap h1 {
            background: #fff;
            padding: 20px;
            margin: 0 0 20px 0;
            border-bottom: 1px solid #ddd;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .psych-admin-wrap .nav-tab-wrapper {
            background: #fff;
            margin: 0;
            padding: 0 20px;
            border-bottom: 1px solid #ddd;
        }

        .psych-admin-wrap .tab-content {
            background: #fff;
            padding: 20px;
            margin: 0;
            min-height: 500px;
        }

        /* Dashboard Cards */
        .psych-dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .psych-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .psych-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .psych-card-icon {
            font-size: 40px;
            color: #3498db;
            flex-shrink: 0;
        }

        .psych-card-content h3 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
            line-height: 1;
        }

        .psych-card-content p {
            margin: 5px 0 0 0;
            color: #7f8c8d;
            font-size: 14px;
        }

        /* Stats Tables */
        .psych-stats-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        .psych-stats-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
        }

        .psych-stats-section h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        /* Form Styles */
        .psych-admin-wrap .form-table th {
            width: 200px;
            padding: 15px 0;
            font-weight: 600;
        }

        .psych-admin-wrap .form-table td {
            padding: 15px 0;
        }

        /* Manual Award Section */
        .psych-manual-award {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .psych-manual-award h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #e74c3c;
            padding-bottom: 10px;
        }

        .psych-award-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: end;
        }

        .psych-award-form .form-group {
            display: flex;
            flex-direction: column;
        }

        .psych-award-form label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }

        .psych-award-form select,
        .psych-award-form input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .psych-award-form button {
            background: #e74c3c;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .psych-award-form button:hover {
            background: #c0392b;
        }

        /* Recent Activities */
        .psych-recent-activities {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 25px;
        }

        .psych-recent-activities h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #27ae60;
            padding-bottom: 10px;
        }

        .psych-activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #ecf0f1;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .psych-activity-item:last-child {
            border-bottom: none;
        }

        .psych-activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3498db;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .psych-activity-content {
            flex: 1;
        }

        .psych-activity-title {
            font-weight: 600;
            color: #2c3e50;
            margin: 0 0 3px 0;
        }

        .psych-activity-meta {
            font-size: 13px;
            color: #7f8c8d;
            margin: 0;
        }

        /* Responsive Admin */
        @media (max-width: 1200px) {
            .psych-stats-container {
                grid-template-columns: 1fr;
            }
            
            .psych-award-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .psych-dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .psych-admin-wrap {
                margin-left: 0;
            }
            
            .psych-card {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Success/Error Messages */
        .psych-admin-notice {
            padding: 12px 20px;
            border-radius: 6px;
            margin: 15px 0;
            font-weight: 600;
        }

        .psych-admin-notice.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .psych-admin-notice.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .psych-admin-notice.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Loading States */
        .psych-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: #7f8c8d;
        }

        .psych-loading::before {
            content: '';
            width: 20px;
            height: 20px;
            border: 2px solid #ecf0f1;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: psych-spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes psych-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
        <?php
    }

    private function get_admin_js() {
        return '
        jQuery(document).ready(function($) {
            // Real-time stats updates
            function updateStats() {
                $.post(psych_dashboard_admin.ajax_url, {
                    action: "psych_dashboard_admin_stats",
                    nonce: psych_dashboard_admin.nonce
                }).done(function(response) {
                    if (response.success) {
                        // Update stats display
                        // This would update the admin dashboard stats
                    }
                });
            }
            
            // Update stats every 30 seconds
            setInterval(updateStats, 30000);
        });
        ';
    }

    // =====================================================================
    // INTEGRATION HOOKS
    // =====================================================================

    public function __clone() {
        // Prevent cloning
        wp_die('عملیات مجاز نیست.');
    }

    public function __wakeup() {
        // Prevent unserializing
        wp_die('عملیات مجاز نیست.');
    }
}

// =====================================================================
// INITIALIZATION AND HOOKS
// =====================================================================

// Initialize the enhanced dashboard
if (!wp_installing()) {
    add_action('plugins_loaded', function() {
        Psych_Dashboard_Display_Enhanced::get_instance();
    }, 10);
}

// Integration hooks for other modules
add_action('init', function() {
    do_action('psych_dashboard_loaded');
}, 20);

// =====================================================================
// UTILITY FUNCTIONS FOR EXTERNAL USE
// =====================================================================

if (!function_exists('psych_dashboard_get_user_data')) {
    /**
     * Get user dashboard data
     * @param int $user_id
     * @return array|null
     */
    function psych_dashboard_get_user_data($user_id) {
        $instance = Psych_Dashboard_Display_Enhanced::get_instance();
        return $instance->get_cached_user_data($user_id);
    }
}

if (!function_exists('psych_dashboard_clear_user_cache')) {
    /**
     * Clear user dashboard cache
     * @param int $user_id
     */
    function psych_dashboard_clear_user_cache($user_id) {
        $instance = Psych_Dashboard_Display_Enhanced::get_instance();
        $instance->clear_user_cache($user_id);
    }
}

if (!function_exists('psych_dashboard_render_widget')) {
    /**
     * Render a specific dashboard widget
     * @param string $widget_type
     * @param int $user_id
     * @param array $args
     * @return string
     */
    function psych_dashboard_render_widget($widget_type, $user_id = 0, $args = []) {
        $instance = Psych_Dashboard_Display_Enhanced::get_instance();
        
        if (!$user_id) {
            $context = $instance->get_viewing_context();
            $user_id = $context['viewed_user_id'];
        }
        
        $method_map = [
            'points' => 'render_points_display',
            'level' => 'render_level_display',
            'badges' => 'render_badges_collection',
            'path' => 'render_progress_path',
            'leaderboard' => 'render_leaderboard',
            'timeline' => 'render_achievement_timeline'
        ];
        
        if (isset($method_map[$widget_type])) {
            $args['user_id'] = $user_id;
            return $instance->{$method_map[$widget_type]}($args);
        }
        
        return '';
    }
}

// =====================================================================
// COMPATIBILITY WITH OLDER VERSIONS
// =====================================================================

if (!class_exists('Psych_Dashboard_Display')) {
    /**
     * Compatibility class for older integrations
     */
    class Psych_Dashboard_Display {
        public static function get_instance() {
            return Psych_Dashboard_Display_Enhanced::get_instance();
        }
        
        public function render_gamified_header($user_id = 0) {
            $instance = Psych_Dashboard_Display_Enhanced::get_instance();
            return $instance->render_gamified_header(['user_id' => $user_id]);
        }
        
        public function render_user_performance_header($user_id = 0) {
            $instance = Psych_Dashboard_Display_Enhanced::get_instance();
            return $instance->render_performance_header(['user_id' => $user_id]);
        }
    }
}

// =====================================================================
// ACTIVATION/DEACTIVATION HOOKS
// =====================================================================

register_activation_hook(__FILE__, function() {
    // Set default options
    if (!get_option('psych_dashboard_settings')) {
        $instance = Psych_Dashboard_Display_Enhanced::get_instance();
        update_option('psych_dashboard_settings', $instance->get_default_settings());
    }
    
    // Set version
    update_option('psych_dashboard_version', Psych_Dashboard_Display_Enhanced::VERSION);
});

register_deactivation_hook(__FILE__, function() {
    // Clear all cache on deactivation
    $instance = Psych_Dashboard_Display_Enhanced::get_instance();
    $instance->clear_all_cache();
});

// =====================================================================
// CRON JOBS FOR CACHE CLEANUP
// =====================================================================

if (!wp_next_scheduled('psych_dashboard_cleanup')) {
    wp_schedule_event(time(), 'daily', 'psych_dashboard_cleanup');
}

add_action('psych_dashboard_cleanup', function() {
    $instance = Psych_Dashboard_Display_Enhanced::get_instance();
    $instance->clear_all_cache();
});

// =====================================================================
// DEBUGGING (only in development)
// =====================================================================

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('psych_dashboard_loaded', function() {
        error_log('Psych Dashboard Display Enhanced Integration Edition loaded successfully');
    });
}

?>