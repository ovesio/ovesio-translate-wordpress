<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// Generate SEO standalone page
// ============================================================

function ovesio_generate_seo_page()
{
    echo '<div class="wrap"><h1>' . esc_html__('Generate SEO Settings', 'ovesio') . '</h1>';
    $api_key   = ovesio_get_option('ovesio_api_settings', 'api_key', '');
    $api_url   = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');
    $workflows = [];
    if ($api_key && $api_url) {
        try {
            $api       = new Ovesio\OvesioAI($api_key, $api_url);
            $wf_result = $api->workflows()->list();
            if (!empty($wf_result->success) && !empty($wf_result->data)) {
                $workflows = (array) $wf_result->data;
            }
        } catch (Exception $e) {}
    }
    echo '<form method="post" action="options.php" class="ov-reset">';
    settings_fields('ovesio_generate_seo');
    ovesio_generate_seo_form_html($workflows);
    submit_button(__('Save Settings', 'ovesio'));
    echo '</form></div>';
}

// ============================================================
// Generate SEO Card HTML (used on dashboard)
// ============================================================

function ovesio_generate_seo_card_html($workflows = [])
{
    $settings      = get_option('ovesio_generate_seo_settings', []);
    $status        = !empty($settings['status']);
    $for_posts     = !empty($settings['for_posts']);
    $for_pages     = !empty($settings['for_pages']);
    $for_products  = !empty($settings['for_products']);
    $for_cats      = !empty($settings['for_categories']);
    $live_update   = !empty($settings['live_update']);
    $workflow_id   = $settings['workflow'] ?? '';

    $resources = [];
    if ($for_posts)    $resources[] = __('Posts', 'ovesio');
    if ($for_pages)    $resources[] = __('Pages', 'ovesio');
    if ($for_products) $resources[] = __('Products', 'ovesio');
    if ($for_cats)     $resources[] = __('Categories', 'ovesio');

    $workflow_name = '';
    if ($workflow_id && !empty($workflows)) {
        foreach ($workflows as $wf) {
            if ((string) $wf->id === (string) $workflow_id) {
                $workflow_name = $wf->name;
                break;
            }
        }
    }

    $modal_url = admin_url('admin-ajax.php?action=ovesio_get_generate_seo_form&_wpnonce=' . wp_create_nonce('ovesio-nonce'));
    ?>
    <div class="ov-card ov-card-fixed" id="generate_seo_card" style="flex:1">
        <div class="ov-card-body ov-card-body-relative">
            <h4 class="ov-card-title ov-text-xl"><?php esc_html_e('AI SEO Generator', 'ovesio'); ?></h4>
            <div class="ov-status-container">
                <?php if ($status): ?>
                <span class="ov-badge ov-badge-success"><?php esc_html_e('Enabled', 'ovesio'); ?></span>
                <?php else: ?>
                <span class="ov-badge ov-badge-danger"><?php esc_html_e('Disabled', 'ovesio'); ?></span>
                <?php endif; ?>
            </div>

            <ul class="ov-bullet-list">
                <li class="ov-bullet-item" style="align-items:flex-start">
                    <span class="ov-bullet ov-bullet-red" style="margin-top:7px;flex-shrink:0"></span>
                    <div>
                        <?php if (!empty($resources)): ?>
                            <?php esc_html_e('Generating SEO for', 'ovesio'); ?>:<br>
                            <?php foreach ($resources as $res): ?>
                                <small class="ov-text-sm ov-text-muted">- <?php echo esc_html($res); ?></small><br>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="ov-text-danger"><?php esc_html_e('No resources targeted', 'ovesio'); ?></span>
                        <?php endif; ?>
                    </div>
                </li>
                <li class="ov-bullet-item">
                    <span class="ov-bullet ov-bullet-green"></span>
                    <?php if ($live_update): ?>
                        <?php esc_html_e('Live SEO generation on update', 'ovesio'); ?>
                    <?php else: ?>
                        <?php esc_html_e('One time SEO generation', 'ovesio'); ?>
                    <?php endif; ?>
                </li>
                <li class="ov-bullet-item">
                    <span class="ov-bullet ov-bullet-blue"></span>
                    <?php esc_html_e('Writes to Yoast SEO fields', 'ovesio'); ?>
                </li>
                <?php if ($workflow_name): ?>
                <li class="ov-bullet-item">
                    <span class="ov-bullet ov-bullet-orange"></span>
                    <b><?php esc_html_e('Workflow', 'ovesio'); ?>:</b>&nbsp;<?php echo esc_html($workflow_name); ?>
                </li>
                <?php endif; ?>
            </ul>

            <div class="ov-card-bottom">
                <div class="ov-feedback-container"></div>
                <div class="ov-edit-button">
                    <a href="javascript:;" class="ov-btn ov-btn-sm ov-btn-outline-primary"
                        onclick="ovesioWP.modalButton(event)"
                        data-title="<?php esc_attr_e('AI SEO Generator Settings', 'ovesio'); ?>"
                        data-url="<?php echo esc_url($modal_url); ?>"
                        data-save-action="generate_seo">
                        <?php esc_html_e('Edit', 'ovesio'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================
// Generate SEO Form HTML (used in modal and standalone page)
// ============================================================

function ovesio_generate_seo_form_html($workflows = [])
{
    if (empty($workflows)) {
        $api_key = ovesio_get_option('ovesio_api_settings', 'api_key', '');
        $api_url = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');
        if ($api_key && $api_url) {
            try {
                $api       = new Ovesio\OvesioAI($api_key, $api_url);
                $wf_result = $api->workflows()->list();
                if (!empty($wf_result->success) && !empty($wf_result->data)) {
                    $workflows = (array) $wf_result->data;
                }
            } catch (Exception $e) {}
        }
    }

    $settings       = get_option('ovesio_generate_seo_settings', []);
    $status         = !empty($settings['status']);
    $for_posts      = !empty($settings['for_posts']);
    $for_pages      = !empty($settings['for_pages']);
    $for_products   = !empty($settings['for_products']);
    $for_categories = !empty($settings['for_categories']);
    $live_update    = !empty($settings['live_update']);
    $workflow_id    = $settings['workflow'] ?? '';

    $save_url = admin_url('admin-ajax.php?action=ovesio_save_generate_seo&_wpnonce=' . wp_create_nonce('ovesio-nonce'));
    ?>
    <form action="<?php echo esc_url($save_url); ?>" method="post" id="generate_seo_form"
          onsubmit="ovesioWP.generateSeoFormSave(event)">
        <div class="ov-reset">

            <fieldset class="ov-fieldset">
                <!-- Status -->
                <div class="ov-form-group">
                    <label class="ov-form-label"><?php esc_html_e('Status', 'ovesio'); ?>:</label>
                    <div style="display:flex;align-items:center;gap:.5em;">
                        <label class="ov-form-switch">
                            <input name="ovesio_generate_seo_settings[status]" value="" type="hidden">
                            <input name="ovesio_generate_seo_settings[status]" type="checkbox" value="1" <?php checked($status); ?>>
                            <span class="ov-switch-slider"></span>
                        </label>
                        <span class="ov-switch-label"><?php esc_html_e('Enable AI SEO Generation', 'ovesio'); ?></span>
                    </div>
                </div>

                <!-- Live Update -->
                <div class="ov-form-group">
                    <label class="ov-form-label">
                        <?php esc_html_e('Live Update', 'ovesio'); ?><br>
                        <small class="ov-text-sm ov-helper-text"><?php esc_html_e('Regenerate SEO meta tags automatically when post is updated', 'ovesio'); ?></small>
                    </label>
                    <div style="display:flex;align-items:center;gap:.5em;">
                        <label class="ov-form-switch">
                            <input name="ovesio_generate_seo_settings[live_update]" value="" type="hidden">
                            <input name="ovesio_generate_seo_settings[live_update]" type="checkbox" value="1" <?php checked($live_update); ?>>
                            <span class="ov-switch-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Workflow -->
                <div class="ov-form-group">
                    <label class="ov-form-label"><?php esc_html_e('Workflow', 'ovesio'); ?>:</label>
                    <div class="ov-form-field">
                        <select name="ovesio_generate_seo_settings[workflow]" class="ov-form-control">
                            <option value=""><?php esc_html_e('— no workflow selected —', 'ovesio'); ?></option>
                            <?php foreach ($workflows as $wf): ?>
                            <option value="<?php echo esc_attr($wf->id); ?>" <?php selected($workflow_id, $wf->id); ?>>
                                <?php echo esc_html($wf->name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="ov-alert ov-alert-primary ov-mb-0">
                    <strong><?php esc_html_e('Yoast SEO Integration', 'ovesio'); ?>:</strong>
                    <?php esc_html_e('Generated SEO data is saved directly into Yoast SEO fields (meta title, meta description, focus keyword).', 'ovesio'); ?>
                </div>
            </fieldset>

            <div class="ov-mb-3 ov-text-muted"><u><?php esc_html_e('Generate SEO for:', 'ovesio'); ?></u></div>

            <!-- Posts fieldset -->
            <fieldset class="ov-fieldset">
                <legend class="ov-d-flex" style="gap:10px;">
                    <?php esc_html_e('Posts', 'ovesio'); ?>
                    <label class="ov-form-switch">
                        <input name="ovesio_generate_seo_settings[for_posts]" value="" type="hidden">
                        <input name="ovesio_generate_seo_settings[for_posts]" type="checkbox" value="1" <?php checked($for_posts); ?>>
                        <span class="ov-switch-slider"></span>
                    </label>
                </legend>
            </fieldset>

            <!-- Pages fieldset -->
            <fieldset class="ov-fieldset">
                <legend class="ov-d-flex" style="gap:10px;">
                    <?php esc_html_e('Pages', 'ovesio'); ?>
                    <label class="ov-form-switch">
                        <input name="ovesio_generate_seo_settings[for_pages]" value="" type="hidden">
                        <input name="ovesio_generate_seo_settings[for_pages]" type="checkbox" value="1" <?php checked($for_pages); ?>>
                        <span class="ov-switch-slider"></span>
                    </label>
                </legend>
            </fieldset>

            <!-- Products fieldset -->
            <fieldset class="ov-fieldset">
                <legend class="ov-d-flex" style="gap:10px;">
                    <?php esc_html_e('Products (WooCommerce)', 'ovesio'); ?>
                    <label class="ov-form-switch">
                        <input name="ovesio_generate_seo_settings[for_products]" value="" type="hidden">
                        <input name="ovesio_generate_seo_settings[for_products]" type="checkbox" value="1" <?php checked($for_products); ?>>
                        <span class="ov-switch-slider"></span>
                    </label>
                </legend>
            </fieldset>

            <!-- Categories fieldset -->
            <fieldset class="ov-fieldset">
                <legend class="ov-d-flex" style="gap:10px;">
                    <?php esc_html_e('Categories', 'ovesio'); ?>
                    <label class="ov-form-switch">
                        <input name="ovesio_generate_seo_settings[for_categories]" value="" type="hidden">
                        <input name="ovesio_generate_seo_settings[for_categories]" type="checkbox" value="1" <?php checked($for_categories); ?>>
                        <span class="ov-switch-slider"></span>
                    </label>
                </legend>
            </fieldset>

        </div>
    </form>
    <?php
}
