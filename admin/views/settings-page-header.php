<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// Dashboard page - main entry point
// ============================================================

function ovesio_dashboard_page()
{
    $api_key      = ovesio_get_option('ovesio_api_settings', 'api_key', '');
    $api_url      = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');
    $connected    = !empty($api_key) && !empty($api_url);

    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash', '');
    $cron_url      = home_url('/index.php?ovesio_cron=1&security_hash=' . $security_hash);
    $callback_url  = home_url('/index.php?ovesio_callback=1&security_hash=' . $security_hash);

    $errors        = [];

    // Fetch workflows if connected
    $workflows     = [];
    if ($connected) {
        try {
            $api       = new Ovesio\OvesioAI($api_key, $api_url);
            $wf_result = $api->workflows()->list();
            if (!empty($wf_result->success) && !empty($wf_result->data)) {
                $workflows = (array) $wf_result->data;
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    $count_errors = 0;
    global $wpdb;
    $table_name = $wpdb->prefix . 'ovesio_list';
    if ($connected) {
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        $count_errors = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE (translate_status = 0 AND translate_id IS NOT NULL AND translate_date < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                OR (generate_description_status = 0 AND generate_description_id IS NOT NULL AND generate_description_date < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                OR (metatags_status = 0 AND metatags_id IS NOT NULL AND metatags_date < DATE_SUB(NOW(), INTERVAL 30 MINUTE))"
        );
    }
    ?>
    <div class="wrap">
        <div class="ov-reset">

            <!-- Top Bar -->
            <div class="ov-top-bar">
                <div>
                    <h1 class="ov-page-title">
                        <img src="<?php echo esc_url(plugin_dir_url(OVESIO_PLUGIN_DIR . 'ovesio.php') . 'admin/assets/img/logo-ovesio-sm.png'); ?>" alt="Ovesio" style="height:28px;vertical-align:middle;margin-right:8px;" onerror="this.style.display='none'">
                        Ovesio
                    </h1>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if ($count_errors > 0): ?>
                    <a class="ov-badge ov-badge-danger" href="<?php echo esc_url(admin_url('admin.php?page=ovesio_requests')); ?>">
                        <?php echo esc_html__('Errors', 'ovesio'); ?>: <?php echo (int) $count_errors; ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ovesio_requests')); ?>" class="ov-btn ov-btn-secondary">
                        <?php esc_html_e('Activity Log', 'ovesio'); ?>
                    </a>
                    <?php if ($connected): ?>
                    <button type="button" class="ov-btn ov-btn-danger" id="btn_disconnect"
                        data-url="<?php echo esc_url(admin_url('admin-ajax.php?action=ovesio_disconnect_api&_wpnonce=' . wp_create_nonce('ovesio-nonce'))); ?>"
                        data-confirm="<?php esc_attr_e('Are you sure you want to disconnect from Ovesio API?', 'ovesio'); ?>"
                        onclick="ovesioWP.disconnectApi(event)">
                        <?php esc_html_e('Disconnect', 'ovesio'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="ovesio_feedback_container"></div>

            <?php if (!$connected): ?>
            <!-- Connection Card -->
            <div id="connection_card" class="ov-card" style="max-width:500px;margin:2em auto;">
                <div class="ov-card-header">
                    <h4 class="ov-card-title ov-mb-0"><?php esc_html_e('Connect to Ovesio', 'ovesio'); ?></h4>
                </div>
                <div class="ov-card-body">
                    <div style="text-align:center;margin-bottom:1.5em;">
                        <div style="width:70px;height:70px;margin:0 auto 1em;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z" fill="white"/>
                            </svg>
                        </div>
                        <p class="ov-text-muted"><?php esc_html_e('Enter your API credentials to get started', 'ovesio'); ?></p>
                    </div>

                    <form method="post" action="options.php" class="ovesio-settings-form">
                        <?php settings_fields('ovesio_api'); ?>
                        <?php
                        $sh = ovesio_get_option('ovesio_api_settings', 'security_hash', '');
                        if (!$sh) $sh = wp_generate_password(32, false);
                        ?>
                        <input type="hidden" name="ovesio_api_settings[security_hash]" value="<?php echo esc_attr($sh); ?>">

                        <div class="ov-form-group ov-form-group-vertical">
                            <label class="ov-form-label" for="api_url_connect"><?php esc_html_e('API URL', 'ovesio'); ?></label>
                            <div class="ov-form-field" style="max-width:100%">
                                <input type="text" id="api_url_connect" name="ovesio_api_settings[api_url]"
                                    value="<?php echo esc_attr($api_url ?: 'https://api.ovesio.com/v1/'); ?>"
                                    class="ov-form-control" placeholder="https://api.ovesio.com/v1/">
                            </div>
                        </div>

                        <div class="ov-form-group ov-form-group-vertical">
                            <label class="ov-form-label" for="api_key_connect">
                                <?php esc_html_e('API Token', 'ovesio'); ?>
                                <small>(<a href="https://app.ovesio.com/" target="_blank"><?php esc_html_e('Get Token', 'ovesio'); ?></a>)</small>
                            </label>
                            <div class="ov-form-field" style="max-width:100%">
                                <input type="password" id="api_key_connect" name="ovesio_api_settings[api_key]"
                                    value="" class="ov-form-control"
                                    placeholder="account_id:api_token">
                                <small class="ov-text-muted"><?php esc_html_e('Found in Ovesio App → Settings → API Token', 'ovesio'); ?></small>
                            </div>
                        </div>

                        <button type="submit" class="ov-btn ov-btn-primary ov-btn-block">
                            <?php esc_html_e('Connect', 'ovesio'); ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php else: ?>

            <?php if (!empty($errors)): ?>
            <div class="ov-alert ov-alert-danger ov-mb-3">
                <ul style="margin:0;padding-left:1.2em;">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Workflow Cards -->
            <div class="ov-table-responsive">
                <div class="ov-cards-container" id="workflow_cards">

                    <!-- Store Info Card -->
                    <div class="ov-card ov-workflow-card ov-card-home">
                        <div class="ov-card-body">
                            <h4 class="ov-card-title ov-text-xl"><?php esc_html_e('Store Info', 'ovesio'); ?></h4>
                            <div style="text-align:center;padding:1em 0;">
                                <div style="width:80px;height:80px;margin:0 auto 1em;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 6px rgba(0,0,0,.1);">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M9 22V12H15V22" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <p class="ov-text-muted ov-mb-3"><?php esc_html_e('Connected to Ovesio', 'ovesio'); ?></p>
                                <span class="ov-badge ov-badge-success"><?php esc_html_e('Active', 'ovesio'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Flow Arrow -->
                    <div class="ov-flow-arrow">
                        <svg width="30" height="24" viewBox="0 0 30 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2 12H28M28 12L22 6M28 12L22 18" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <!-- Generate Content Card -->
                    <?php ovesio_generate_content_card_html($workflows); ?>

                    <!-- Flow Arrow -->
                    <div class="ov-flow-arrow">
                        <svg width="30" height="24" viewBox="0 0 30 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2 12H28M28 12L22 6M28 12L22 18" stroke="#6c757d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <!-- Generate SEO Card -->
                    <?php ovesio_generate_seo_card_html($workflows); ?>

                    <!-- Flow Arrow -->
                    <div class="ov-flow-arrow">
                        <svg width="30" height="24" viewBox="0 0 30 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2 12H28M28 12L22 6M28 12L22 18" stroke="#0d6efd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <!-- Translate Card -->
                    <?php ovesio_translate_card_html($workflows); ?>

                </div>
            </div>

            <!-- Cron Info -->
            <div class="ov-mb-3" id="general_cron_info">
                <h4 class="ov-mb-2"><?php esc_html_e('Cron / Automatic Processing', 'ovesio'); ?></h4>
                <div class="ov-cron-section">
                    <div class="ov-form-group ov-form-group-vertical">
                        <label class="ov-form-label"><?php esc_html_e('Cron URL', 'ovesio'); ?>:</label>
                        <div class="ov-form-field" style="max-width:100%">
                            <input type="text" class="ov-form-control" value="<?php echo esc_attr($cron_url); ?>" readonly onclick="this.select()">
                        </div>
                    </div>
                    <div class="ov-form-group ov-form-group-vertical">
                        <label class="ov-form-label"><?php esc_html_e('Cron Command', 'ovesio'); ?>:</label>
                        <div class="ov-form-field" style="max-width:100%">
                            <input type="text" class="ov-form-control" value="<?php echo esc_attr('*/5 * * * * wget -q -O - ' . $cron_url . ' > /dev/null 2>&1'); ?>" readonly onclick="this.select()">
                        </div>
                    </div>
                    <div class="ov-form-group ov-form-group-vertical">
                        <label class="ov-form-label"><?php esc_html_e('Recommended Interval', 'ovesio'); ?>:</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="ov-badge ov-badge-info">1-5 <?php esc_html_e('minutes', 'ovesio'); ?></span>
                            <small class="ov-text-muted"><?php esc_html_e('WordPress built-in cron runs automatically. Use external cron for best results.', 'ovesio'); ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Callback URL info -->
            <div class="ov-callback-info ov-mb-3">
                <strong><?php esc_html_e('Callback URL', 'ovesio'); ?>:</strong>
                <a href="<?php echo esc_url($callback_url); ?>" target="_blank" class="ov-text-sm"><?php echo esc_html($callback_url); ?></a>
                <small class="ov-text-sm ov-text-muted"><?php esc_html_e('This URL is automatically configured. Do not share it publicly.', 'ovesio'); ?></small>
            </div>

            <?php endif; ?>

        </div><!-- .ov-reset -->
    </div><!-- .wrap -->
    <?php
}
