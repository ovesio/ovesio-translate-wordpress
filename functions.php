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
        'gb'    => 'en',
        'gr'    => 'el',
        'cz'    => 'cs',
        'dk'    => 'da',
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

    $code       = str_replace('_', '-', $code);
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
        'cs'    => 'cz',
        'et'    => 'ee',
        'ga'    => 'ie',
        'pt-br' => 'br',
        'sr'    => 'rs',
        'sl'    => 'si',
        'sv'    => 'se',
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

// ============================================================
// Sanitization functions
// ============================================================

function ovesio_sanitize_api_options($input)
{
    $input['api_key']       = sanitize_text_field($input['api_key'] ?? '');
    $input['api_url']       = sanitize_text_field($input['api_url'] ?? '');
    $input['security_hash'] = sanitize_text_field($input['security_hash'] ?? wp_generate_password(32, false));

    //check connection
    if(!empty($input['api_key']) && !empty($input['api_url'])) {
        try {
            $api       = new Ovesio\OvesioAI($input['api_key'], $input['api_url']);
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
    $input['translation_to']               = $input['translation_to'] ?? [];
    $translation_default_language          = sanitize_text_field($input['translation_default_language'] ?? 'system');
    $input['translation_workflow']         = sanitize_text_field($input['translation_workflow'] ?? '');
    $input['post_status']                  = sanitize_text_field($input['post_status'] ?? 'publish');
    $input['auto_refresh_pending']         = !empty($input['auto_refresh_pending']) ? 1 : 0;
    $input['translate_for_posts']          = !empty($input['translate_for_posts']) ? 1 : 0;
    $input['translate_for_pages']          = !empty($input['translate_for_pages']) ? 1 : 0;
    $input['translate_for_products']       = !empty($input['translate_for_products']) ? 1 : 0;
    $input['translate_for_categories']     = !empty($input['translate_for_categories']) ? 1 : 0;
    $input['translate_for_product_cats']   = !empty($input['translate_for_product_cats']) ? 1 : 0;

    //Remove default language
    if($translation_default_language == 'system' && function_exists('pll_default_language')){
        $system_default_language = ovesio_polylang_to_ovesio_code_conversion(pll_default_language());

        $lang_id = array_search($system_default_language, (array)$input['translation_to']);
        if(is_numeric($lang_id)) {
            unset($input['translation_to'][$lang_id]);
        }
    }

    $input['translation_default_language'] = $translation_default_language;

    return $input;
}

function ovesio_sanitize_generate_content_settings($input)
{
    return [
        'status'              => !empty($input['status']) ? 1 : 0,
        'for_posts'           => !empty($input['for_posts']) ? 1 : 0,
        'for_pages'           => !empty($input['for_pages']) ? 1 : 0,
        'for_products'        => !empty($input['for_products']) ? 1 : 0,
        'for_categories'      => !empty($input['for_categories']) ? 1 : 0,
        'min_length'          => (int) ($input['min_length'] ?? 500),
        'min_length_category' => (int) ($input['min_length_category'] ?? 300),
        'live_update'         => !empty($input['live_update']) ? 1 : 0,
        'workflow'            => sanitize_text_field($input['workflow'] ?? ''),
    ];
}

function ovesio_sanitize_generate_seo_settings($input)
{
    return [
        'status'         => !empty($input['status']) ? 1 : 0,
        'for_posts'      => !empty($input['for_posts']) ? 1 : 0,
        'for_pages'      => !empty($input['for_pages']) ? 1 : 0,
        'for_products'   => !empty($input['for_products']) ? 1 : 0,
        'for_categories' => !empty($input['for_categories']) ? 1 : 0,
        'live_update'    => !empty($input['live_update']) ? 1 : 0,
        'workflow'       => sanitize_text_field($input['workflow'] ?? ''),
    ];
}

// ============================================================
// Relations helpers
// ============================================================

function ovesio_categories_relations($id, $target_lang, $post_type = 'post') {
    $catLang = [];
    if ($post_type != 'post' && $post_type != 'page') {
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
    $term = get_term($id);
    if (!$term || is_wp_error($term)) {
        return 0;
    }
    $parent = $term->parent;
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
    $term_ids         = wp_get_post_terms((int) $id, $taxonomy, ['fields' => 'ids']);

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

// ============================================================
// Elementor helpers
// ============================================================

function ovesio_traverse_elements_with_id(array $elements, callable $callback) {
    foreach ($elements as $element) {
        if (isset($element['id'])) {
            $callback($element);
        }
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

// ============================================================
// Option helpers
// ============================================================

function ovesio_get_option($optionName, $key = null, $default = '') {
    $options = get_option($optionName, array());

    if($key) {
        return (isset($options[$key]) ? $options[$key] : $default);
    }

    return $options;
}

// ============================================================
// WooCommerce helpers
// ============================================================

function ovesio_set_product_type($productId, $newProductId) {
    if (!function_exists('wc_get_product')) {
        return;
    }
    $product = wc_get_product($productId);
    if (!$product) {
        return;
    }
    $productType = $product->get_type();
    wp_set_object_terms($newProductId, $productType, 'product_type');

    if ($productType === 'variable') {
        $source_product = wc_get_product($productId);
        $target_product = wc_get_product($newProductId);
        if ($target_product) {
            $target_product->set_attributes($source_product->get_attributes());
            $target_product->save();
        }
    }
}

// ============================================================
// Get post categories as string for AI context
// ============================================================

function ovesio_get_post_categories_string($post_id, $post_type = 'post') {
    $cat_names = [];

    if ($post_type === 'product') {
        $terms = get_the_terms($post_id, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $cat_names[] = $term->name;
            }
        }
    } else {
        $categories = get_the_category($post_id);
        if (!empty($categories)) {
            foreach ($categories as $cat) {
                $cat_names[] = $cat->name;
            }
        }
    }

    return implode(', ', $cat_names);
}

// ============================================================
// Translation API call
// ============================================================

function ovesio_call_translation_ai($callback, $source, $target, $type, $id) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';

    $ref           = $type . '/' . $id;
    $url           = ovesio_get_option('ovesio_api_settings', 'api_url', '');
    $key           = ovesio_get_option('ovesio_api_settings', 'api_key');
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');
    $workflow      = ovesio_get_option('ovesio_options', 'translation_workflow');

    $translation_default_language = ovesio_get_option('ovesio_options', 'translation_default_language', '');
    if($translation_default_language == 'auto') {
        $source = 'auto';
    }

    if($target == 'all') {
        $system_languages = PLL()->model->get_languages_list();
        $targets          = array_column($system_languages, 'slug');
    } else {
        $targets = explode(',', $target);
    }

    $to_langs = [];
    foreach($targets as $to) {
        $to_langs[] = ovesio_polylang_to_ovesio_code_conversion($to);
    }

    $response = [];
    try {
        $ovesio  = new Ovesio\OvesioAI($key, $url);
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
            list($account, $token) = explode(':', $key);

            $translate_id = $request->data[0]->id;
            $link         = str_replace(['api', 'v1/'], ['app', 'account/' . $account], $url) . '/app/translate_requests/' . urlencode($translate_id);

            foreach($to_langs as $lang)
            {
                /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
                $wpdb->insert($table_name, [
                    'resource'         => $type,
                    'resource_id'      => $id,
                    'lang'             => $lang,
                    'translate_id'     => $translate_id,
                    'translate_hash'   => md5(json_encode($callback)),
                    'translate_date'   => gmdate('Y-m-d H:i:s'),
                    'translate_status' => 0,
                    'link'             => $link,
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

// ============================================================
// Generate Description API call
// ============================================================

function ovesio_call_generate_description_ai($post_id, $type) {
    global $wpdb;

    $table_name    = $wpdb->prefix . 'ovesio_list';
    $url           = ovesio_get_option('ovesio_api_settings', 'api_url', '');
    $key           = ovesio_get_option('ovesio_api_settings', 'api_key');
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');
    $settings      = get_option('ovesio_generate_content_settings', []);
    $workflow      = $settings['workflow'] ?? '';

    if (empty($key) || empty($url)) {
        return ['errors' => 'API not configured'];
    }

    $post = get_post($post_id);
    if (!$post) {
        return ['errors' => 'Post not found'];
    }

    $lang = '';
    if (function_exists('pll_get_post_language')) {
        $lang = pll_get_post_language($post_id);
    }
    if (empty($lang)) {
        $lang = get_locale();
        $lang = substr($lang, 0, 2);
    }

    $categories = ovesio_get_post_categories_string($post_id, $type);

    $data = array_filter([
        ['key' => 'name',       'value' => $post->post_title],
        ['key' => 'categories', 'value' => $categories],
    ], function($item) { return !empty($item['value']); });

    $ref = $type . '/' . $post_id;

    try {
        $ovesio  = new Ovesio\OvesioAI($key, $url);
        $request = $ovesio->generateDescription()
            ->to($lang)
            ->callbackUrl(home_url('/index.php?ovesio_callback=1&security_hash=' . $security_hash));

        if (!empty($workflow)) {
            $request = $request->workflow((int) $workflow);
        }

        $request  = $request->data(array_values($data), $ref)->request();

        if (!empty($request->success)) {
            $request_id = 0;
            if (!empty($request->data)) {
                if (is_array($request->data)) {
                    $request_id = $request->data[0]->id ?? 0;
                } elseif (is_object($request->data)) {
                    $request_id = $request->data->id ?? 0;
                }
            }
            if (!$request_id && !empty($request->id)) {
                $request_id = $request->id;
            }

            /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
            $wpdb->insert($table_name, [
                'resource'                    => $type,
                'resource_id'                 => $post_id,
                'lang'                        => $lang,
                'generate_description_id'     => $request_id,
                'generate_description_hash'   => md5(json_encode($data)),
                'generate_description_date'   => gmdate('Y-m-d H:i:s'),
                'generate_description_status' => 0,
            ]);

            return ['success' => 'Generate description sent, id: ' . $request_id];
        } else {
            return ['errors' => 'Generate description failed: ' . implode(',', (array) ($request->errors ?? []))];
        }
    } catch (Exception $e) {
        return ['errors' => 'Generate description failed: ' . $e->getMessage()];
    }
}

// ============================================================
// Generate SEO API call
// ============================================================

function ovesio_call_generate_seo_ai($post_id, $type) {
    global $wpdb;

    $table_name    = $wpdb->prefix . 'ovesio_list';
    $url           = ovesio_get_option('ovesio_api_settings', 'api_url', '');
    $key           = ovesio_get_option('ovesio_api_settings', 'api_key');
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');
    $settings      = get_option('ovesio_generate_seo_settings', []);
    $workflow      = $settings['workflow'] ?? '';

    if (empty($key) || empty($url)) {
        return ['errors' => 'API not configured'];
    }

    $lang = '';

    if (in_array($type, ['post', 'page', 'product'])) {
        $post = get_post($post_id);
        if (!$post) {
            return ['errors' => 'Post not found'];
        }

        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id);
        }
        if (empty($lang)) {
            $lang = substr(get_locale(), 0, 2);
        }

        $categories   = ovesio_get_post_categories_string($post_id, $type);
        $description  = wp_strip_all_tags($post->post_content);
        $description  = mb_substr($description, 0, 1000);

        $data = array_filter([
            ['key' => 'name',        'value' => $post->post_title],
            ['key' => 'description', 'value' => $description],
            ['key' => 'categories',  'value' => $categories],
        ], function($item) { return !empty($item['value']); });

    } elseif (in_array($type, ['category', 'product_cat', 'post_tag', 'product_tag'])) {
        $term = get_term($post_id);
        if (!$term || is_wp_error($term)) {
            return ['errors' => 'Term not found'];
        }

        if (function_exists('pll_get_term_language')) {
            $lang = pll_get_term_language($post_id);
        }
        if (empty($lang)) {
            $lang = substr(get_locale(), 0, 2);
        }

        $data = array_filter([
            ['key' => 'name',        'value' => $term->name],
            ['key' => 'description', 'value' => $term->description],
        ], function($item) { return !empty($item['value']); });
    } else {
        return ['errors' => 'Unsupported resource type: ' . $type];
    }

    $ref = $type . '/' . $post_id;

    try {
        $ovesio  = new Ovesio\OvesioAI($key, $url);
        $request = $ovesio->generateSeo()
            ->to($lang)
            ->callbackUrl(home_url('/index.php?ovesio_callback=1&security_hash=' . $security_hash));

        if (!empty($workflow)) {
            $request = $request->workflow((int) $workflow);
        }

        $request = $request->data(array_values($data), $ref)->request();

        if (!empty($request->success)) {
            $request_id = 0;
            if (!empty($request->data)) {
                if (is_array($request->data)) {
                    $request_id = $request->data[0]->id ?? 0;
                } elseif (is_object($request->data)) {
                    $request_id = $request->data->id ?? 0;
                }
            }
            if (!$request_id && !empty($request->id)) {
                $request_id = $request->id;
            }

            /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
            $wpdb->insert($table_name, [
                'resource'        => $type,
                'resource_id'     => $post_id,
                'lang'            => $lang,
                'metatags_id'     => $request_id,
                'metatags_hash'   => md5(json_encode($data)),
                'metatags_date'   => gmdate('Y-m-d H:i:s'),
                'metatags_status' => 0,
            ]);

            return ['success' => 'Generate SEO sent, id: ' . $request_id];
        } else {
            return ['errors' => 'Generate SEO failed: ' . implode(',', (array) ($request->errors ?? []))];
        }
    } catch (Exception $e) {
        return ['errors' => 'Generate SEO failed: ' . $e->getMessage()];
    }
}

// ============================================================
// Cron queue processor
// ============================================================

function ovesio_process_cron_queue() {
    $api_key = ovesio_get_option('ovesio_api_settings', 'api_key');
    $api_url = ovesio_get_option('ovesio_api_settings', 'api_url');

    if (empty($api_key) || empty($api_url)) {
        return;
    }

    // Process generate content for configured post types
    $gc_settings = get_option('ovesio_generate_content_settings', []);
    if (!empty($gc_settings['status'])) {
        $post_types = [];
        if (!empty($gc_settings['for_posts']))      $post_types[] = 'post';
        if (!empty($gc_settings['for_pages']))      $post_types[] = 'page';
        if (!empty($gc_settings['for_products']))   $post_types[] = 'product';

        $min_length = (int) ($gc_settings['min_length'] ?? 500);

        foreach ($post_types as $pt) {
            $query = new WP_Query([
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'orderby'        => 'rand',
                'meta_query'     => [
                    [
                        'key'     => '_ovesio_gc_processed',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ]);

            foreach ($query->posts as $post) {
                $content_length = mb_strlen(wp_strip_all_tags($post->post_content));
                if ($content_length < $min_length) {
                    ovesio_call_generate_description_ai($post->ID, $pt);
                    update_post_meta($post->ID, '_ovesio_gc_processed', time());
                }
            }
        }

        // Process categories if configured
        if (!empty($gc_settings['for_categories'])) {
            $min_cat = (int) ($gc_settings['min_length_category'] ?? 300);
            $terms   = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'number'     => 5,
                'orderby'    => 'none',
                'meta_query' => [
                    [
                        'key'     => '_ovesio_gc_processed',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if (mb_strlen($term->description) < $min_cat) {
                        ovesio_call_generate_description_ai($term->term_id, 'category');
                        update_term_meta($term->term_id, '_ovesio_gc_processed', time());
                    }
                }
            }
        }
    }

    // Process generate SEO for configured post types
    $seo_settings = get_option('ovesio_generate_seo_settings', []);
    if (!empty($seo_settings['status'])) {
        $post_types = [];
        if (!empty($seo_settings['for_posts']))    $post_types[] = 'post';
        if (!empty($seo_settings['for_pages']))    $post_types[] = 'page';
        if (!empty($seo_settings['for_products'])) $post_types[] = 'product';

        foreach ($post_types as $pt) {
            $query = new WP_Query([
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'orderby'        => 'rand',
                'meta_query'     => [
                    'relation' => 'OR',
                    [
                        'key'     => '_yoast_wpseo_title',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_yoast_wpseo_title',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ]);

            foreach ($query->posts as $post) {
                ovesio_call_generate_seo_ai($post->ID, $pt);
            }
        }
    }
}
