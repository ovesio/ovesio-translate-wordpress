<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ovesio_pre')) {
    function ovesio_pre($var, $exit = false)
    {
        /* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r */
        echo '<pre>' . esc_html( print_r( $var, true ) ) . "</pre>\n";
        if(!empty($exit)) exit();
    }
}

function ovesio_polylang_code_conversion($code) {
    //Ovesio => Polylang
    $langs_match = [
        'gb' => 'en',
        'gr' => 'el',
        'cz' => 'cs',
        'dk' => 'da',
        'pt-br' => 'pt',
    ];

    $code = strtolower(trim((string) $code));
    if (isset($langs_match[$code])) {
        return $langs_match[$code];
    }

    return $code;
}

function ovesio_normalize_polylang_slug($code) {
    $code = strtolower(trim((string) $code));
    if ($code === '') {
        return $code;
    }

    $code = str_replace('_', '-', $code);
    $normalized = ovesio_polylang_code_conversion($code);
    if (!function_exists('pll_languages_list')) {
        return $normalized;
    }

    $available_languages = (array) pll_languages_list(['fields' => 'slug']);
    if (in_array($normalized, $available_languages, true)) {
        return $normalized;
    }

    // Fallback for locales like "fr-FR" or "fr_FR" -> "fr".
    $parts = explode('-', $normalized);
    if (!empty($parts[0]) && in_array($parts[0], $available_languages, true)) {
        return $parts[0];
    }

    return $normalized;
}

function ovesio_lang_to_country_code($code)
{
    $codes = [
        'cs' => 'cz',
        'et' => 'ee',
        'ga' => 'ie',
        'pt-br' => 'br',
        'sr' => 'rs',
        'sl' => 'si',
        'sv' => 'se',
    ];

    if(isset($codes[$code])) {
        return $codes[$code];
    }

    return $code;
}

function ovesio_polylang_to_ovesio_code_conversion($code) {
    $translation_to = (array) ovesio_get_option('ovesio_options', 'translation_to');

    if(in_array($code, $translation_to)) {
        return $code;
    }

    if($code == 'pt' && in_array('pt-br', $translation_to)) {
        $code = 'pt-br';
    }

    return $code;
}

// Sanitization functions
function ovesio_sanitize_api_options($input)
{
    $input['api_key'] = sanitize_text_field($input['api_key'] ?? '');
    $input['api_url'] = sanitize_text_field($input['api_url'] ?? '');
    $input['security_hash'] = sanitize_text_field($input['security_hash']);

    //check connection
    if(!empty($input['api_key']) && !empty($input['api_url'])) {
        try {
            $api = new Ovesio\OvesioAI($input['api_key'], $input['api_url']);
            $languages = $api->languages()->list();

            if(empty($languages->success)) {
                $input['api_key'] = '';
                $input['api_url'] = '';

                set_transient('ovesio_error', __('Invalid API connection data', 'ovesio'), 30);
            } else {
                delete_transient('ovesio_error');
                set_transient('ovesio_success', __('API connection successful', 'ovesio'), 30);
            }
        } catch (Exception $e) {
            set_transient('ovesio_error', __('Invalid API connection data', 'ovesio'), 30);
        }
    } else {
        set_transient('ovesio_error', __('Please enter a valid API Key and API Url', 'ovesio'), 30);
    }
    return $input;
}

function ovesio_sanitize_options($input)
{
    $input['translation_to'] = $input['translation_to'] ?? [];
    $translation_default_language = sanitize_text_field($input['translation_default_language']);
    $input['translation_workflow'] = sanitize_text_field($input['translation_workflow']);
    $input['post_status'] = sanitize_text_field($input['post_status']);
    $input['auto_refresh_pending'] = !empty($input['auto_refresh_pending']) ? 1 : 0;

    //Remove default language
    if($translation_default_language == 'system'){
        $system_default_language = ovesio_polylang_to_ovesio_code_conversion(pll_default_language());

        $lang_id = array_search($system_default_language, (array)$input['translation_to']);
        if(is_numeric($lang_id)) {
            unset($input['translation_to'][$lang_id]);
        }
    }

    $input['translation_default_language'] = $translation_default_language;

    return $input;
}

function ovesio_categories_relations($id, $target_lang, $post_type = 'post') {
    $catLang = [];
    if ($post_type != 'post' && $post_type != 'page') {
        // for custom post types
        $taxonomies_obj = get_object_taxonomies($post_type);
        foreach ($taxonomies_obj as $tax) {
            $term_all = wp_get_post_terms($id, $tax, [
                'fields' => 'ids',
            ]);
            foreach ($term_all as $term) {
                $translations = pll_get_term_translations($term);
                if (!empty($translations)) {
                    $catLang[] = $translations;
                }
            }
        }
    } else {
        foreach (wp_get_post_categories($id) as $cat) {
            $catLang[] = pll_get_term_translations($cat);
        }
    }

    return array_column($catLang, $target_lang);
}

