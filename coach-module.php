<?php
/**
 * Coach Module for Psych Complete System
 *
 * This file manages all coach-related functionalities, including impersonation,
 * access control, student assignments, and frontend shortcodes, optimized for
 * sales growth, user engagement, and SEO.
 * Version: 7.1 (Growth Ecosystem Edition - Enhanced)
 *
 * @package Psych_Complete_System
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Ensure WP_List_Table class is available for the admin management page.
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Psych_Coach_Student_List_Table class.
 *
 * Renders the list table for student assignments, integrating gamification data
 * and assignment status for a comprehensive overview.
 */
class Psych_Coach_Student_List_Table extends WP_List_Table {

    private $product_id;
    private $coach_id;

    public function __construct($product_id, $coach_id) {
        $this->product_id = intval($product_id);
        $this->coach_id = intval($coach_id);

        parent::__construct([
            'singular' => 'دانشجو',
            'plural'   => 'دانشجویان',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        $columns = [
            'cb'            => '<input type="checkbox" />',
            'display_name'  => 'نام دانشجو',
            'mobile_phone'  => 'شماره موبایل (برای ورود)',
            'assignment_status' => 'وضعیت تخصیص',
            'registration_date' => 'تاریخ ثبت‌نام',
        ];
        
        // Conditionally add gamification column if the module exists.
        if (class_exists('Psych_Gamification_Center')) {
            $columns['gamification'] = 'امتیاز / سطح';
        }
        
        return $columns;
    }

    public function get_sortable_columns() {
        return [
            'display_name' => ['display_name', false],
            'registration_date' => ['registration_date', true]
        ];
    }

    protected function get_bulk_actions() {
        return [
            'assign'   => 'تخصیص به این مربی',
            'unassign' => 'حذف تخصیص از این مربی'
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="student_ids[]" value="%d" />', intval($item->ID));
    }

    public function column_display_name($item) {
        $actions = [
            'edit' => sprintf(
                '<a href="%s" target="_blank">ویرایش پروفایل</a>', 
                esc_url(get_edit_user_link($item->ID))
            )
        ];
        return sprintf(
            '<strong>%1$s</strong>%2$s', 
            esc_html($item->display_name), 
            $this->row_actions($actions)
        );
    }

    public function column_mobile_phone($item) {
        $phone = get_user_meta($item->ID, 'billing_phone', true);
        if (empty($phone)) {
            // Try alternative phone field names
            $phone = get_user_meta($item->ID, 'phone', true);
        }
        return $phone ? '<code>' . esc_html($phone) . '</code>' : '<em>ثبت نشده</em>';
    }

    public function column_assignment_status($item) {
        $meta_key = 'psych_assigned_coach_for_product_' . $this->product_id;
        $assigned_coach_id = get_user_meta($item->ID, $meta_key, true);

        if ($assigned_coach_id == $this->coach_id) {
            return '<span style="color:green; font-weight:bold;">✓ تخصیص یافته به این مربی</span>';
        } elseif (!empty($assigned_coach_id)) {
            $other_coach = get_userdata($assigned_coach_id);
            $coach_name = $other_coach ? $other_coach->display_name : 'مربی حذف شده';
            return '<span style="color:orange;">تخصیص به: ' . esc_html($coach_name) . '</span>';
        } else {
            return '<span style="color:#999;">تخصیص نیافته</span>';
        }
    }

    public function column_registration_date($item) {
        $user_registered = $item->user_registered;
        if ($user_registered) {
            return date_i18n('Y/m/d', strtotime($user_registered));
        }
        return 'نامشخص';
    }

    public function column_gamification($item) {
        // This column will only be called if it exists in get_columns.
        if (!class_exists('Psych_Gamification_Center')) {
            return 'ماژول فعال نیست';
        }

        try {
            $gamification_instance = Psych_Gamification_Center::get_instance();
            $level_info = $gamification_instance->get_user_level_info($item->ID);

            $points = isset($level_info['current_points']) ? intval($level_info['current_points']) : 0;
            $level_name = isset($level_info['name']) ? $level_info['name'] : 'نامشخص';
            $level_icon = isset($level_info['icon']) ? $level_info['icon'] : 'fa-question-circle';
            $level_color = isset($level_info['color']) ? $level_info['color'] : '#333';

            return sprintf(
                '<span style="font-weight:bold;">%s</span> امتیاز <br>
                <span style="color:%s;"><i class="fa %s"></i> %s</span>',
                number_format_i18n($points),
                esc_attr($level_color),
                esc_attr($level_icon),
                esc_html($level_name)
            );
        } catch (Exception $e) {
            return 'خطا در بارگذاری';
        }
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        global $wpdb;
        $per_page = 25;
        $current_page = $this->get_pagenum();

        // Get all user IDs who purchased the specific product.
        $customer_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.post_author FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
             WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed', 'wc-processing')
             AND oim.meta_key = '_product_id' AND oim.meta_value = %d AND p.post_author != 0",
            $this->product_id
        ));

        if (empty($customer_ids)) {
            $this->items = [];
            $this->set_pagination_args(['total_items' => 0, 'per_page' => $per_page]);
            return;
        }

        // Build the query arguments for WP_User_Query.
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'display_name';
        $order = isset($_REQUEST['order']) && $_REQUEST['order'] === 'desc' ? 'DESC' : 'ASC';

        $args = [
            'include' => $customer_ids,
            'number'  => $per_page,
            'paged'   => $current_page,
            'orderby' => $orderby,
            'order'   => $order,
        ];

        if (!empty($_REQUEST['s'])) {
            $search = sanitize_text_field($_REQUEST['s']);
            $args['search'] = '*' . esc_sql($search) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'billing_phone',
                    'value' => $search,
                    'compare' => 'LIKE'
                ],
                [
                    'key' => 'phone',
                    'value' => $search,
                    'compare' => 'LIKE'
                ]
            ];
        }

