<?php

if (!defined('ABSPATH')) {
    exit;
}

// Register public callback url
add_filter('query_vars', 'ovesio_register_callback_query_var');
add_action('template_redirect', 'ovesio_handle_public_endpoint');

function ovesio_register_callback_query_var($vars) {
    $vars[] = 'ovesio_callback';
    $vars[] = 'ovesio_cron';
    $vars[] = 'security_hash';
    return $vars;
}

function ovesio_handle_public_endpoint() {
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');
    if (get_query_var('security_hash') != $security_hash) {
        return;
    }

    // Handle callback
    if (get_query_var('ovesio_callback') == '1') {
        header('Content-Type: application/json');

        $callbackHandler = new \Ovesio\Callback\CallbackHandler();
        if($callback = $callbackHandler->handle()) {
            list($type, $id) = explode('/', $callback->ref);

            try {
                $callback_type = isset($callback->type) ? strtolower(trim((string) $callback->type)) : 'translate';

                if ($callback_type === 'generate_content') {
                    if(in_array($type, ['post', 'page', 'product', 'category', 'product_cat'])) {
                        ovesio_wp_generate_description_callback($type, $id, $callback);
                    } else {
                        throw new Exception('Unsupported resource type for generate_content: ' . $type);
                    }
                } elseif ($callback_type === 'generate_seo' || $callback_type === 'metatags') {
                    if(in_array($type, ['post', 'page', 'product', 'category', 'product_cat', 'post_tag', 'product_tag'])) {
                        ovesio_wp_generate_seo_callback($type, $id, $callback);
                    } else {
                        throw new Exception('Unsupported resource type for generate_seo: ' . $type);
                    }
                } else {
                    // Default: translate
                    if(in_array($type, ['page', 'post', 'post_tag', 'category', 'product', 'product_cat', 'product_tag'])) {
                        ovesio_wp_post_callback($type, $id, $callback);
                    } else {
                        throw new Exception('Unsupported resource type: ' . $type);
                    }
                }
            } catch (Exception $e) {
                $callbackHandler->fail($e->getMessage());
                exit();
            }

            $callbackHandler->success();
        } else {
            $callbackHandler->fail();
        }
        exit();
    }

    // Handle cron via URL (external cron trigger)
    if (get_query_var('ovesio_cron') == '1') {
        header('Content-Type: application/json');
        ovesio_process_cron_queue();
        echo wp_json_encode(['success' => true, 'message' => 'Queue processed']);
        exit();
    }
}

// ============================================================
// Generate Description Callback Handler
// ============================================================

function ovesio_wp_generate_description_callback($type, $id, $callback) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';
    $id         = (int) $id;

    // Find the pending request
    /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND generate_description_id = %d AND generate_description_status = 0 ORDER BY id DESC LIMIT 1",
        $type,
        $id,
        $callback->id
    ));

    if (empty($row->id)) {
        // Try without status filter (might already have been processed)
        /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND generate_description_id = %d ORDER BY id DESC LIMIT 1",
            $type,
            $id,
            $callback->id
        ));
        if (empty($row->id)) {
            throw new Exception('Generate description request not found for id: ' . $callback->id);
        }
    }

    // Extract description from content
    $description = '';
    if (!empty($callback->content)) {
        foreach ($callback->content as $content) {
            if ($content->key === 'description' || $content->key === 'content') {
                $description = $content->value;
                break;
            }
        }
        // Fallback: take first content value
        if (empty($description) && !empty($callback->content[0]->value)) {
            $description = $callback->content[0]->value;
        }
    }

    if (!empty($description)) {
        if (in_array($type, ['post', 'page', 'product'])) {
            wp_update_post([
                'ID'           => $id,
                'post_content' => wp_kses_post($description),
            ]);
        } elseif (in_array($type, ['category', 'product_cat', 'post_tag', 'product_tag'])) {
            wp_update_term($id, $type, ['description' => sanitize_textarea_field($description)]);
        }
    }

    // Update table status
    /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
    $wpdb->update(
        $table_name,
        ['generate_description_status' => 1],
        ['id' => $row->id],
        ['%d'],
        ['%d']
    );
}

// ============================================================
// Generate SEO Callback Handler
// ============================================================

