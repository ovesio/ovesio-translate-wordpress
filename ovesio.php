<?php

/**
 * Plugin Name: Ovesio
 * Description: Get instant translations & AI content/SEO generation in over 30 languages, powered by the most advanced artificial intelligence technologies.
 * Version: 2.0.0
 * Author: Ovesio
 * Text Domain: ovesio
 * Author URI: https://ovesio.com
 * Tags: Ovesio, AI Translation, multilingual, translation, content generator, SEO generator, woocommerce product translations, automated translations
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OVESIO_PLUGIN_VERSION', '2.0.0');
define('OVESIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OVESIO_ADMIN_DIR', OVESIO_PLUGIN_DIR . 'admin/');

// Composer files
require_once __DIR__ . '/vendor/autoload.php';

// Helper functions
require_once OVESIO_PLUGIN_DIR . 'functions.php';
require_once OVESIO_PLUGIN_DIR . 'callback.php';

// Action Buttons
require_once OVESIO_ADMIN_DIR . 'buttons.php';

// Views
require_once OVESIO_ADMIN_DIR . 'views/settings-page-header.php';
require_once OVESIO_ADMIN_DIR . 'views/settings-api-tab.php';
require_once OVESIO_ADMIN_DIR . 'views/settings-translation-tab.php';
require_once OVESIO_ADMIN_DIR . 'views/settings-generate-content.php';
require_once OVESIO_ADMIN_DIR . 'views/settings-generate-seo.php';
require_once OVESIO_ADMIN_DIR . 'views/requests-list-tab.php';


add_action('admin_notices', function() {
    if ($message = get_transient('ovesio_error')) {
        echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
        delete_transient('ovesio_error');
    }

    if ($message = get_transient('ovesio_success')) {
        echo '<div class="notice notice-success"><p>' . wp_kses_post($message) . '</p></div>';
        delete_transient('ovesio_success');
    }
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ovesio_plugin_action_links');
function ovesio_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=ovesio') . '">' . __('Settings', 'ovesio') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

register_activation_hook(__FILE__, 'ovesio_create_table');
function ovesio_create_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT(11) NOT NULL AUTO_INCREMENT,
        resource VARCHAR(50) NOT NULL,
        resource_id INT(11) NOT NULL,
        content_id INT(11) DEFAULT NULL,
        lang VARCHAR(50) DEFAULT NULL,
        generate_description_id INT(11) DEFAULT NULL,
        generate_description_hash VARCHAR(50) DEFAULT NULL,
        generate_description_date DATETIME DEFAULT NULL,
        generate_description_status INT(11) DEFAULT '0',
        metatags_id INT(11) DEFAULT NULL,
        metatags_hash VARCHAR(50) DEFAULT NULL,
        metatags_date DATETIME DEFAULT NULL,
        metatags_status INT(11) DEFAULT '0',
        translate_id INT(11) DEFAULT NULL,
        translate_hash VARCHAR(50) DEFAULT NULL,
        translate_date DATETIME DEFAULT NULL,
        translate_status INT(11) DEFAULT '0',
        link VARCHAR(250) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        INDEX resource (resource),
        INDEX resource_id (resource_id),
        INDEX content_id (content_id),
        INDEX lang (lang),
        INDEX generate_description_id (generate_description_id),
        INDEX generate_description_hash (generate_description_hash),
        INDEX generate_description_date (generate_description_date),
        INDEX generate_description_status (generate_description_status),
        INDEX metatags_id (metatags_id),
        INDEX metatags_hash (metatags_hash),
        INDEX metatags_date (metatags_date),
        INDEX metatags_status (metatags_status),
        INDEX translate_id (translate_id),
        INDEX translate_hash (translate_hash),
        INDEX translate_date (translate_date),
        INDEX translate_status (translate_status),
        INDEX created_at (created_at)
    ) $charset_collate;";

    dbDelta($sql);

    // Initialize default options
    $options = get_option('ovesio_options', []);
    if (!is_array($options)) {
        $options = [];
    }
    if (!array_key_exists('auto_refresh_pending', $options)) {
        $options['auto_refresh_pending'] = 1;
        update_option('ovesio_options', $options);
    }

    // Initialize generate content defaults
    if (!get_option('ovesio_generate_content_settings')) {
        update_option('ovesio_generate_content_settings', [
            'status'              => 0,
            'for_posts'           => 0,
            'for_pages'           => 0,
            'for_products'        => 0,
            'for_categories'      => 0,
            'min_length'          => 500,
            'min_length_category' => 300,
            'live_update'         => 0,
            'workflow'            => '',
        ]);
    }

    // Initialize generate SEO defaults
    if (!get_option('ovesio_generate_seo_settings')) {
        update_option('ovesio_generate_seo_settings', [
            'status'         => 0,
            'for_posts'      => 0,
            'for_pages'      => 0,
            'for_products'   => 0,
            'for_categories' => 0,
            'live_update'    => 0,
            'workflow'       => '',
        ]);
    }

    // Set security hash if missing
    $api_settings = get_option('ovesio_api_settings', []);
    if (empty($api_settings['security_hash'])) {
        $api_settings['security_hash'] = wp_generate_password(32, false);
        update_option('ovesio_api_settings', $api_settings);
    }

    // Schedule cron
    if (!wp_next_scheduled('ovesio_cron_queue')) {
        wp_schedule_event(time(), 'ovesio_five_minutes', 'ovesio_cron_queue');
    }
}

register_deactivation_hook(__FILE__, 'ovesio_deactivation');
function ovesio_deactivation() {
    wp_clear_scheduled_hook('ovesio_cron_queue');
}

// Add custom cron interval (5 minutes)
add_filter('cron_schedules', 'ovesio_cron_intervals');
function ovesio_cron_intervals($schedules) {
    $schedules['ovesio_five_minutes'] = [
        'interval' => 300,
        'display'  => __('Every 5 Minutes', 'ovesio'),
    ];
    return $schedules;
}

// Schedule cron on admin_init if not already scheduled
add_action('admin_init', 'ovesio_maybe_schedule_cron');
function ovesio_maybe_schedule_cron() {
    if (!wp_next_scheduled('ovesio_cron_queue')) {
        wp_schedule_event(time(), 'ovesio_five_minutes', 'ovesio_cron_queue');
    }
}

// Cron callback
add_action('ovesio_cron_queue', 'ovesio_process_cron_queue');

// Detect post save (stale detection for live update)
add_action('save_post', 'ovesio_handle_post_saved', 20, 2);
function ovesio_handle_post_saved($post_id, $post) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!in_array($post->post_type, ['post', 'page', 'product'])) {
        return;
    }
    if ($post->post_status === 'auto-draft') {
        return;
    }

    // Live update for generate content
    $gc_settings = get_option('ovesio_generate_content_settings', []);
    $gc_status    = !empty($gc_settings['status']);
    $gc_live      = !empty($gc_settings['live_update']);
    $gc_for_type  = !empty($gc_settings['for_' . $post->post_type . 's'])
                    || !empty($gc_settings['for_' . $post->post_type]);

    if ($gc_status && $gc_live && $gc_for_type) {
        ovesio_call_generate_description_ai($post_id, $post->post_type);
    }

    // Live update for generate SEO
    $seo_settings = get_option('ovesio_generate_seo_settings', []);
    $seo_status   = !empty($seo_settings['status']);
    $seo_live     = !empty($seo_settings['live_update']);
    $seo_for_type = !empty($seo_settings['for_' . $post->post_type . 's'])
                    || !empty($seo_settings['for_' . $post->post_type]);

    if ($seo_status && $seo_live && $seo_for_type) {
        ovesio_call_generate_seo_ai($post_id, $post->post_type);
    }
}

add_action('admin_menu', 'ovesio_admin_menu');
function ovesio_admin_menu() {
    add_menu_page(
        __('Ovesio - Content AI', 'ovesio'),
        __('Ovesio', 'ovesio'),
        'manage_options',
        'ovesio',
        'ovesio_dashboard_page',
        'dashicons-admin-site-alt3',
    );

    add_submenu_page(
        'ovesio',
        __('Dashboard', 'ovesio'),
        __('Dashboard', 'ovesio'),
        'manage_options',
        'ovesio',
        'ovesio_dashboard_page'
    );

    add_submenu_page(
        'ovesio',
        __('API Settings', 'ovesio'),
        __('API Settings', 'ovesio'),
        'manage_options',
        'ovesio_api',
        'ovesio_api_settings_page'
    );

    add_submenu_page(
        'ovesio',
        __('Generate Content', 'ovesio'),
        __('Generate Content', 'ovesio'),
        'manage_options',
        'ovesio_generate_content',
        'ovesio_generate_content_page'
    );

    add_submenu_page(
        'ovesio',
        __('Generate SEO', 'ovesio'),
        __('Generate SEO', 'ovesio'),
        'manage_options',
        'ovesio_generate_seo',
        'ovesio_generate_seo_page'
    );

    add_submenu_page(
        'ovesio',
        __('Translation', 'ovesio'),
        __('Translation', 'ovesio'),
        'manage_options',
        'ovesio_translation',
        'ovesio_translation_settings_page_standalone'
    );

    add_submenu_page(
        'ovesio',
        __('Activity Log', 'ovesio'),
        __('Activity Log', 'ovesio'),
        'manage_options',
        'ovesio_requests',
        'ovesio_requests_list_page',
    );
}

// Standalone wrappers for submenu pages
function ovesio_api_settings_page() {
    echo '<div class="wrap"><h1>' . esc_html__('API Settings', 'ovesio') . '</h1>';
    ovesio_api_page();
    echo '</div>';
}

function ovesio_translation_settings_page_standalone() {
    echo '<div class="wrap"><h1>' . esc_html__('Translation Settings', 'ovesio') . '</h1>';
    ovesio_translation_settings_page();
    echo '</div>';
}

// Register settings
add_action('admin_init', 'ovesio_register_settings');
function ovesio_register_settings() {
    if (!ovesio_has_polylang()) {
        set_transient('ovesio_error', '<strong>Ovesio</strong> requires <a href="https://wordpress.org/plugins/polylang/" target="_blank"><b>Polylang</b></a>. Works with both Pro or Free version', 120);
    }

    $options = get_option('ovesio_options', []);
    if (is_array($options) && !array_key_exists('auto_refresh_pending', $options)) {
        $options['auto_refresh_pending'] = 1;
        update_option('ovesio_options', $options);
    }

    register_setting('ovesio_api', 'ovesio_api_settings', 'ovesio_sanitize_api_options');
    register_setting('ovesio_settings', 'ovesio_options', 'ovesio_sanitize_options');
    register_setting('ovesio_generate_content', 'ovesio_generate_content_settings', 'ovesio_sanitize_generate_content_settings');
    register_setting('ovesio_generate_seo', 'ovesio_generate_seo_settings', 'ovesio_sanitize_generate_seo_settings');
}

function ovesio_has_polylang() {
    return function_exists('pll_languages_list');
}

// Register Assets
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_script(
        'ovesio-script',
        plugin_dir_url(__FILE__) . 'admin/assets/js/admin.js',
        ['jquery'],
        OVESIO_PLUGIN_VERSION,
        true
    );

    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash', '');
    $cron_url = add_query_arg([
        'ovesio_cron' => '1',
        'security_hash' => $security_hash,
    ], home_url('/index.php'));

    wp_localize_script(
        'ovesio-script',
        'ovesioAdmin',
        [
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('ovesio-nonce'),
            'autoRefreshPending'  => (bool) ovesio_get_option('ovesio_options', 'auto_refresh_pending', 1),
            'refreshInterval'     => 30,
            'countdownLabel'      => __('Refreshing in', 'ovesio'),
            'secondsLabel'        => __('seconds', 'ovesio'),
            'cronUrl'             => $cron_url,
            'confirmDisconnect'   => __('Are you sure you want to disconnect from Ovesio API?', 'ovesio'),
            'disconnectUrl'       => admin_url('admin-ajax.php?action=ovesio_disconnect_api&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'saveContentUrl'      => admin_url('admin-ajax.php?action=ovesio_save_generate_content&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'saveSeoUrl'          => admin_url('admin-ajax.php?action=ovesio_save_generate_seo&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'saveTranslationUrl'  => admin_url('admin-ajax.php?action=ovesio_save_translation&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'getContentFormUrl'   => admin_url('admin-ajax.php?action=ovesio_get_generate_content_form&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'getSeoFormUrl'       => admin_url('admin-ajax.php?action=ovesio_get_generate_seo_form&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
            'getTranslationFormUrl' => admin_url('admin-ajax.php?action=ovesio_get_translation_form&_wpnonce=' . wp_create_nonce('ovesio-nonce')),
        ]
    );

    wp_enqueue_style(
        'ovesio-style',
        plugin_dir_url(__FILE__) . 'admin/assets/css/admin.css',
        [],
        OVESIO_PLUGIN_VERSION
    );
});

// Add page loader
if (is_admin()) {
    add_action('admin_footer', function () {
        echo "<div class=\"ovesio-loader-overlay-container\" style=\"display:none;\">
            <svg class=\"loader-overlay\" width=\"60\" height=\"60\" viewBox=\"0 0 100 100\" xmlns=\"http://www.w3.org/2000/svg\" fill=\"#000\">
                <circle cx=\"30\" cy=\"50\" r=\"10\">
                    <animate attributeName=\"r\" values=\"10;5;10\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0s\"/>
                    <animate attributeName=\"fill-opacity\" values=\"1;.3;1\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0s\"/>
                </circle>
                <circle cx=\"50\" cy=\"50\" r=\"10\">
                    <animate attributeName=\"r\" values=\"10;5;10\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0.2s\"/>
                    <animate attributeName=\"fill-opacity\" values=\"1;.3;1\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0.2s\"/>
                </circle>
                <circle cx=\"70\" cy=\"50\" r=\"10\">
                    <animate attributeName=\"r\" values=\"10;5;10\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0.4s\"/>
                    <animate attributeName=\"fill-opacity\" values=\"1;.3;1\" dur=\"1s\" repeatCount=\"indefinite\" begin=\"0.4s\"/>
                </circle>
            </svg>
        </div>
        <div id='ovesioModal' class='ov-modal' style='display:none;'>
          <div class='ov-reset ov-modal-dialog'>
            <div class='ov-card'>
              <div class='ov-card-header ov-modal-header'>
                <h4 class='ov-card-title ov-mb-0' id='modalTitle'>" . esc_html__('Edit Configuration', 'ovesio') . "</h4>
                <button type='button' class='ov-btn ov-btn-outline-secondary ov-btn-sm' onclick='ovesioWP.closeModal()'>&times;</button>
              </div>
              <div class='ov-card-body' id='modalContent'>
                <p>" . esc_html__('Loading...', 'ovesio') . "</p>
              </div>
              <div class='ov-card-footer ov-modal-footer'>
                <button type='button' class='ov-btn ov-btn-secondary' onclick='ovesioWP.closeModal()'>" . esc_html__('Cancel', 'ovesio') . "</button>
                <button type='button' class='ov-btn ov-btn-primary ov-ml-2' id='btn_save_modal' onclick='ovesioWP.saveModal()'>" . esc_html__('Save Changes', 'ovesio') . "</button>
              </div>
            </div>
          </div>
        </div>";
    });
}

// ============================================================
// AJAX Handlers
// ============================================================

// Disconnect API
add_action('wp_ajax_ovesio_disconnect_api', 'ovesio_ajax_disconnect_api');
function ovesio_ajax_disconnect_api() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }
    update_option('ovesio_api_settings', [
        'api_key'       => '',
        'api_url'       => '',
        'security_hash' => ovesio_get_option('ovesio_api_settings', 'security_hash', ''),
    ]);
    wp_send_json_success(['message' => __('Disconnected from Ovesio API', 'ovesio')]);
}

// Get Generate Content Form (AJAX modal)
add_action('wp_ajax_ovesio_get_generate_content_form', 'ovesio_ajax_get_generate_content_form');
function ovesio_ajax_get_generate_content_form() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }
    ob_start();
    ovesio_generate_content_form_html();
    echo ob_get_clean();
    exit;
}

// Get Generate SEO Form (AJAX modal)
add_action('wp_ajax_ovesio_get_generate_seo_form', 'ovesio_ajax_get_generate_seo_form');
function ovesio_ajax_get_generate_seo_form() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }
    ob_start();
    ovesio_generate_seo_form_html();
    echo ob_get_clean();
    exit;
}

// Get Translation Form (AJAX modal)
add_action('wp_ajax_ovesio_get_translation_form', 'ovesio_ajax_get_translation_form');
function ovesio_ajax_get_translation_form() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }
    ob_start();
    ovesio_translation_form_html();
    echo ob_get_clean();
    exit;
}

// Save Generate Content Settings (AJAX)
add_action('wp_ajax_ovesio_save_generate_content', 'ovesio_ajax_save_generate_content');
function ovesio_ajax_save_generate_content() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }

    /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
    $input = isset($_POST['ovesio_generate_content_settings']) ? (array) $_POST['ovesio_generate_content_settings'] : [];
    $sanitized = ovesio_sanitize_generate_content_settings($input);
    update_option('ovesio_generate_content_settings', $sanitized);

    ob_start();
    ovesio_generate_content_card_html();
    $card_html = ob_get_clean();

    wp_send_json_success([
        'message'   => __('Generate Content settings saved.', 'ovesio'),
        'card_html' => $card_html,
    ]);
}

// Save Generate SEO Settings (AJAX)
add_action('wp_ajax_ovesio_save_generate_seo', 'ovesio_ajax_save_generate_seo');
function ovesio_ajax_save_generate_seo() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }

    /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
    $input = isset($_POST['ovesio_generate_seo_settings']) ? (array) $_POST['ovesio_generate_seo_settings'] : [];
    $sanitized = ovesio_sanitize_generate_seo_settings($input);
    update_option('ovesio_generate_seo_settings', $sanitized);

    ob_start();
    ovesio_generate_seo_card_html();
    $card_html = ob_get_clean();

    wp_send_json_success([
        'message'   => __('Generate SEO settings saved.', 'ovesio'),
        'card_html' => $card_html,
    ]);
}

// Save Translation Settings (AJAX)
add_action('wp_ajax_ovesio_save_translation', 'ovesio_ajax_save_translation');
function ovesio_ajax_save_translation() {
    if (!current_user_can('manage_options') || !check_ajax_referer('ovesio-nonce', '_wpnonce', false)) {
        wp_send_json_error('Unauthorized', 403);
    }

    /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
    $input = isset($_POST['ovesio_options']) ? (array) $_POST['ovesio_options'] : [];
    $sanitized = ovesio_sanitize_options($input);
    update_option('ovesio_options', $sanitized);

    ob_start();
    ovesio_translate_card_html();
    $card_html = ob_get_clean();

    wp_send_json_success([
        'message'   => __('Translation settings saved.', 'ovesio'),
        'card_html' => $card_html,
    ]);
}

// Generate Description for a post (AJAX from row action)
add_action('wp_ajax_ovesio_generate_description', 'ovesio_ajax_generate_description');
function ovesio_ajax_generate_description() {
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'ovesio-nonce')) {
        wp_send_json_error('Invalid nonce', 403);
    }
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied', 403);
    }

    $type = sanitize_text_field(wp_unslash($_REQUEST['type'] ?? ''));
    $id   = (int) ($_REQUEST['id'] ?? 0);

    if (!$type || !$id) {
        wp_send_json_error('Missing parameters', 400);
    }

    $response = ovesio_call_generate_description_ai($id, $type);

    if (!empty($response['errors'])) {
        wp_send_json_error($response['errors'], 500);
    } else {
        wp_send_json_success($response);
    }
}

// Generate SEO for a post (AJAX from row action)
add_action('wp_ajax_ovesio_generate_seo', 'ovesio_ajax_generate_seo');
function ovesio_ajax_generate_seo() {
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'ovesio-nonce')) {
        wp_send_json_error('Invalid nonce', 403);
    }
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied', 403);
    }

    $type = sanitize_text_field(wp_unslash($_REQUEST['type'] ?? ''));
    $id   = (int) ($_REQUEST['id'] ?? 0);

    if (!$type || !$id) {
        wp_send_json_error('Missing parameters', 400);
    }

    $response = ovesio_call_generate_seo_ai($id, $type);

    if (!empty($response['errors'])) {
        wp_send_json_error($response['errors'], 500);
    } else {
        wp_send_json_success($response);
    }
}