        $user_query = new WP_User_Query($args);
        $this->items = $user_query->get_results();
        $this->set_pagination_args(['total_items' => $user_query->get_total(), 'per_page' => $per_page]);
    }

    public function process_bulk_action() {
        if (!isset($_POST['student_ids']) || !is_array($_POST['student_ids'])) return;

        $action = $this->current_action();
        if (!$action || !check_admin_referer('bulk-' . $this->_args['plural'])) return;

        $student_ids = array_map('intval', $_POST['student_ids']);
        $meta_key = 'psych_assigned_coach_for_product_' . $this->product_id;
        $notice_key = '';

        foreach ($student_ids as $student_id) {
            // Validate student ID
            if (!get_userdata($student_id)) continue;

            if ('assign' === $action) {
                update_user_meta($student_id, $meta_key, $this->coach_id);
                // Log the assignment
                do_action('psych_coach_student_assigned', $student_id, $this->coach_id, $this->product_id);
                $notice_key = 'assign_success';
            }
            
            if ('unassign' === $action && get_user_meta($student_id, $meta_key, true) == $this->coach_id) {
                delete_user_meta($student_id, $meta_key);
                // Log the unassignment
                do_action('psych_coach_student_unassigned', $student_id, $this->coach_id, $this->product_id);
                $notice_key = 'unassign_success';
            }
        }
        
        if ($notice_key) {
            // Redirect with a query arg to show a persistent notice.
            $redirect_url = add_query_arg('psych_notice', $notice_key, wp_get_referer());
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    public function no_items() {
        if ($this->product_id > 0) {
            esc_html_e('هیچ دانشجویی این دوره را خریداری نکرده یا با جستجوی شما مطابقت ندارد.', 'psych-text-domain');
        } else {
            esc_html_e('برای شروع، لطفاً یک دوره و یک مربی را انتخاب و فیلتر کنید.', 'psych-text-domain');
        }
    }
}

/**
 * Main Coach Module Class.
 * Initializes all hooks and functionalities.
 */
final class Psych_Coach_Module {

    private static $instance = null;
    public $student_list_table;
    private $coach_roles;

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->coach_roles = apply_filters('psych_coach_roles', ['coach', 'adviser', 'administrator']);
        $this->setup_hooks();
    }

    private function setup_hooks() {
        // SECTION 1: CORE SYSTEMS & ACTIONS
        add_action('template_redirect', [$this, 'handle_impersonation'], 1);
        add_action('init', [$this, 'capture_coach_referral_cookie']);
        add_action('woocommerce_thankyou', [$this, 'assign_coach_on_purchase_from_cookie']);

        // SECTION 2: ADMIN & DASHBOARD
        add_action('admin_menu', [$this, 'add_coach_management_page']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('wp_ajax_psych_coach_get_students', [$this, 'ajax_get_students_list']);
        add_action('wp_ajax_psych_coach_bulk_assign', [$this, 'ajax_bulk_assign']);

        // SECTION 3: USER PROFILE & ACCESS CONTROL
        add_action('show_user_profile', [$this, 'add_coach_access_control_fields']);
        add_action('edit_user_profile', [$this, 'add_coach_access_control_fields']);
        add_action('personal_options_update', [$this, 'save_coach_access_control_fields']);
        add_action('edit_user_profile_update', [$this, 'save_coach_access_control_fields']);

        // SECTION 4: FRONTEND SHORTCODES & UI
        add_shortcode('coach_see_as_user', [$this, 'shortcode_coach_impersonate_form']);
        add_shortcode('coach_only_content', [$this, 'shortcode_coach_only_content']);
        add_shortcode('user_product_codes', [$this, 'shortcode_user_codes_list']);
        add_shortcode('coach_search_by_code', [$this, 'shortcode_coach_search_by_code']);
        add_shortcode('psych_user_dashboard', [$this, 'shortcode_user_dashboard']);
        add_shortcode('psych_coach_page', [$this, 'render_coach_page_shortcode']);

        // SECTION 5: INTEGRATIONS & DATA HANDLING
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('woocommerce_order_status_completed', [$this, 'generate_unique_code_on_purchase']);
        add_filter('gform_pre_render', [$this, 'dynamically_inject_user_id_to_gform']);
        add_filter('gform_entry_created_by', [$this, 'override_gform_entry_creator'], 10, 3);
        
        // SECTION 6: VIEWING CONTEXT (JS)
        add_action('wp_head', [$this, 'inject_viewing_context_js']);
        add_action('admin_head', [$this, 'inject_viewing_context_js']);

        // SECTION 7: SECURITY & PERFORMANCE
        add_action('wp_login', [$this, 'clear_impersonation_on_login'], 10, 2);
        add_action('wp_logout', [$this, 'clear_impersonation_on_logout']);
    }

    // =====================================================================
    // SECTION 1: CORE SYSTEMS & ACTIONS
    // =====================================================================
    
    public function get_viewing_context() {
        // Check if path engine is available
        if (function_exists('psych_path_get_viewing_context')) {
            return psych_path_get_viewing_context();
        }
        
        // Initialize session if not started
        if (!session_id()) {
            @session_start();
        }
        
        $real_user_id = isset($_SESSION['_seeas_real_user']) ? intval($_SESSION['_seeas_real_user']) : get_current_user_id();
        $viewed_user_id = get_current_user_id();
        
        return [
            'is_impersonating' => ($real_user_id != $viewed_user_id && $real_user_id > 0),
            'real_user_id'     => $real_user_id,
            'viewed_user_id'   => $viewed_user_id,
        ];
    }
    
    public function handle_impersonation() {
        $context = $this->get_viewing_context();
        $real_id = $context['real_user_id'];
        $current_user_obj = get_userdata($real_id);

        // Security check: Only allow coaches to impersonate
        if (!$current_user_obj || empty(array_intersect($this->coach_roles, (array)$current_user_obj->roles))) {
            return;
        }

        // Start Impersonation
        if (isset($_GET['seeas']) && !isset($_GET['stop_seeas']) && !$context['is_impersonating']) {
            $impersonate_id = intval($_GET['seeas']);
            
            // Validation checks
            if ($impersonate_id > 0 && $impersonate_id != $real_id) {
                $target_user = get_userdata($impersonate_id);
                if ($target_user && $this->can_coach_impersonate_user($real_id, $impersonate_id)) {
                    if (!session_id()) {
                        session_start();
                    }
                    $_SESSION['_seeas_real_user'] = $real_id;
                    wp_set_current_user($impersonate_id);
                    
                    // Log the impersonation
                    do_action('psych_coach_impersonation_started', $real_id, $impersonate_id);
                    
                    // Redirect to remove query args for a cleaner URL
                    wp_safe_redirect(remove_query_arg('seeas'));
                    exit;
                }
            }
        }

        // Stop Impersonation
        if ($context['is_impersonating'] && isset($_GET['stop_seeas'])) {
            if (!session_id()) {
                session_start();
            }
            
            // Log the end of impersonation
            do_action('psych_coach_impersonation_ended', $real_id, $context['viewed_user_id']);
            
            unset($_SESSION['_seeas_real_user']);
            wp_set_current_user($real_id);
            wp_safe_redirect(remove_query_arg('stop_seeas'));
            exit;
        }
    }

    /**
     * Check if a coach can impersonate a specific user
     */
    private function can_coach_impersonate_user($coach_id, $user_id) {
        // Admin bypass
        if (user_can($coach_id, 'manage_options')) {
            return true;
        }

        // Check if the user is assigned to this coach for any product
        global $wpdb;
        $meta_key_pattern = 'psych_assigned_coach_for_product_%';
        $assigned = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} 
             WHERE user_id = %d AND meta_key LIKE %s AND meta_value = %d",
            $user_id, $meta_key_pattern, $coach_id
        ));

        return $assigned > 0;
    }

    public function capture_coach_referral_cookie() {
        if (isset($_GET['coach_ref'])) {
            $coach_id = intval($_GET['coach_ref']);
            $coach_user = get_userdata($coach_id);
            
            if ($coach_user && !empty(array_intersect($this->coach_roles, (array)$coach_user->roles))) {
                // Set secure cookie
                $cookie_options = [
                    'expires' => time() + (86400 * 30), // 30 days
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ];
                
                if (PHP_VERSION_ID >= 70300) {
                    setcookie('psych_coach_ref_id', $coach_id, $cookie_options);
                } else {
                    setcookie('psych_coach_ref_id', $coach_id, $cookie_options['expires'], 
                             $cookie_options['path'], $cookie_options['domain'], 
                             $cookie_options['secure'], $cookie_options['httponly']);
                }
            }
        }
    }

    public function assign_coach_on_purchase_from_cookie($order_id) {
        if (!isset($_COOKIE['psych_coach_ref_id'])) return;

        $coach_id = intval($_COOKIE['psych_coach_ref_id']);
        $order = wc_get_order($order_id);
        
        if (!$order || !$order->get_user_id() || !$coach_id) return;

        $user_id = $order->get_user_id();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $meta_key = 'psych_assigned_coach_for_product_' . $product_id;
            
            // Assign only if no coach is already assigned for this product.
            if (!get_user_meta($user_id, $meta_key, true)) {
                update_user_meta($user_id, $meta_key, $coach_id);
                
                // Log the assignment
                do_action('psych_coach_auto_assigned', $user_id, $coach_id, $product_id, $order_id);
            }
        }
        
        // Clear the cookie after use
        setcookie('psych_coach_ref_id', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
    }

    public function clear_impersonation_on_login($user_login, $user) {
        if (!session_id()) {
            @session_start();
        }
        unset($_SESSION['_seeas_real_user']);
    }

    public function clear_impersonation_on_logout() {
        if (!session_id()) {
            @session_start();
        }
        unset($_SESSION['_seeas_real_user']);
    }

    // =====================================================================
    // SECTION 2: ADMIN & DASHBOARD
    // =====================================================================

    public function add_coach_management_page() {
        $hook = add_submenu_page(
            'psych-system', // Parent slug
            'مدیریت مربیان', // Page title
            'مدیریت مربیان', // Menu title
            'manage_options', // Capability
            'psych-coach-management', // Menu slug
            [$this, 'render_coach_management_page'] // Callback function
        );
        add_action("load-{$hook}", [$this, 'init_student_list_table']);
    }

    public function init_student_list_table() {
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        $coach_id = isset($_GET['coach_id']) ? intval($_GET['coach_id']) : 0;
        $this->student_list_table = new Psych_Coach_Student_List_Table($product_id, $coach_id);
    }
    
    public function show_admin_notices() {
        if (isset($_GET['psych_notice'])) {
            $notice_type = sanitize_key($_GET['psych_notice']);
            $messages = [
                'assign_success' => 'دانشجویان انتخاب شده با موفقیت تخصیص داده شدند.',
                'unassign_success' => 'تخصیص دانشجویان انتخاب شده با موفقیت حذف شد.',
                'error_occurred' => 'خطایی در پردازش درخواست رخ داد.'
            ];
            
            if (array_key_exists($notice_type, $messages)) {
                $notice_class = strpos($notice_type, 'error') !== false ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . 
                     esc_html($messages[$notice_type]) . '</p></div>';
            }
        }
    }

    public function ajax_get_students_list() {
        if (!current_user_can('manage_options') || !check_ajax_referer('coach_student_filter_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'خطای امنیتی.']);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $coach_id = isset($_POST['coach_id']) ? intval($_POST['coach_id']) : 0;

        if (!$product_id || !$coach_id) {
            wp_send_json_error(['message' => 'لطفا دوره و مربی را انتخاب کنید.']);
        }

        $this->student_list_table = new Psych_Coach_Student_List_Table($product_id, $coach_id);
        $this->student_list_table->prepare_items();

        ob_start();
        $this->student_list_table->display();
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    public function ajax_bulk_assign() {
        if (!current_user_can('manage_options') || !check_ajax_referer('coach_student_filter_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'خطای امنیتی.']);
        }

        $student_ids = isset($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : [];
        $action = isset($_POST['action2']) && $_POST['action2'] !== '-1' ? sanitize_key($_POST['action2']) : (isset($_POST['action']) && $_POST['action'] !== '-1' ? sanitize_key($_POST['action']) : '');
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $coach_id = isset($_POST['coach_id']) ? intval($_POST['coach_id']) : 0;

        if (empty($student_ids) || empty($action) || !$product_id || !$coach_id) {
            wp_send_json_error(['message' => 'اطلاعات ناقص است. لطفاً دانشجو و عملیات مورد نظر را انتخاب کنید.']);
        }

        $meta_key = 'psych_assigned_coach_for_product_' . $product_id;
        $success_count = 0;

        foreach ($student_ids as $student_id) {
            if (!get_userdata($student_id)) continue;

            if ('assign' === $action) {
                update_user_meta($student_id, $meta_key, $coach_id);
                do_action('psych_coach_student_assigned', $student_id, $coach_id, $product_id);
                $success_count++;
            }

            if ('unassign' === $action && get_user_meta($student_id, $meta_key, true) == $coach_id) {
                delete_user_meta($student_id, $meta_key);
                do_action('psych_coach_student_unassigned', $student_id, $coach_id, $product_id);
                $success_count++;
            }
        }

        if ($success_count > 0) {
            $message = sprintf('%d دانشجو با موفقیت پردازش شد.', $success_count);
            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => 'هیچ تغییری اعمال نشد. ممکن است دانشجویان قبلاً به مربی دیگری تخصیص داده شده باشند.']);
        }
    }

    public function render_coach_management_page() {
        // The list table object is already instantiated in init_student_list_table
        if ($this->student_list_table) {
            $this->student_list_table->process_bulk_action();
        }
        
        $products = wc_get_products([
            'limit' => -1, 
            'status' => 'publish', 
            'orderby' => 'title', 
            'order' => 'ASC'
        ]);
        
        $coaches = get_users([
            'orderby' => 'display_name'
        ]);
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-groups" style="color:#8E44AD;"></span> مرکز مدیریت مربیان و دانشجویان</h1>
            <div class="psych-admin-header">
                <p>در این مرکز می‌توانید دانشجویان خریدار هر دوره را به مربی مورد نظر تخصیص دهید.</p>
                <ol>
                    <li>یک <strong>دوره (محصول ووکامرس)</strong> را انتخاب کنید تا دانشجویان آن نمایش داده شوند.</li>
                    <li>یک <strong>مربی</strong> را جهت تخصیص انتخاب کنید.</li>
                    <li>روی دکمه <strong>"نمایش دانشجویان"</strong> کلیک کنید.</li>
                </ol>
            </div>
            
            <form id="coach-student-filter-form">
                <?php wp_nonce_field('coach_student_filter_nonce', 'coach_student_filter_nonce'); ?>
                <div class="psych-filters">
                    <select name="product_id" id="psych-product-id" required>
                        <option value="">-- انتخاب دوره/تست --</option>
                        <?php foreach ($products as $product) : ?>
                            <option value="<?php echo esc_attr($product->get_id()); ?>">
                                <?php echo esc_html($product->get_name()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="coach_id" id="psych-coach-id" required>
                        <option value="">-- انتخاب مربی --</option>
                        <?php foreach ($coaches as $coach) : ?>
                            <option value="<?php echo esc_attr($coach->ID); ?>">
                                <?php echo esc_html($coach->display_name . ' (' . $coach->user_email . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" id="filter-students-btn" class="button button-primary">نمایش دانشجویان</button>
                </div>
            </form>
            
            <div id="student-list-container">
                <!-- Student list table will be loaded here via AJAX -->
                <p class="initial-message">برای شروع، یک دوره و یک مربی را انتخاب کرده و روی دکمه "نمایش دانشجویان" کلیک کنید.</p>
            </div>
        </div>
        <?php
    }

    // =====================================================================
    // SECTION 3: USER PROFILE & ACCESS CONTROL
    // =====================================================================
    
    public function add_coach_access_control_fields($user) {
        if (!current_user_can('edit_users') || empty(array_intersect($this->coach_roles, (array)$user->roles))) {
            return;
        }

        wp_nonce_field('psych_save_coach_access', 'psych_coach_access_nonce');
        $allowed_pages = get_user_meta($user->ID, 'psych_coach_allowed_pages', true) ?: [];
        ?>
        <hr>
        <h3><span class="dashicons dashicons-lock" style="vertical-align: middle;"></span> مدیریت حوزه دسترسی مربی</h3>
        <p>صفحاتی را انتخاب کنید که این مربی مجاز است در آنها فعالیت (مانند مشاهده به جای کاربر) انجام دهد.</p>
        <table class="form-table">
            <tr>
                <th><label for="psych_coach_allowed_pages">صفحات مجاز</label></th>
                <td>
                    <select multiple="multiple" name="psych_coach_allowed_pages[]" id="psych_coach_allowed_pages" 
                            style="width:100%; height: 250px;">
                        <?php foreach (get_pages() as $page) : ?>
                            <option value="<?php echo intval($page->ID); ?>" 
                                    <?php selected(in_array($page->ID, (array)$allowed_pages)); ?>>
                                <?php echo esc_html($page->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        این تنظیم مشخص می‌کند مربی در کدام صفحات می‌تواند از شورت‌کد `[coach_see_as_user]` استفاده کند.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_coach_access_control_fields($user_id) {
        if (!current_user_can('edit_user', $user_id) || 
            !isset($_POST['psych_coach_access_nonce']) || 
            !wp_verify_nonce($_POST['psych_coach_access_nonce'], 'psych_save_coach_access')) {
            return;
        }
        
        $allowed_pages = isset($_POST['psych_coach_allowed_pages']) ? 
                        array_map('intval', $_POST['psych_coach_allowed_pages']) : [];
        update_user_meta($user_id, 'psych_coach_allowed_pages', $allowed_pages);
    }
    
    private function is_coach_allowed_on_page($coach_id, $page_id) {
        if (!$coach_id || !$page_id) return false;
        if (user_can($coach_id, 'manage_options')) return true; // Admins can bypass.
        
        $allowed_pages = get_user_meta($coach_id, 'psych_coach_allowed_pages', true);
        return is_array($allowed_pages) && in_array($page_id, $allowed_pages);
    }

    // =====================================================================
    // SECTION 4: FRONTEND SHORTCODES & UI
    // =====================================================================

    public function shortcode_coach_impersonate_form($atts, $content = null) {
        $context = $this->get_viewing_context();
        if ($context['is_impersonating'] || !is_user_logged_in()) {
            return '';
        }

        $coach_id = $context['real_user_id'];
        $current_page_id = get_queried_object_id();

        if (!$this->is_coach_allowed_on_page($coach_id, $current_page_id)) {
            return '<!-- Coach access to this page is restricted. -->';
        }

        $product_id = is_singular('product') ? $current_page_id : 
                     get_post_meta($current_page_id, '_psych_related_product', true);
        
        if (empty($product_id)) {
            return '<!-- No product is associated with this page for student lookup. -->';
        }

        $assigned_students = get_users([
            'meta_key' => 'psych_assigned_coach_for_product_' . $product_id,
            'meta_value' => $coach_id,
            'orderby' => 'display_name'
        ]);

        ob_start(); ?>
        <div class="psych-form-container coach-impersonate-form">
            <h4><span class="dashicons dashicons-groups"></span> مشاهده به جای دانشجو</h4>
            <?php if (!empty($assigned_students)) : ?>
            <form method="get" action="<?php echo esc_url(get_permalink($current_page_id)); ?>">
                <p>یکی از دانشجویان خود را برای این دوره انتخاب کنید:</p>
                <select name="seeas" required>
                    <option value="">-- انتخاب دانشجو --</option>
                    <?php foreach ($assigned_students as $student) : ?>
                        <option value="<?php echo esc_attr($student->ID); ?>">
                            <?php echo esc_html($student->display_name . ' (' . $student->user_email . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="ورود به حساب کاربر">
            </form>
            <?php else : ?>
                <p><em>در حال حاضر دانشجویی برای این دوره به شما اختصاص داده نشده است.</em></p>
            <?php endif; ?>
        </div>
        <?php 
        return ob_get_clean();
    }

    public function shortcode_coach_only_content($atts, $content = null) {
        $context = $this->get_viewing_context();
        if (!$context['is_impersonating']) {
            return '';
        }
        
        $user_data = get_userdata($context['viewed_user_id']);
        if (!$user_data) {
            return ''; // Safety check
        }

        $stop_link = esc_url(add_query_arg('stop_seeas', '1', get_permalink(get_queried_object_id())));
        $notice = sprintf(
            '<div class="coach-impersonation-notice">شما در حال مشاهده به جای <strong>%s</strong> هستید. <a href="%s">بازگشت به حساب کاربری خود</a></div>',
            esc_html($user_data->display_name),
            $stop_link
        );
        
        return $notice . '<div class="coach-only-content-wrapper">' . do_shortcode($content) . '</div>';
    }

    public function shortcode_user_codes_list($atts, $content = null) {
        if (!is_user_logged_in()) {
            return 'برای مشاهده این بخش باید وارد شوید.';
        }
        
        $codes = get_user_meta(get_current_user_id(), 'psych_user_product_codes', true);
        if (empty($codes) || !is_array($codes)) {
            return "<p>شما هنوز کد یکتایی برای هیچ محصولی دریافت نکرده‌اید.</p>";
        }
        
        ob_start(); ?>
        <div class="user-codes-table-wrapper">
            <h4>کدهای یکتای شما</h4>
            <table class="user-codes-table">
                <thead>
                    <tr>
                        <th>نام محصول/تست</th>
                        <th>کد یکتای شما</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($codes as $product_id => $code) : 
                    $product_title = get_the_title($product_id);
                    if (empty($product_title)) continue;
                ?>
                <tr>
                    <td><?php echo esc_html($product_title); ?></td>
                    <td><code id="user-code-<?php echo esc_attr($product_id); ?>"><?php echo esc_html($code); ?></code></td>
                    <td><button class="copy-code-btn" data-target="#user-code-<?php echo esc_attr($product_id); ?>">کپی</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php 
        return ob_get_clean();
    }
    
    public function shortcode_coach_search_by_code($atts, $content = null) {
        $context = $this->get_viewing_context();
        if ($context['is_impersonating']) {
            return '';
        }
        
        $error = '';
        if (isset($_POST['product_code_search_nonce']) && 
            wp_verify_nonce($_POST['product_code_search_nonce'], 'coach_search_by_code')) {
            
            if (isset($_POST['product_code']) && !empty($_POST['product_code'])) {
                $code = sanitize_text_field($_POST['product_code']);
                $user_id = $this->find_user_by_product_code($code);
                
                if ($user_id && $this->can_coach_impersonate_user($context['real_user_id'], $user_id)) {
                    wp_safe_redirect(add_query_arg('seeas', $user_id));
                    exit;
                } else {
                    $error = '<p class="psych-form-error">کد وارد شده نامعتبر است یا شما مجاز به مشاهده این کاربر نیستید.</p>';
                }
            }
        }
        
        ob_start(); ?>
        <div class="psych-form-container coach-search-form">
             <h4><span class="dashicons dashicons-search"></span> جستجوی کاربر با کد یکتا</h4>
             <form method="post">
                <?php wp_nonce_field('coach_search_by_code', 'product_code_search_nonce'); ?>
                <label for="product_code">کد یکتای کاربر را وارد کنید:</label>
                <input type="text" name="product_code" id="product_code" class="ltr-input" required>
                <input type="submit" value="جستجو و مشاهده">
                <?php echo $error; ?>
             </form>
        </div>
        <?php 
        return ob_get_clean();
    }
    
    public function shortcode_user_dashboard($atts, $content = null) {
        $context = $this->get_viewing_context();
        $user_id = $context['viewed_user_id'];

        if (!$user_id) {
            return "لطفا برای مشاهده داشبورد، وارد حساب کاربری خود شوید.";
        }

        $user_data = get_userdata($user_id);
        if (!$user_data) {
            return "خطا در بارگذاری اطلاعات کاربر.";
        }

        ob_start(); ?>
        <div class="psych-user-dashboard">
            <h2>داشبورد شما، <?php echo esc_html($user_data->first_name ?: $user_data->display_name); ?></h2>
            <div class="dashboard-grid">
                <div class="dashboard-widget-ai-coach">
                    <?php echo $this->render_ai_coach_recommendation($user_id); ?>
                </div>
                <div class="dashboard-widget-notebook">
                    <?php echo $this->render_notebook_section($user_id); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_coach_page_shortcode($atts, $content = null) {
        $context = $this->get_viewing_context();
        if ($context['is_impersonating'] || !is_user_logged_in()) {
            return '<p>برای مشاهده این صفحه باید به عنوان مربی وارد شوید.</p>';
        }

        $coach_id = get_current_user_id();
        $user_obj = get_userdata($coach_id);
        if (!$user_obj || empty(array_intersect($this->coach_roles, (array)$user_obj->roles))) {
            return '<!-- Content only visible to coaches -->';
        }

        $atts = shortcode_atts([
            'product_id' => 0,
        ], $atts, 'psych_coach_page');

        $product_id = intval($atts['product_id']);

        global $wpdb;
        $meta_key_pattern = 'psych_assigned_coach_for_product_%';
        if ($product_id > 0) {
            $meta_key_pattern = 'psych_assigned_coach_for_product_' . $product_id;
        }

        $assigned_students_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND meta_value = %d",
            $meta_key_pattern, $coach_id
        ));

        if (empty($assigned_students_meta)) {
            return '<p>در حال حاضر هیچ دانشجویی به شما تخصیص داده نشده است.</p>';
        }

        $students = [];
        foreach ($assigned_students_meta as $meta) {
            $user_info = get_userdata($meta->user_id);
            if ($user_info) {
                $product_id_from_key = (int) str_replace('psych_assigned_coach_for_product_', '', $meta->meta_key);
                $students[] = [
                    'user' => $user_info,
                    'product_id' => $product_id_from_key,
                    'product_name' => get_the_title($product_id_from_key) ?: 'نامشخص'
                ];
            }
        }

        usort($students, function($a, $b) {
            return strcmp($a['user']->display_name, $b['user']->display_name);
        });

        ob_start();
        ?>
        <div class="psych-coach-student-list">
            <h3>لیست دانشجویان شما</h3>
            <table class="coach-students-table">
                <thead>
                    <tr>
                        <th>نام دانشجو</th>
                        <th>دوره/محصول</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student_data) : ?>
                        <tr>
                            <td><?php echo esc_html($student_data['user']->display_name); ?></td>
                            <td><?php echo esc_html($student_data['product_name']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('seeas', $student_data['user']->ID)); ?>" class="button-impersonate">
                                    مشاهده به جای کاربر
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // =====================================================================
    // SECTION 5: INTEGRATIONS & DATA HANDLING
    // =====================================================================

    public function generate_unique_code_on_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_user_id()) return;
        
        $user_id = $order->get_user_id();
        $codes = get_user_meta($user_id, 'psych_user_product_codes', true) ?: [];
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!isset($codes[$product_id])) { // Generate only if it doesn't exist.
                $codes[$product_id] = 'P' . $product_id . 'U' . $user_id . '-' . 
                                     strtoupper(wp_generate_password(8, false));
            }
        }
        
        update_user_meta($user_id, 'psych_user_product_codes', $codes);
    }
    
    public function find_user_by_product_code($code) {
        global $wpdb;
        
        // Sanitize the input
        $code = sanitize_text_field($code);
        if (empty($code)) return false;
        
        $meta_key = 'psych_user_product_codes';
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
             WHERE meta_key = %s AND meta_value LIKE %s",
            $meta_key,
            '%"' . $wpdb->esc_like($code) . '"%'
        ));
        
        return $user_id ? (int) $user_id : false;
    }
    
    public function render_ai_coach_recommendation($user_id) {
        $advice = "برای دریافت توصیه شخصی، لطفا یک آزمون را تکمیل کنید.";
        
        // Try to get more personalized advice if unified report card is available
        if (class_exists('Psych_Unified_Report_Card')) {
            try {
                $report_instance = Psych_Unified_Report_Card::get_instance();
                if (method_exists($report_instance, 'get_last_completed_test')) {
                    $last_test = $report_instance->get_last_completed_test($user_id);
                    if ($last_test) {
                        $advice = "بر اساس آخرین آزمون شما ({$last_test['title']})، توصیه می‌کنیم به بهبود نقاط ضعف شناسایی شده توجه کنید.";
                    }
                }
            } catch (Exception $e) {
                // Fallback to default advice
            }
        }
        
        return "<div class='psych-ai-coach'>👩‍💻 <strong>توصیه هوشمند:</strong> <span>" . esc_html($advice) . "</span></div>";
    }

    public function render_notebook_section($user_id) {
        $current_user_id = get_current_user_id();
        
        // Process form submission
        if (isset($_POST['psych_user_note_nonce']) && 
            wp_verify_nonce($_POST['psych_user_note_nonce'], 'psych_save_user_note') &&
            $current_user_id == $user_id) {
            
            if (isset($_POST['user_note'])) {
                update_user_meta($user_id, 'psych_user_notebook', sanitize_textarea_field($_POST['user_note']));
                echo '<div class="notice notice-success"><p>یادداشت شما ذخیره شد.</p></div>';
            }
        }
        
        $note = get_user_meta($user_id, 'psych_user_notebook', true);
        $readonly = ($current_user_id != $user_id) ? 'readonly' : '';
        
        $form = '<form method="post" class="user-notebook-form">
                    ' . wp_nonce_field('psych_save_user_note', 'psych_user_note_nonce', true, false) . '
                    <textarea name="user_note" placeholder="افکار و نکات خود را اینجا یادداشت کنید..." ' . $readonly . '>' . 
                    esc_textarea($note) . '</textarea>';
        
        if ($current_user_id == $user_id) {
            $form .= '<button type="submit">ذخیره یادداشت</button>';
        }
        
        $form .= '</form>';
        
        return "<h3>📝 دفترچه یادداشت شخصی</h3>" . $form;
    }

    public function dynamically_inject_user_id_to_gform($form) {
        if (!class_exists('GFForms')) return $form;
        
        $context = $this->get_viewing_context();
        if (!$context['is_impersonating']) return $form;
        
        foreach ($form['fields'] as &$field) {
            if (isset($field->cssClass) && strpos($field->cssClass, 'gf_selected_user_id') !== false) {
                $field->defaultValue = $context['viewed_user_id'];
            }
        }
        
        return $form;
    }

    public function override_gform_entry_creator($created_by, $form, $entry) {
        if (!class_exists('GFForms')) return $created_by;
        
        $context = $this->get_viewing_context();
        if (!$context['is_impersonating']) return $created_by;

        foreach ($form['fields'] as $field) {
            if (isset($field->cssClass) && strpos($field->cssClass, 'gf_selected_user_id') !== false) {
                $viewed_user_id_from_form = rgar($entry, (string) $field->id);
                
                // Ensure the creator is only overridden if the form field matches the impersonated user.
                if (!empty($viewed_user_id_from_form) && (int) $viewed_user_id_from_form === $context['viewed_user_id']) {
                    return $context['viewed_user_id'];
                }
                break;
            }
        }
        
        return $created_by;
    }

    // =====================================================================
    // SECTION 6: ASSETS & VIEWING CONTEXT
    // =====================================================================

    public function inject_viewing_context_js() {
        $context = $this->get_viewing_context();
        echo "<script id='psych-viewing-context'>window.PSYCH_VIEWING_CONTEXT = " . 
             wp_json_encode($context) . ";</script>";
    }

    public function enqueue_frontend_assets() {
        $css = "
        .coach-impersonation-notice{background:#8E44AD;color:white;padding:12px 20px;text-align:center;border-bottom:3px solid #F1C40F;margin-bottom:20px;font-weight:bold;}
        .coach-impersonation-notice a{color:#F1C40F;text-decoration:underline;}
        .psych-form-container{background:#fdfdfd;border:1px solid #e1e1e1;padding:25px;border-radius:8px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.05);}
        .psych-form-container h4{margin-top:0;font-size:18px;border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:15px;}
        .psych-form-container h4 .dashicons{vertical-align:middle;margin-left:8px;}
        .psych-form-container select, .psych-form-container input[type='text']{width:100%;padding:10px;border-radius:4px;border:1px solid #ccc;}
        .psych-form-container input[type='submit']{background:#3498DB;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;margin-top:10px;font-weight:bold;}
        .psych-form-container input[type='submit']:hover{background:#2980B9;}
        .psych-form-error{color:red;font-size:0.9em;margin-top:5px;}
        .user-codes-table-wrapper{border:1px solid #eee;padding:20px;border-radius:8px;background:#fafafa;}
        .user-codes-table{width:100%;border-collapse:collapse;margin-top:10px;}
        .user-codes-table th, .user-codes-table td{text-align:right;padding:12px;border-bottom:1px solid #eee;}
        .user-codes-table th{background:#f5f5f5;font-weight:bold;}
        .copy-code-btn{background-color:#27AE60;color:white;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;transition:background-color 0.2s;}
        .copy-code-btn:hover{background-color:#229954;}
        .copy-code-btn.copied{background-color:#2ECC71;}
        .ltr-input{direction:ltr;text-align:left;}
        .psych-user-dashboard h2{border-bottom:2px solid #8E44AD;padding-bottom:10px;}
        .dashboard-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;}
        @media (max-width: 768px) {
            .dashboard-grid{grid-template-columns:1fr;}
        }
        .psych-ai-coach, .user-notebook-form{padding:20px;background:#f9f9f9;border-radius:8px;border:1px solid #e1e1e1;}
        .user-notebook-form textarea{width:100%;min-height:120px;margin-bottom:10px;padding:10px;border:1px solid #ccc;border-radius:4px;}
        .user-notebook-form button{background:#8E44AD;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;}
        .user-notebook-form button:hover{background:#7D3C98;}
        .notice{margin:10px 0;padding:10px;border-radius:4px;}
        .notice-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
        .notice-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
        ";
        
        wp_register_style('psych-coach-style', false);
        wp_enqueue_style('psych-coach-style');
        wp_add_inline_style('psych-coach-style', $css);

        $js = "
        document.addEventListener('DOMContentLoaded', function() {
            // Copy code functionality
            document.body.addEventListener('click', function(e) {
                if (e.target.matches('.copy-code-btn')) {
                    e.preventDefault();
                    const button = e.target;
                    const target = document.querySelector(button.dataset.target);
                    
                    if (target && navigator.clipboard) {
                        const originalText = button.textContent;
                        navigator.clipboard.writeText(target.innerText.trim()).then(function() {
                            button.textContent = 'کپی شد!';
                            button.classList.add('copied');
                            setTimeout(function() {
                                button.textContent = originalText;
                                button.classList.remove('copied');
                            }, 2000);
                        }).catch(function() {
                            button.textContent = 'خطا';
                            setTimeout(function() {
                                button.textContent = originalText;
                            }, 2000);
                        });
                    }
                }
            });
            
            // Auto-hide notices
            setTimeout(function() {
                const notices = document.querySelectorAll('.notice.is-dismissible');
                notices.forEach(function(notice) {
                    notice.style.opacity = '0';
                    setTimeout(function() {
                        notice.remove();
                    }, 300);
                });
            }, 5000);
        });
        ";
        
        wp_register_script('psych-coach-script', false);
        wp_enqueue_script('psych-coach-script');
        wp_add_inline_script('psych-coach-script', $js);
    }
    
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_psych-coach-management') return;
        
        wp_enqueue_style('font-awesome-psych', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
        
        $css = "
            .psych-admin-header { 
                background: #F8F9F9; 
                border: 1px solid #CCD1D1; 
                padding: 20px; 
                margin-top: 15px; 
                border-right: 4px solid #8E44AD; 
                border-radius: 4px; 
            }
            .psych-filters { 
                padding: 20px; 
                background: #fff; 
                border: 1px solid #ddd; 
                margin-bottom: 20px; 
                display: flex; 
                gap: 15px; 
                align-items: center; 
                flex-wrap: wrap;
            }
            .psych-filters select { 
                min-width: 250px; 
                padding: 8px;
            }
            .wp-list-table .column-gamification .fa { 
                margin-left: 5px; 
            }
            .wp-list-table .column-registration_date {
                width: 120px;
            }
            @media (max-width: 782px) {
                .psych-filters {
                    flex-direction: column;
                    align-items: stretch;
                }
                .psych-filters select {
                    min-width: auto;
                    width: 100%;
                }
            }
            #student-list-container .initial-message {
                padding: 20px;
                background: #f0f0f0;
                border: 1px solid #ddd;
                text-align: center;
            }
            #student-list-container .spinner {
                visibility: visible !important;
                float: none;
                margin: 20px auto;
                display: block;
            }
        ";
        
        wp_add_inline_style('wp-admin', $css);

        $js = "
        jQuery(document).ready(function($) {
            // Handle the main filter form submission
            $('#coach-student-filter-form').on('submit', function(e) {
                e.preventDefault();

                const container = $('#student-list-container');
                const button = $('#filter-students-btn');
                const originalButtonText = button.html();

                container.html('<div class=\"spinner is-active\"></div>');
                button.html('در حال بارگذاری...').prop('disabled', true);

                const data = {
                    action: 'psych_coach_get_students',
                    nonce: $('#coach_student_filter_nonce').val(),
                    product_id: $('#psych-product-id').val(),
                    coach_id: $('#psych-coach-id').val()
                };

                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        container.html(response.data.html);
                    } else {
                        container.html('<div class=\"notice notice-error is-dismissible\"><p>' + response.data.message + '</p></div>');
                    }
                }).fail(function() {
                    container.html('<div class=\"notice notice-error is-dismissible\"><p>خطای ناشناخته در ارتباط با سرور.</p></div>');
                }).always(function() {
                    button.html(originalButtonText).prop('disabled', false);
                });
            });

            // Handle the bulk actions form submission using event delegation
            $('#student-list-container').on('submit', 'form', function(e) {
                e.preventDefault();

                const form = $(this);
                const container = $('#student-list-container');
                const filterButton = $('#filter-students-btn');

                // Add a spinner to the bulk action button
                form.find('.button.action').after('<span class=\"spinner is-active\" style=\"float:none; vertical-align: middle;\"></span>').prop('disabled', true);

                let formData = form.serialize();
                // Add product_id and coach_id from the main filter form
                formData += '&product_id=' + $('#psych-product-id').val();
                formData += '&coach_id=' + $('#psych-coach-id').val();
                formData += '&action=psych_coach_bulk_assign';
                formData += '&nonce=' + $('#coach_student_filter_nonce').val();

                $.post(ajaxurl, formData, function(response) {
                    // Remove the old notice if it exists
                    $('.psych-bulk-notice').remove();

                    let noticeClass = response.success ? 'notice-success' : 'notice-error';
                    let noticeMessage = response.success ? response.data.message : response.data.message;

                    // Display a new notice
                    $('#coach-student-filter-form').after('<div class=\"notice ' + noticeClass + ' is-dismissible psych-bulk-notice\"><p>' + noticeMessage + '</p></div>');

                    // Refresh the list
                    filterButton.trigger('submit');

                }).fail(function() {
                    $('.psych-bulk-notice').remove();
                    $('#coach-student-filter-form').after('<div class=\"notice notice-error is-dismissible psych-bulk-notice\"><p>خطای ناشناخته در ارتباط با سرور.</p></div>');
                });
            });
        });
        ";
        wp_add_inline_script('jquery-core', $js);
    }
}

// Initialize the masterpiece.
Psych_Coach_Module::get_instance();