function ovesio_wp_generate_seo_callback($type, $id, $callback) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';
    $id         = (int) $id;

    // Find the pending request
    /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND metatags_id = %d AND metatags_status = 0 ORDER BY id DESC LIMIT 1",
        $type,
        $id,
        $callback->id
    ));

    if (empty($row->id)) {
        /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND metatags_id = %d ORDER BY id DESC LIMIT 1",
            $type,
            $id,
            $callback->id
        ));
        if (empty($row->id)) {
            throw new Exception('Generate SEO request not found for id: ' . $callback->id);
        }
    }

    // Map content keys to Yoast SEO post meta fields
    $post_meta_map = [
        'meta_title'       => '_yoast_wpseo_title',
        'meta_description' => '_yoast_wpseo_metadesc',
        'meta_keywords'    => '_yoast_wpseo_focuskw',
        'title'            => '_yoast_wpseo_title',
        'description'      => '_yoast_wpseo_metadesc',
        'keywords'         => '_yoast_wpseo_focuskw',
    ];

    // Map content keys to Yoast SEO term meta fields
    $term_meta_map = [
        'meta_title'       => 'wpseo_title',
        'meta_description' => 'wpseo_desc',
        'title'            => 'wpseo_title',
        'description'      => 'wpseo_desc',
    ];

    if (!empty($callback->content)) {
        if (in_array($type, ['post', 'page', 'product'])) {
            foreach ($callback->content as $content) {
                $key = strtolower(trim($content->key));
                if (isset($post_meta_map[$key])) {
                    update_post_meta($id, $post_meta_map[$key], sanitize_text_field($content->value));
                }
            }
        } elseif (in_array($type, ['category', 'product_cat', 'post_tag', 'product_tag'])) {
            foreach ($callback->content as $content) {
                $key = strtolower(trim($content->key));
                if (isset($term_meta_map[$key])) {
                    update_term_meta($id, $term_meta_map[$key], sanitize_text_field($content->value));
                }
            }
        }
    }

    // Update table status
    /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
    $wpdb->update(
        $table_name,
        ['metatags_status' => 1],
        ['id' => $row->id],
        ['%d'],
        ['%d']
    );
}

// ============================================================
// Translation Callback Handler (existing)
// ============================================================

function ovesio_acquire_translation_lock($key, $timeout = 10) {
    $lock_key = 'ovesio_lock_' . md5((string) $key);
    $deadline = microtime(true) + max(1, (int) $timeout);

    while (microtime(true) < $deadline) {
        if (add_option($lock_key, time(), '', false)) {
            return $lock_key;
        }
        usleep(200000);
    }

    return false;
}

function ovesio_release_translation_lock($lock_key) {
    if (!empty($lock_key)) {
        delete_option($lock_key);
    }
}

function ovesio_merge_translation_map(array $base, array $extra) {
    foreach ($extra as $lang => $entity_id) {
        $lang      = sanitize_key((string) $lang);
        $entity_id = (int) $entity_id;

        if ($lang === '' || $entity_id <= 0) {
            continue;
        }

        $base[$lang] = $entity_id;
    }

    return $base;
}

function ovesio_collect_post_translations($post_id) {
    if (!function_exists('pll_get_post_translations')) {
        return [];
    }

    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }

    $translations = pll_get_post_translations($post_id);
    if (!is_array($translations)) {
        $translations = [];
    }

    foreach (array_values($translations) as $translated_id) {
        $translated_id = (int) $translated_id;
        if ($translated_id <= 0) {
            continue;
        }

        $nested = pll_get_post_translations($translated_id);
        if (is_array($nested)) {
            $translations = ovesio_merge_translation_map($translations, $nested);
        }
    }

    return $translations;
}

function ovesio_collect_term_translations($term_id) {
    if (!function_exists('pll_get_term_translations')) {
        return [];
    }

    $term_id = (int) $term_id;
    if ($term_id <= 0) {
        return [];
    }

    $translations = pll_get_term_translations($term_id);
    if (!is_array($translations)) {
        $translations = [];
    }

    foreach (array_values($translations) as $translated_id) {
        $translated_id = (int) $translated_id;
        if ($translated_id <= 0) {
            continue;
        }

        $nested = pll_get_term_translations($translated_id);
        if (is_array($nested)) {
            $translations = ovesio_merge_translation_map($translations, $nested);
        }
    }

    return $translations;
}