function ovesio_parent_category_relations($id, $target_lang) {
    $parent = get_term($id)->parent;
    if ($parent) {
        $parentLang = pll_get_term_translations($parent);
        if (empty($parentLang[$target_lang])) {
            return 0;
        } else {
            return $parentLang[$target_lang];
        }
    } else {
        return 0;
    }
}

function ovesio_tags_relations($id, $target_lang, $taxonomy = 'post_tag') {
    $translated_terms = [];
    $term_ids = wp_get_post_terms((int) $id, $taxonomy, ['fields' => 'ids']);

    if (is_wp_error($term_ids) || empty($term_ids)) {
        return $translated_terms;
    }

    foreach ($term_ids as $term_id) {
        $translations = pll_get_term_translations((int) $term_id);
        if (!empty($translations[$target_lang])) {
            $translated_terms[] = (int) $translations[$target_lang];
        }
    }

    return array_values(array_unique($translated_terms));
}

function ovesio_traverse_elements_with_id(array $elements, callable $callback) {
    foreach ($elements as $element) {
        // Dacă are ID, îl procesăm
        if (isset($element['id'])) {
            $callback($element);
        }

        // Dacă are copii (sub-elemente), continuăm recursiv
        if (isset($element['elements']) && is_array($element['elements'])) {
            ovesio_traverse_elements_with_id($element['elements'], $callback);
        }
    }
}

function ovesio_apply_translations_to_elements(array $elements, array $translations): array {
    foreach ($elements as &$element) {
        if (isset($element['id']) && isset($element['settings']) && is_array($element['settings'])) {
            foreach ($translations as $translation) {
                [$target_id, $setting_key] = explode('/', $translation['key'], 2);

                if ($element['id'] === $target_id && array_key_exists($setting_key, $element['settings'])) {
                    $element['settings'][$setting_key] = $translation['value'];
                }
            }
        }

        if (isset($element['elements']) && is_array($element['elements'])) {
            $element['elements'] = ovesio_apply_translations_to_elements($element['elements'], $translations);
        }
    }

    return $elements;
}

// Get a setting from the plugin options
function ovesio_get_option($optionName, $key = null, $default = '') {
    $options = get_option($optionName, array());

    if($key) {
        return (isset($options[$key]) ? $options[$key] : $default);
    }

    return $options;
}

function ovesio_set_product_type($productId, $newProductId) {
    $productType = wc_get_product($productId)->get_type();
    wp_set_object_terms($newProductId, $productType, 'product_type');

    // For variable products, copy variations
    if ($productType === 'variable') {
        $source_product = wc_get_product($productId);
        $target_product = wc_get_product($newProductId);
        $target_product->set_attributes($source_product->get_attributes());
        $target_product->save();
    }
}

function ovesio_call_translation_ai($callback, $source, $target, $type, $id) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';

    $ref = $type . '/' . $id;

    $url = ovesio_get_option('ovesio_api_settings', 'api_url', '');
    $key = ovesio_get_option('ovesio_api_settings', 'api_key');
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');

    $workflow = ovesio_get_option('ovesio_options', 'translation_workflow');

    $translation_default_language = ovesio_get_option('ovesio_options', 'translation_default_language', '');
    if($translation_default_language == 'auto') {
        $source = 'auto';
    }

    if($target == 'all')
    {
        $system_languages = PLL()->model->get_languages_list();
        $system_languages = array_column($system_languages, 'slug');
    } else {
        $targets = explode(',', $target);
    }

    $to_langs = [];
    foreach($targets as $to) {
        $to_langs[] = ovesio_polylang_to_ovesio_code_conversion($to);
    }

    $response = [];
    try {
        $ovesio = new Ovesio\OvesioAI($key, $url);
        $request = $ovesio->translate()
            ->from($source)
            ->to($to_langs)
            ->useExistingTranslation(true)
            ->callbackUrl(home_url('/index.php?ovesio_callback=1&security_hash=' . $security_hash))
            ->data($callback, $ref)
            ->filterByValue();

        if(!empty($workflow)){
            $request = $request->workflow($workflow);
        }
        $request = $request->request();

        if(!empty($request->success)) {
            list($account , $token) = explode(':', $key);

            $translate_id = $request->data[0]->id;
            $link = str_replace(['api', 'v1/'], ['app', 'account/' . $account], $url) . '/app/translate_requests/' . urlencode($translate_id);

            foreach($to_langs as $lang)
            {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Safe insert with $wpdb->insert and sanitized data
                $wpdb->insert($table_name, [
                    'resource' => $type,
                    'resource_id' => $id,
                    'lang' => $lang,
                    'translate_id' => $translate_id,
                    'translate_hash' => md5(json_encode($callback)),
                    'translate_date' => gmdate('Y-m-d H:i:s'),
                    'translate_status' => 0,
                    'link' => $link
                ]);
            }

            $response['success'] = 'Translation sent successful, id:' . $translate_id;
        } else {
            $response['errors'] = 'Translation failed sending: ' . implode(',', (array) $request->errors);
        }
    } catch (Exception $e) {
        $response['errors'] = 'Translation failed: ' . $e->getMessage();
    }

    return $response;
}
