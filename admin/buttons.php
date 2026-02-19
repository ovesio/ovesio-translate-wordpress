<?php

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================
// Row Actions (Translation + Generate Content + Generate SEO)
// ============================================================

add_filter('post_row_actions',           'ovesio_add_action_buttons', 10, 2);
add_filter('page_row_actions',           'ovesio_add_action_buttons', 10, 2);
add_filter('post_tag_row_actions',       'ovesio_add_action_buttons', 10, 2);
add_filter('category_row_actions',       'ovesio_add_action_buttons', 10, 2);
add_filter('product_cat_row_actions',    'ovesio_add_action_buttons', 10, 2);
add_filter('product_tag_row_actions',    'ovesio_add_action_buttons', 10, 2);
add_filter('elementor_library_row_actions', 'ovesio_add_action_buttons', 10, 2);

function ovesio_add_action_buttons($actions, $post)
{
    global $wpdb;

    if (!function_exists('pll_the_languages') || !function_exists('pll_get_post_language')) {
        return $actions;
    }

    if (!current_user_can('edit_posts')) {
        return $actions;
    }

    $api_key = ovesio_get_option('ovesio_api_settings', 'api_key');
    if (empty($api_key)) {
        return $actions;
    }

    $table_name = $wpdb->prefix . 'ovesio_list';

    if (isset($post->ID)) {
        $sourceLang = pll_get_post_language($post->ID);
        $type       = $post->post_type;
        $id         = $post->ID;
    } elseif (isset($post->term_id)) {
        $sourceLang = pll_get_term_language($post->term_id);
        $type       = $post->taxonomy;
        $id         = $post->term_id;
    } else {
        return $actions;
    }

    $options = get_option('ovesio_options', []);

    // -------------------------------------------------------
    // Translation buttons
    // -------------------------------------------------------

    $translation_to = ovesio_get_option('ovesio_options', 'translation_to');
    if ($translation_to) {
        // Check which resource types are configured for translation
        $translate_enabled = true;
        if (in_array($type, ['post']) && empty($options['translate_for_posts'])) $translate_enabled = false;
        if (in_array($type, ['page']) && empty($options['translate_for_pages'])) $translate_enabled = false;
        if (in_array($type, ['product']) && empty($options['translate_for_products'])) $translate_enabled = false;
        if (in_array($type, ['category']) && empty($options['translate_for_categories'])) $translate_enabled = false;
        if (in_array($type, ['product_cat']) && empty($options['translate_for_product_cats'])) $translate_enabled = false;

        if ($translate_enabled) {
            $languages = pll_the_languages([
                'raw'           => true,
                'hide_if_empty' => false,
                'show_flags'    => true,
            ]);
            $lang_slug    = [];
            $lang_flag    = [];
            $lang_name    = [];
            $pending_lang = [];

            foreach ($languages as $lang) {
                /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
                $query = $wpdb->prepare(
                    "SELECT `translate_status` FROM {$table_name} WHERE resource = %s AND resource_id = %d AND lang = %s ORDER BY id DESC LIMIT 1",
                    $type,
                    $id,
                    ovesio_polylang_code_conversion($lang['slug'])
                );

                /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                $translation_exists = $wpdb->get_row($query);

                if(in_array($type, ['post', 'page', 'product', 'elementor_library'])){
                    $post_lang = pll_get_post($id, $lang['slug']);
                } else {
                    $post_lang = pll_get_term($id, $lang['slug']);
                }

                $pending_translations = ($translation_exists && $translation_exists->translate_status != 1);

                if(!$post_lang && $pending_translations){
                    $pending_lang[] = $lang['flag'];
                }

                if (!$post_lang && !$pending_translations) {
                    $lang_flag[] = $lang['flag'];
                    $lang_slug[] = $lang['slug'];
                    $lang_name[] = $lang['name'];
                }
            }

            $item = "type={$type}&id={$id}";

            $entries = array_map(
                function ($slug, $flag, $name) use ($item, $sourceLang) {
                    return '<a class="ovesio-translate-ajax-request" title="' . esc_attr($name) . '" href="' . admin_url("admin-ajax.php?action=ovesio_translate_content&" . $item . "&source=" . $sourceLang . "&slug=" . $slug . "&_wpnonce=" . wp_create_nonce('ovesio-nonce')) . '" style="margin:0 4px;">' . $flag . '</a>';
                },
                $lang_slug,
                $lang_flag,
                $lang_name
            );

            if($pending_lang) {
                $pending_lang = implode(' ', $pending_lang);
                $actions['pending_translations'] = '<span class="new-translation ovesio-pending-translations"><span class="ovesio-pending-label">' . esc_html__('Pending translations', 'ovesio') . '</span>: ' . $pending_lang . '</span>';
            }

            if($entries) {
                $actions['translate_all'] = '<span class="translate-all"><a class="ovesio-translate-ajax-request" href="' . admin_url("admin-ajax.php?action=ovesio_translate_content&" . $item . "&source=" . $sourceLang . "&slug=" . implode(',', $lang_slug) . "&_wpnonce=" . wp_create_nonce('ovesio-nonce')) . '">' . esc_html__('Translate All', 'ovesio') . '</a></span>';

                $finalLang = implode('', $entries);
                $actions['new_translation'] = '<span class="new-translation"><a>' . esc_html__('Translate', 'ovesio') . ':</a> ' . $finalLang . '</span>';
            }
        }
    }

    // -------------------------------------------------------
    // Generate Content button (only for posts/pages/products/categories)
    // -------------------------------------------------------

    if (in_array($type, ['post', 'page', 'product', 'category', 'product_cat'])) {
        $gc_settings = get_option('ovesio_generate_content_settings', []);
        $gc_enabled  = !empty($gc_settings['status']);
        $gc_for_type = false;

        if ($type === 'post' && !empty($gc_settings['for_posts']))           $gc_for_type = true;
        if ($type === 'page' && !empty($gc_settings['for_pages']))           $gc_for_type = true;
        if ($type === 'product' && !empty($gc_settings['for_products']))     $gc_for_type = true;
        if (in_array($type, ['category', 'product_cat']) && !empty($gc_settings['for_categories'])) $gc_for_type = true;

        if ($gc_enabled && $gc_for_type) {
            // Check for pending
            /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $gc_pending = $wpdb->get_row($wpdb->prepare(
                "SELECT generate_description_status FROM {$table_name} WHERE resource = %s AND resource_id = %d AND generate_description_id IS NOT NULL ORDER BY id DESC LIMIT 1",
                $type, $id
            ));

            if ($gc_pending && $gc_pending->generate_description_status == 0) {
                $actions['generate_description_pending'] = '<span class="ovesio-pending-translations"><span class="ovesio-pending-label">' . esc_html__('Content: Pending', 'ovesio') . '</span></span>';
            } else {
                $gc_url = admin_url("admin-ajax.php?action=ovesio_generate_description&type={$type}&id={$id}&_wpnonce=" . wp_create_nonce('ovesio-nonce'));
                $actions['generate_description'] = '<a class="ovesio-generate-ajax-request" href="' . esc_url($gc_url) . '" title="' . esc_attr__('Generate AI Description', 'ovesio') . '">' . esc_html__('Generate Content', 'ovesio') . '</a>';
            }
        }
    }

    // -------------------------------------------------------
    // Generate SEO button (only for posts/pages/products/categories)
    // -------------------------------------------------------

    if (in_array($type, ['post', 'page', 'product', 'category', 'product_cat', 'post_tag', 'product_tag'])) {
        $seo_settings = get_option('ovesio_generate_seo_settings', []);
        $seo_enabled  = !empty($seo_settings['status']);
        $seo_for_type = false;

        if ($type === 'post' && !empty($seo_settings['for_posts']))           $seo_for_type = true;
        if ($type === 'page' && !empty($seo_settings['for_pages']))           $seo_for_type = true;
        if ($type === 'product' && !empty($seo_settings['for_products']))     $seo_for_type = true;
        if (in_array($type, ['category', 'product_cat', 'post_tag', 'product_tag']) && !empty($seo_settings['for_categories'])) $seo_for_type = true;

        if ($seo_enabled && $seo_for_type) {
            // Check for pending
            /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $seo_pending = $wpdb->get_row($wpdb->prepare(
                "SELECT metatags_status FROM {$table_name} WHERE resource = %s AND resource_id = %d AND metatags_id IS NOT NULL ORDER BY id DESC LIMIT 1",
                $type, $id
            ));

            if ($seo_pending && $seo_pending->metatags_status == 0) {
                $actions['generate_seo_pending'] = '<span class="ovesio-pending-translations"><span class="ovesio-pending-label">' . esc_html__('SEO: Pending', 'ovesio') . '</span></span>';
            } else {
                $seo_url = admin_url("admin-ajax.php?action=ovesio_generate_seo&type={$type}&id={$id}&_wpnonce=" . wp_create_nonce('ovesio-nonce'));
                $actions['generate_seo'] = '<a class="ovesio-generate-seo-ajax-request" href="' . esc_url($seo_url) . '" title="' . esc_attr__('Generate AI SEO Meta Tags (Yoast)', 'ovesio') . '">' . esc_html__('Generate SEO', 'ovesio') . '</a>';
            }
        }
    }

    return $actions;
}

