<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// Translation Card HTML (used on dashboard)
// ============================================================

function ovesio_translate_card_html($workflows = [])
{
    $options     = get_option('ovesio_options', []);
    $trans_to    = (array) ($options['translation_to'] ?? []);
    $status      = !empty($trans_to);
    $workflow_id = $options['translation_workflow'] ?? '';

    $workflow_name = '';
    if ($workflow_id && !empty($workflows)) {
        foreach ($workflows as $wf) {
            if ((string) $wf->id === (string) $workflow_id) {
                $workflow_name = $wf->name;
                break;
            }
        }
    }

    $lang_flags = [];
    if (!empty($trans_to) && function_exists('pll_languages_list')) {
        $pll_langs = pll_languages_list(['fields' => false]);
        foreach ($pll_langs as $pll_lang) {
            $ovesio_code = ovesio_polylang_to_ovesio_code_conversion($pll_lang->slug);
            if (in_array($ovesio_code, $trans_to, true) || in_array($pll_lang->slug, $trans_to, true)) {
                $country  = ovesio_lang_to_country_code($pll_lang->slug);
                $flag_url = plugins_url('admin/assets/flags/' . $country . '.png', OVESIO_PLUGIN_DIR . 'ovesio.php');
                $lang_flags[] = '<img src="' . esc_url($flag_url) . '" width="16" height="11" title="' . esc_attr($pll_lang->name) . '" alt="' . esc_attr($pll_lang->slug) . '">';
            }
        }
    }

    $resources = [];
    if (!empty($options['translate_for_posts']))        $resources[] = __('Posts', 'ovesio');
    if (!empty($options['translate_for_pages']))        $resources[] = __('Pages', 'ovesio');
    if (!empty($options['translate_for_products']))     $resources[] = __('Products', 'ovesio');
    if (!empty($options['translate_for_categories']))   $resources[] = __('Categories', 'ovesio');
    if (!empty($options['translate_for_product_cats'])) $resources[] = __('Product Categories', 'ovesio');

    $modal_url = admin_url('admin-ajax.php?action=ovesio_get_translation_form&_wpnonce=' . wp_create_nonce('ovesio-nonce'));
    ?>
    <div class="ov-card ov-card-fixed" id="translate_card" style="flex:1">
        <div class="ov-card-body ov-card-body-relative">
            <h4 class="ov-card-title ov-text-xl"><?php esc_html_e('AI Translation', 'ovesio'); ?></h4>
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
                        <?php if (!empty($trans_to)): ?>
                            <?php esc_html_e('Translating to', 'ovesio'); ?>:<br>
                            <?php if (!empty($lang_flags)): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                                <?php echo implode(' ', $lang_flags); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="ov-text-danger"><?php esc_html_e('No target languages configured', 'ovesio'); ?></span>
                        <?php endif; ?>
                    </div>
                </li>
                <?php if (!empty($resources)): ?>
                <li class="ov-bullet-item" style="align-items:flex-start">
                    <span class="ov-bullet ov-bullet-blue" style="margin-top:7px;flex-shrink:0"></span>
                    <div>
                        <?php esc_html_e('Translating', 'ovesio'); ?>:<br>
                        <?php foreach ($resources as $res): ?>
                            <small class="ov-text-sm ov-text-muted">- <?php echo esc_html($res); ?></small><br>
                        <?php endforeach; ?>
                    </div>
                </li>
                <?php endif; ?>
                <?php if ($workflow_name): ?>
                <li class="ov-bullet-item">
                    <span class="ov-bullet ov-bullet-orange"></span>
                    <b><?php esc_html_e('Workflow', 'ovesio'); ?>:</b>&nbsp;<?php echo esc_html($workflow_name); ?>
                </li>
                <?php endif; ?>
                <?php if (!empty($options['translation_default_language'])): ?>
                <li class="ov-bullet-item">
                    <span class="ov-bullet ov-bullet-purple"></span>
                    <?php if ($options['translation_default_language'] === 'auto'): ?>
                        <?php esc_html_e('Auto detect source language', 'ovesio'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Content-defined source language', 'ovesio'); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
            </ul>

            <div class="ov-card-bottom">
                <div class="ov-feedback-container"></div>
                <div class="ov-edit-button">
                    <a href="javascript:;" class="ov-btn ov-btn-sm ov-btn-outline-primary"
                        onclick="ovesioWP.modalButton(event)"
                        data-title="<?php esc_attr_e('Translation Settings', 'ovesio'); ?>"
                        data-url="<?php echo esc_url($modal_url); ?>"
                        data-save-action="translation">
                        <?php esc_html_e('Edit', 'ovesio'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================
// Translation Form HTML (used in modal)
// ============================================================

function ovesio_translation_form_html($workflows = [])
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

    $options   = get_option('ovesio_options', []);
    $trans_lang = $options['translation_default_language'] ?? 'system';
    $trans_wf   = $options['translation_workflow'] ?? '';
    $trans_to   = (array) ($options['translation_to'] ?? []);
    $post_status = $options['post_status'] ?? 'publish';
    $auto_refresh = (int) ($options['auto_refresh_pending'] ?? 1);

    $languages        = [];
    $system_languages = [];
    $api_key          = ovesio_get_option('ovesio_api_settings', 'api_key', '');
    $api_url          = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');
    if ($api_key && $api_url) {
        try {
            $api      = new Ovesio\OvesioAI($api_key, $api_url);
            $lang_res = $api->languages()->list();
            if (!empty($lang_res->success)) {
                $languages = (array) $lang_res->data;
            }
        } catch (Exception $e) {}
    }
    if (function_exists('pll_languages_list')) {
        $system_languages = pll_languages_list(['fields' => 'slug']);
    }

    $save_url = admin_url('admin-ajax.php?action=ovesio_save_translation&_wpnonce=' . wp_create_nonce('ovesio-nonce'));
    ?>
    <form action="<?php echo esc_url($save_url); ?>" method="post" id="translate_form"
          onsubmit="ovesioWP.translateFormSave(event)">
        <div class="ov-reset">

            <fieldset class="ov-fieldset">
                <div class="ov-form-group">
                    <label class="ov-form-label">
                        <?php esc_html_e('Source Language', 'ovesio'); ?><br>
                        <small class="ov-helper-text"><?php esc_html_e('How to detect content language', 'ovesio'); ?></small>
                    </label>
                    <div class="ov-form-field">
                        <label style="display:flex;align-items:center;gap:.5em;margin-bottom:.5em;">
                            <input type="radio" name="ovesio_options[translation_default_language]" value="system" <?php checked('system', $trans_lang); ?>>
                            <?php esc_html_e('Content defined language (Polylang)', 'ovesio'); ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:.5em;">
                            <input type="radio" name="ovesio_options[translation_default_language]" value="auto" <?php checked('auto', $trans_lang); ?>>
                            <?php esc_html_e('Auto detect', 'ovesio'); ?>
                        </label>
                    </div>
                </div>

                <div class="ov-form-group">
                    <label class="ov-form-label"><?php esc_html_e('Translation Workflow', 'ovesio'); ?>:</label>
                    <div class="ov-form-field">
                        <select name="ovesio_options[translation_workflow]" class="ov-form-control">
                            <option value=""><?php esc_html_e('— no workflow selected —', 'ovesio'); ?></option>
                            <?php foreach ($workflows as $wf): ?>
                                <?php if (isset($wf->type) && $wf->type !== 'translate') continue; ?>
                                <option value="<?php echo esc_attr($wf->id); ?>" <?php selected($trans_wf, $wf->id); ?>>
                                    <?php echo esc_html($wf->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="ov-form-group">
                    <label class="ov-form-label"><?php esc_html_e('Post Status', 'ovesio'); ?>:</label>
                    <div class="ov-form-field">
                        <select name="ovesio_options[post_status]" class="ov-form-control">
                            <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish', 'ovesio'); ?></option>
                            <option value="pending" <?php selected($post_status, 'pending'); ?>><?php esc_html_e('Pending Review', 'ovesio'); ?></option>
                            <option value="draft"   <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Draft', 'ovesio'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="ov-form-group">
                    <label class="ov-form-label"><?php esc_html_e('Auto Refresh', 'ovesio'); ?>:</label>
                    <div style="display:flex;align-items:center;gap:.5em;">
                        <label class="ov-form-switch">
                            <input name="ovesio_options[auto_refresh_pending]" value="" type="hidden">
                            <input name="ovesio_options[auto_refresh_pending]" type="checkbox" value="1" <?php checked(1, $auto_refresh); ?>>
                            <span class="ov-switch-slider"></span>
                        </label>
                        <span class="ov-switch-label"><?php esc_html_e('Refresh every 30s when pending', 'ovesio'); ?></span>
                    </div>
                </div>
            </fieldset>

            <?php if (!empty($languages)): ?>
            <fieldset class="ov-fieldset">
                <legend><?php esc_html_e('Translate To', 'ovesio'); ?></legend>
                <p class="ov-text-muted" style="margin-bottom:1em;"><?php printf(
                    /* translators: %s = link */
                    esc_html__('Languages must be added in %s first.', 'ovesio'),
                    '<a href="' . esc_url(admin_url('admin.php?page=mlang')) . '" target="_blank">Polylang</a>'
                ); ?></p>
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <?php foreach ($languages as $language):
                        $pll_code = ovesio_polylang_code_conversion($language->code);
                        $disabled = !in_array($pll_code, $system_languages) ? 'disabled' : '';
                        $checked  = in_array($language->code, $trans_to);
                        $country  = ovesio_lang_to_country_code($language->code);
                    ?>
                    <label style="width:22%;min-width:130px;display:inline-flex;align-items:center;gap:4px;margin-bottom:6px;<?php echo $disabled ? 'opacity:.5' : ''; ?>">
                        <input type="checkbox" name="ovesio_options[translation_to][]"
                            value="<?php echo esc_attr($language->code); ?>"
                            <?php checked($checked); ?> <?php echo esc_attr($disabled); ?>>
                        <img src="<?php echo esc_url(plugins_url('admin/assets/flags/' . $country . '.png', OVESIO_PLUGIN_DIR . 'ovesio.php')); ?>"
                            width="16" height="11" alt="<?php echo esc_attr($language->code); ?>">
                        <?php echo esc_html($language->name); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php endif; ?>

            <fieldset class="ov-fieldset">
                <legend><?php esc_html_e('Resources to Translate', 'ovesio'); ?></legend>
                <?php
                $resource_opts = [
                    'translate_for_posts'        => __('Posts', 'ovesio'),
                    'translate_for_pages'        => __('Pages', 'ovesio'),
                    'translate_for_products'     => __('Products', 'ovesio'),
                    'translate_for_categories'   => __('Categories', 'ovesio'),
                    'translate_for_product_cats' => __('Product Categories', 'ovesio'),
                ];
                ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5em;">
                    <?php foreach ($resource_opts as $opt_key => $opt_label):
                        $opt_val = (int) ($options[$opt_key] ?? 1);
                    ?>
                    <label style="display:flex;align-items:center;gap:.5em;">
                        <label class="ov-form-switch">
                            <input name="ovesio_options[<?php echo esc_attr($opt_key); ?>]" value="" type="hidden">
                            <input name="ovesio_options[<?php echo esc_attr($opt_key); ?>]" type="checkbox" value="1" <?php checked(1, $opt_val); ?>>
                            <span class="ov-switch-slider"></span>
                        </label>
                        <span><?php echo esc_html($opt_label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

        </div>
    </form>
    <?php
}

// ============================================================
// Translation Settings standalone page (linked from submenu)
// ============================================================

function ovesio_translation_settings_page()
{
    ?>
    <form method="post" action="options.php" class="ovesio-settings-form">
        <?php settings_fields('ovesio_settings'); ?>
        <?php do_settings_sections('ovesio_settings'); ?>
        <?php
        $api_key   = ovesio_get_option('ovesio_api_settings', 'api_key', '');
        $api_url   = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');
        $options   = get_option('ovesio_options', []);
        $trans_lang  = $options['translation_default_language'] ?? 'system';
        $trans_wf    = $options['translation_workflow'] ?? '';
        $trans_to    = (array) ($options['translation_to'] ?? []);
        $post_status = $options['post_status'] ?? 'publish';
        $auto_refresh = (int) ($options['auto_refresh_pending'] ?? 1);

        $languages        = [];
        $system_languages = [];
        $workflows        = [];

        if ($api_key && $api_url) {
            try {
                $api       = new Ovesio\OvesioAI($api_key, $api_url);
                $wf_result = $api->workflows()->list();
                if (!empty($wf_result->success) && !empty($wf_result->data)) {
                    $workflows = (array) $wf_result->data;
                }
                $lang_res = $api->languages()->list();
                if (!empty($lang_res->success)) {
                    $languages = (array) $lang_res->data;
                }
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        if (function_exists('pll_languages_list')) {
            $system_languages = pll_languages_list(['fields' => 'slug']);
        }
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Source Language', 'ovesio'); ?></th>
                <td>
                    <label><input type="radio" name="ovesio_options[translation_default_language]" value="system" <?php checked('system', $trans_lang); ?>> <?php esc_html_e('Content defined language', 'ovesio'); ?></label><br>
                    <label><input type="radio" name="ovesio_options[translation_default_language]" value="auto" <?php checked('auto', $trans_lang); ?>> <?php esc_html_e('Auto detect', 'ovesio'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Workflow', 'ovesio'); ?></th>
                <td>
                    <select name="ovesio_options[translation_workflow]" class="regular-text">
                        <option value=""><?php esc_html_e('— no workflow —', 'ovesio'); ?></option>
                        <?php foreach ($workflows as $wf): ?>
                            <?php if (isset($wf->type) && $wf->type !== 'translate') continue; ?>
                            <option value="<?php echo esc_attr($wf->id); ?>" <?php selected($trans_wf, $wf->id); ?>><?php echo esc_html($wf->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Translate To', 'ovesio'); ?></th>
                <td>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <?php foreach ($languages as $language):
                        $pll_code = ovesio_polylang_code_conversion($language->code);
                        $disabled = !in_array($pll_code, $system_languages) ? 'disabled' : '';
                        $checked  = in_array($language->code, $trans_to);
                        $country  = ovesio_lang_to_country_code($language->code);
                    ?>
                    <label style="width:22%;min-width:130px;display:inline-flex;align-items:center;gap:4px;margin-bottom:4px;<?php echo $disabled ? 'opacity:.5' : ''; ?>">
                        <input type="checkbox" name="ovesio_options[translation_to][]"
                            value="<?php echo esc_attr($language->code); ?>"
                            <?php checked($checked); ?> <?php echo esc_attr($disabled); ?>>
                        <img src="<?php echo esc_url(plugins_url('admin/assets/flags/' . $country . '.png', OVESIO_PLUGIN_DIR . 'ovesio.php')); ?>"
                            width="16" height="11" alt="">
                        <?php echo esc_html($language->name); ?>
                    </label>
                    <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Translated Post Status', 'ovesio'); ?></th>
                <td>
                    <select name="ovesio_options[post_status]">
                        <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish', 'ovesio'); ?></option>
                        <option value="pending" <?php selected($post_status, 'pending'); ?>><?php esc_html_e('Pending Review', 'ovesio'); ?></option>
                        <option value="draft"   <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Draft', 'ovesio'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Auto Refresh', 'ovesio'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ovesio_options[auto_refresh_pending]" value="1" <?php checked(1, $auto_refresh); ?>>
                        <?php esc_html_e('Refresh pages every 30s when pending translations exist', 'ovesio'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}
