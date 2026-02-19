<?php

if (!defined('ABSPATH')) exit;


function ovesio_translation_settings_page()
{
    $api_key = ovesio_get_option('ovesio_api_settings', 'api_key', '');
    $api_url = ovesio_get_option('ovesio_api_settings', 'api_url', 'https://api.ovesio.com/v1/');

    $system_languages = PLL()->model->get_languages_list();
    $system_languages = array_column($system_languages, 'slug');

    //Disable default system language
    $system_default_language = pll_default_language();
    $lang_id = array_search($system_default_language, (array)$system_languages);
    if(is_numeric($lang_id)) {
        unset($system_languages[$lang_id]);
    }

    $error = false;
    $ovesio = new Ovesio\OvesioAI($api_key, $api_url);

    try {
        $workflows = $ovesio->workflows()->list();

        if(empty($workflows->success)) {
            $error = 'Ovesio: Workflows list operation failed.';
        }
    } catch (Exception $e) {
        $error = 'Ovesio API Connection Error: ' . $e->getMessage();
    }

    if(!$error) {
        try {
            $languages = $ovesio->languages()->list();

            if(empty($languages->success)) {
                $error = 'Ovesio: Languages list operation failed.';
            }
        } catch (Exception $e) {
            $error = 'Ovesio API Connection Error: ' . $e->getMessage();
        }
    }

    if($error) {
        set_transient('ovesio_error', $error, 30);

        $url = admin_url('admin.php?page=ovesio');
        header('Location: ' . $url);
        exit;
    }

    // Get settings
    $translation_default_language = ovesio_get_option('ovesio_options', 'translation_default_language', '');
    $translation_default_language = $translation_default_language ? $translation_default_language : 'system';
    $translation_workflow = ovesio_get_option('ovesio_options', 'translation_workflow', 0);
    $translation_to = ovesio_get_option('ovesio_options', 'translation_to');
    $post_status = ovesio_get_option('ovesio_options', 'post_status', 'publish');
    $auto_refresh_pending = (int) ovesio_get_option('ovesio_options', 'auto_refresh_pending', 1);
?>

    <form method="post" action="options.php" class="ovesio-settings-form">
        <?php settings_fields('ovesio_settings'); ?>
        <?php do_settings_sections('ovesio_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="ovesio_translation_default_lang"><?php esc_html_e('Content language', 'ovesio'); ?></label>
                    <p class="description"><?php esc_html_e('Select content language', 'ovesio'); ?></p>
                </th>
                <td>
                    <label>
                        <input type="radio" name="ovesio_options[translation_default_language]" value="system" <?php checked('system', $translation_default_language); ?>> <?php esc_html_e('Content defined language', 'ovesio'); ?>
                    </label>
                    <label>
                        <input type="radio" name="ovesio_options[translation_default_language]" value="auto" <?php checked('auto', $translation_default_language); ?>> <?php esc_html_e('Auto detect', 'ovesio'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ovesio_translation_workflow"><?php esc_html_e('Select Workflow', 'ovesio'); ?></label>
                    <p class="description"><?php esc_html_e('Workflow for new translations', 'ovesio'); ?></p>
                </th>
                <td>
                    <select id="ovesio_translation_workflow" name="ovesio_options[translation_workflow]">
                        <option value=""><?php esc_html_e('- no workflow selected -', 'ovesio'); ?></option>
                        <?php foreach($workflows->data as $workflow) {
                            if($workflow->type !== 'translate')
                                continue;
                            ?>
                            <option value="<?php echo esc_attr($workflow->id); ?>" <?php selected($translation_workflow, $workflow->id); ?>><?php echo esc_html($workflow->name); ?></option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Translate to', 'ovesio'); ?></label>
                    <p class="description"><?php
                    /* translators: %s is the URL to the Polylang languages admin page. */
                    $message = __( 'Select the languages you want your system to translate to. In order to enable new languages you need to add new languages in <a target="_blank" href="%s">Polylang language</a> section.', 'ovesio' );

                    echo wp_kses_post( sprintf( $message, esc_url( admin_url( 'admin.php?page=mlang' ) ) ) );
                    ?></p>
                </th>
                <td>
                    <div style="width:100%; display:block">
                    <?php foreach($languages->data as $language) {
                        $pll_code = ovesio_polylang_code_conversion($language->code);
                        $disabled = !in_array($pll_code, $system_languages) ? 'disabled' : '';
                    ?>
                        <label style="width:25%;display:block;float:left">
                            <input type="checkbox" name="ovesio_options[translation_to][]" value="<?php echo esc_attr($language->code); ?>" <?php checked(in_array($language->code, (array) $translation_to), true); echo esc_attr($disabled); ?>>

                            <img src="<?php echo plugins_url( 'admin/assets/flags/' . ovesio_lang_to_country_code($language->code) . '.png', OVESIO_ADMIN_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" width="16" height="11" style="width: 16px; height: 11px;">
                            <?php echo esc_html($language->name); ?>
                        </label>
                    <?php } ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ovesio_post_status"><?php esc_html_e('Translated Post Status', 'ovesio'); ?></label>
                </th>
                <td>
                    <select id="ovesio_post_status" name="ovesio_options[post_status]">
                        <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish', 'ovesio'); ?></option>
                        <option value="pending" <?php selected($post_status, 'pending'); ?>><?php esc_html_e('Pending Review', 'ovesio'); ?></option>
                        <option value="draft" <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Draft', 'ovesio'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Status for new translated posts or pages', 'ovesio'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ovesio_auto_refresh_pending"><?php esc_html_e('Auto refresh pending list', 'ovesio'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="ovesio_auto_refresh_pending" name="ovesio_options[auto_refresh_pending]" value="1" <?php checked(1, $auto_refresh_pending); ?>>
                        <?php esc_html_e('Refresh list pages every 30 seconds when pending translations exist', 'ovesio'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p class="description">
            <?php esc_html_e('Here\'s a complete tutorial on how to get the most out of the', 'ovesio'); ?>
            <a target="_blank" href='https://ovesio.com/blog/ovesio-ai-translation-wordpress-plugin'><?php esc_html_e('Ovesio WordPress Plugin', 'ovesio'); ?></a>
        </p>
        <?php submit_button(); ?>
    </form>
<?php
}