// ============================================================
// AJAX: Translation
// ============================================================

add_action('wp_ajax_ovesio_translate_content', 'ovesio_translate_content_ajax_handler');
function ovesio_translate_content_ajax_handler() {
    if (!empty($_REQUEST['type']) && !empty($_REQUEST['id'])) {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'ovesio-nonce')) {
            wp_send_json_error('Invalid nonce', 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied', 403);
        }

        if (!isset($_REQUEST['type'], $_REQUEST['slug'], $_REQUEST['source'])) {
            wp_send_json_error('Missing required parameters', 400);
        }

        $type        = sanitize_text_field(wp_unslash($_REQUEST['type']));
        $id          = (int) $_REQUEST['id'];
        $source      = sanitize_text_field(wp_unslash($_REQUEST['source']));
        $target_lang = sanitize_text_field(wp_unslash($_REQUEST['slug']));

        $request = [];
        $post    = null;

        switch($type) {
            case 'post':
            case 'page':
            case 'product':
            case 'elementor_library':
                $post = get_post($id);

                if (!$post) {
                    wp_send_json_error("Invalid {$type} ID", 400);
                }

                if (function_exists('pll_get_post_translations')) {
                    $existing_translations = pll_get_post_translations($id);
                    if (!empty($existing_translations[$target_lang])) {
                        wp_send_json_error('Translation for this language already exists', 409);
                    }
                }

                $request = [
                    ['key' => 'post_title',   'value' => $post->post_title],
                    ['key' => 'post_content', 'value' => $post->post_content],
                    ['key' => 'post_excerpt', 'value' => $post->post_excerpt],
                ];

                // Tags
                if(in_array($type, ['post', 'product'])) {
                    $tags = get_the_terms($id, $type . '_tag');
                    if (!empty($tags) && !is_wp_error($tags)) {
                        foreach ($tags as $tag) {
                            $request[] = [
                                'key'   => 't:' . $tag->term_id,
                                'value' => $tag->name,
                            ];
                        }
                    }
                }

                // Elementor
                if (did_action('elementor/loaded')) {
                    $doc = \Elementor\Plugin::$instance->documents->get($id);
                    if ($doc && $doc->is_built_with_elementor()) {
                        $raw = get_post_meta($id, '_elementor_data', true);
                        if ($raw) {
                            $raw_data = json_decode($raw, true);
                            if ($raw_data) {
                                ovesio_traverse_elements_with_id($raw_data, function($item) use(&$request) {
                                    if(!empty($item['settings']) && is_array($item['settings'])) {
                                        foreach($item['settings'] as $setting_key => $setting_value) {
                                            $request[] = [
                                                'key'   => 'e:' . $item['id'] . '/' . $setting_key,
                                                'value' => $setting_value,
                                            ];
                                        }
                                    }
                                });
                            }
                        }
                    }
                }

                // Yoast SEO
                $all_meta  = get_post_meta($id);
                $yoast_meta = array_filter($all_meta, function($key) {
                    return in_array($key, ['_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc']);
                }, ARRAY_FILTER_USE_KEY);

                foreach($yoast_meta as $meta_key => $meta_value) {
                    $request[] = [
                        'key'   => 'y:' . $meta_key,
                        'value' => isset($meta_value[0]) ? $meta_value[0] : $meta_value,
                    ];
                }

                break;

            case 'post_tag':
            case 'category':
            case 'product_cat':
            case 'product_tag':
                $post = get_term($id);

                if (!$post) {
                    wp_send_json_error("Invalid {$type} ID", 400);
                }

                if (function_exists('pll_get_term_translations')) {
                    $existing_translations = pll_get_term_translations($id);
                    if (!empty($existing_translations[$target_lang])) {
                        wp_send_json_error('Translation for this language already exists', 409);
                    }
                }

                $request = [
                    ['key' => 'name',        'value' => $post->name],
                    ['key' => 'description', 'value' => $post->description],
                ];

                break;
        }

        if(empty($request)) {
            wp_send_json_error('Invalid request', 400);
        }

        $response = ovesio_call_translation_ai($request, $source, $target_lang, $type, $id);

        if (!empty($response['errors'])) {
            wp_send_json_error($response['errors'], 500);
        } else {
            wp_send_json_success($response);
        }
    } else {
        $referrer = wp_get_referer();
        if ($referrer) {
            wp_redirect($referrer);
            exit;
        }
    }
}