function ovesio_collect_request_translations($resource, $resource_id, $translate_id) {
    global $wpdb;

    $resource     = sanitize_key((string) $resource);
    $resource_id  = (int) $resource_id;
    $translate_id = (int) $translate_id;
    if ($resource === '' || $resource_id <= 0 || $translate_id <= 0) {
        return [];
    }

    $table_name = $wpdb->prefix . 'ovesio_list';
    $query      = $wpdb->prepare(
        /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        "SELECT lang, content_id FROM {$table_name} WHERE resource = %s AND resource_id = %d AND translate_id = %d AND content_id IS NOT NULL",
        $resource,
        $resource_id,
        $translate_id
    );

    /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
    $rows = $wpdb->get_results($query);
    if (!is_array($rows)) {
        return [];
    }

    $translations = [];
    foreach ($rows as $saved_row) {
        $lang       = isset($saved_row->lang) ? ovesio_normalize_polylang_slug($saved_row->lang) : '';
        $content_id = isset($saved_row->content_id) ? (int) $saved_row->content_id : 0;
        if ($lang === '' || $content_id <= 0) {
            continue;
        }
        $translations[$lang] = $content_id;
    }

    return $translations;
}

function ovesio_wp_post_callback($type, $id, $callback)
{
    global $wpdb;

    $table_name          = $wpdb->prefix . 'ovesio_list';
    $target_lang_ovesio  = strtolower(trim((string) $callback->to));
    $target_lang         = ovesio_normalize_polylang_slug($target_lang_ovesio);

    /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    $query = $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND lang = %s AND translate_id = %d AND content_id IS NULL",
        $type,
        $id,
        $target_lang_ovesio,
        $callback->id
    );

    /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
    $row = $wpdb->get_row( $query );

    if(empty($row->id)) {
        throw new Exception('Ovesio request not found!');
    }

    $post_status = ovesio_get_option('ovesio_options', 'post_status', 'publish');

    if(in_array($type, ['post', 'page', 'product'])) {
        $post = (array) get_post($id);
        unset($post['ID']);

        if (is_wp_error($post) || !$post) {
            wp_send_json_error('Post not found.', 404);
        }

        $translations = pll_get_post_translations($id);

        if (isset($translations[$target_lang])) {
            $post['ID'] = $translations[$target_lang];
        }

        $post['post_status'] = $post_status;
        $yoast = $elementor = $tags = [];
        foreach($callback->content as $content) {
            if(substr($content->key, 0, 2) == 't:') {
                $content->key   = substr($content->key, 2, strlen($content->key));
                $tags[$content->key] = $content->value;
            } elseif(substr($content->key, 0, 2) == 'e:') {
                $content->key   = substr($content->key, 2, strlen($content->key));
                $elementor[]    = (array) $content;
            } elseif(substr($content->key, 0, 2) == 'y:') {
                $content->key   = substr($content->key, 2, strlen($content->key));
                $yoast[$content->key] = $content->value;
            } else {
                $post[$content->key] = $content->value;
            }
        }

        $post['post_name'] = sanitize_title($post['post_title']);
        $new_post_id       = wp_insert_post($post);

        if(!empty($tags) && in_array($type, ['post', 'product'])) {
            $tag_type    = $type . '_tag';
            $new_tag_ids = [];
            foreach($tags as $tag_id => $tag_value) {
                $term = (array) get_term($tag_id, $tag_type);
                if (is_wp_error($term) || !$term) {
                    continue;
                }
                unset($term['name']);
                unset($term['description']);

                $tag_translation = pll_get_term_translations($tag_id);
                $name            = esc_html(wp_unslash($tag_value));
                $parent_cat      = ovesio_parent_category_relations($tag_id, $target_lang);
                $term['slug']    = sanitize_title($name);

                $target_tag_id = isset($tag_translation[$target_lang]) ? (int) $tag_translation[$target_lang] : 0;

                if ($target_tag_id <= 0) {
                    $new_term    = wp_insert_term($name, $tag_type, $term);
                    $new_term_id = (is_array($new_term) && !empty($new_term['term_id'])) ? $new_term['term_id'] : 0;
                    if (is_wp_error($new_term)) {
                        if(!empty($new_term->error_data['term_exists'])) {
                            $new_term_id = $new_term->error_data['term_exists'];
                        } else {
                            continue;
                        }
                    }

                    $target_tag_id = (int) $new_term_id;

                    if (function_exists('pll_set_term_language')) {
                        pll_set_term_language($target_tag_id, $target_lang);
                    }

                    if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
                        $lock_key = ovesio_acquire_translation_lock('term:' . $callback->id . ':' . $tag_type . ':' . $tag_id, 12);
                        try {
                            $source_lang           = function_exists('pll_get_term_language') ? pll_get_term_language($tag_id, 'slug') : '';
                            $fresh_tag_translations = ovesio_collect_term_translations($tag_id);
                            $fresh_tag_translations = ovesio_merge_translation_map($fresh_tag_translations, ovesio_collect_term_translations($target_tag_id));
                            $fresh_tag_translations = ovesio_merge_translation_map($fresh_tag_translations, ovesio_collect_request_translations($tag_type, $tag_id, $callback->id));

                            if (!empty($source_lang) && empty($fresh_tag_translations[$source_lang])) {
                                $fresh_tag_translations[$source_lang] = $tag_id;
                            }
                            $fresh_tag_translations[$target_lang] = $target_tag_id;
                            pll_save_term_translations($fresh_tag_translations);
                        } finally {
                            ovesio_release_translation_lock($lock_key);
                        }
                    }
                }

                if ($target_tag_id > 0) {
                    $new_tag_ids[] = $target_tag_id;
                }
            }

            if($new_tag_ids) {
                $new_tag_ids = array_values(array_unique(array_map('intval', $new_tag_ids)));
                if ($type === 'product') {
                    wp_set_object_terms($new_post_id, $new_tag_ids, 'product_tag', false);
                } else {
                    wp_set_post_terms($new_post_id, $new_tag_ids, 'post_tag', false);
                }
            }
        }

        if (is_wp_error($new_post_id)) {
            wp_send_json_error('Post creation failed: ' . $new_post_id->get_error_message(), 500);
        }

        $meta = get_post_meta($id);
        if($elementor && !empty($meta['_elementor_data'][0])) {
            $raw_data = json_decode($meta['_elementor_data'][0], true);
            if($raw_data) {
                $elementor_meta_update = ovesio_apply_translations_to_elements($raw_data, $elementor);
                add_post_meta($new_post_id, '_elementor_data', $elementor_meta_update);
            }
        }

        if($yoast) {
            foreach($yoast as $y_key => $y_value) {
                update_post_meta($new_post_id, $y_key, $y_value);
            }
        }

        if (!empty($meta)) {
            foreach ($meta as $key => $values) {
                if (in_array($key, [
                    '_edit_lock',
                    '_edit_last',
                    '_thumbnail_id',
                    '_wp_old_slug',
                    '_icl_lang_duplicate_of',
                    '_polylang_translation',
                    '_pll_language',
                    '_pll_trid',
                    '_ovesio_gc_processed',
                ])) {
                    continue;
                }
                foreach ($values as $value) {
                    add_post_meta($new_post_id, $key, maybe_unserialize($value));
                }
            }
        }

        $thumbnail_id = get_post_thumbnail_id($id);
        if ($thumbnail_id && get_post($thumbnail_id)) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($new_post_id, $target_lang);

            $lock_key = ovesio_acquire_translation_lock('post:' . $callback->id . ':' . $id, 12);
            try {
                $source_lang        = function_exists('pll_get_post_language') ? pll_get_post_language($id, 'slug') : '';
                $fresh_translations = ovesio_collect_post_translations($id);
                $fresh_translations = ovesio_merge_translation_map($fresh_translations, ovesio_collect_post_translations($new_post_id));
                $fresh_translations = ovesio_merge_translation_map($fresh_translations, ovesio_collect_request_translations($type, $id, $callback->id));

                if (!empty($source_lang) && empty($fresh_translations[$source_lang])) {
                    $fresh_translations[$source_lang] = $id;
                }
                $fresh_translations[$target_lang] = $new_post_id;
                pll_save_post_translations($fresh_translations);
            } finally {
                ovesio_release_translation_lock($lock_key);
            }

            $cat_relations = ovesio_categories_relations($id, $target_lang, $type);
            if (!empty($cat_relations)) {
                if ($type != 'post' && $type != 'page') {
                    foreach ($cat_relations as $cat) {
                        $catType = get_term($cat)->taxonomy;
                        wp_set_object_terms($new_post_id, [$cat], $catType, true);
                    }
                } else {
                    wp_set_post_categories($new_post_id, ovesio_categories_relations($id, $target_lang, $type));
                }
            }

            $tags_taxonomy  = ($type === 'product') ? 'product_tag' : 'post_tag';
            $tags_relations = ovesio_tags_relations($id, $target_lang, $tags_taxonomy);
            if (!empty($tags_relations)) {
                if ($type === 'product') {
                    wp_set_object_terms($new_post_id, $tags_relations, 'product_tag', false);
                } else {
                    wp_set_post_terms($new_post_id, $tags_relations, 'post_tag', false);
                }
            }

            if ($type === 'product') {
                ovesio_set_product_type($id, $new_post_id);
            }
        }
    } elseif(in_array($type, ['post_tag', 'category', 'product_cat', 'product_tag'])) {
        $term = (array) get_term($id, $type);
        if (is_wp_error($term) || !$term) {
            wp_send_json_error('Term not found.', 404);
        }

        $translations = pll_get_term_translations($id);

        foreach($callback->content as $content) {
            $term[$content->key] = esc_html(wp_unslash($content->value));
        }

        $name = $term['name'];
        unset($term['name']);
        unset($term['description']);

        $parent_cat  = ovesio_parent_category_relations($id, $target_lang);
        $term_exists = term_exists($name, $type, $parent_cat);

        if(!empty($term_exists)) {
            $existing_term = get_term($term_exists['term_id'], $type);
            $term['slug']  = $existing_term->slug;
        } else {
            $term['slug'] = sanitize_title($name);
        }

        if (isset($translations[$target_lang])) {
            $term['name'] = $name;
            $new_term     = wp_update_term($translations[$target_lang], $type, $term);
        } else {
            $new_term = wp_insert_term($name, $type, $term);
        }

        $new_term_id = (is_array($new_term) && !empty($new_term['term_id'])) ? $new_term['term_id'] : 0;

        if (is_wp_error($new_term)) {
            if(!empty($new_term->error_data['term_exists'])) {
                $new_term_id = $new_term->error_data['term_exists'];
            } else {
                wp_send_json_error('Term creation failed: ' . $new_term->get_error_message(), 500);
            }
        }

        if (empty($new_term_id)) {
            wp_send_json_error('Term ID not returned', 500);
        }

        $new_post_id = $new_term_id;

        if (function_exists('pll_set_term_language')) {
            pll_set_term_language($new_term_id, $target_lang);
        }

        if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
            $lock_key = ovesio_acquire_translation_lock('term:' . $callback->id . ':' . $type . ':' . $id, 12);
            try {
                $source_lang        = function_exists('pll_get_term_language') ? pll_get_term_language($id, 'slug') : '';
                $fresh_translations = ovesio_collect_term_translations($id);
                $fresh_translations = ovesio_merge_translation_map($fresh_translations, ovesio_collect_term_translations($new_term_id));
                $fresh_translations = ovesio_merge_translation_map($fresh_translations, ovesio_collect_request_translations($type, $id, $callback->id));

                if (!empty($source_lang) && empty($fresh_translations[$source_lang])) {
                    $fresh_translations[$source_lang] = $id;
                }
                $fresh_translations[$target_lang] = $new_term_id;
                pll_save_term_translations($fresh_translations);
            } finally {
                ovesio_release_translation_lock($lock_key);
            }
        }
    } else {
        wp_send_json_error('Request failed: unknown resource type ' . $type, 500);
    }

    if(!$new_post_id) {
        wp_send_json_error('Request failed: new post ID not returned', 500);
    }

    /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
    $wpdb->update(
        $table_name,
        [
            'translate_status' => 1,
            'content_id'       => $new_post_id,
        ],
        ['id' => $row->id],
        ['%d', '%d'],
        ['%d']
    );
}
