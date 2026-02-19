<?php

if (!defined('ABSPATH')) {
    exit;
}

// Register public callback url
add_filter('query_vars', 'ovesio_register_callback_query_var');
add_action('template_redirect', 'ovesio_handle_public_endpoint');

function ovesio_register_callback_query_var($vars) {
    $vars[] = 'ovesio_callback';
    $vars[] = 'security_hash';
    return $vars;
}

function ovesio_handle_public_endpoint() {
    // Check security
    $security_hash = ovesio_get_option('ovesio_api_settings', 'security_hash');
    if (get_query_var('security_hash') != $security_hash) {
        return;
    }

    if (get_query_var('ovesio_callback') == '1') {
        header('Content-Type: application/json');

        $callbackHandler = new \Ovesio\Callback\CallbackHandler();
        if($callback = $callbackHandler->handle()) {
            list($type, $id) = explode('/', $callback->ref);

            try {
                if(in_array($type, ['page', 'post', 'post_tag', 'category', 'product', 'product_cat', 'product_tag'])) {
                    ovesio_wp_post_callback($type, $id, $callback);
                } else {
                    throw new Exception('Unsupported resource type: '. $type);
                }
            } catch (Exception $e) {
                $callbackHandler->fail($e->getMessage());
            }

            $callbackHandler->success();
        } else {
            $callbackHandler->fail();
        }
        exit();
    }
}

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
        $lang = sanitize_key((string) $lang);
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

    $resource = sanitize_key((string) $resource);
    $resource_id = (int) $resource_id;
    $translate_id = (int) $translate_id;
    if ($resource === '' || $resource_id <= 0 || $translate_id <= 0) {
        return [];
    }

    $table_name = $wpdb->prefix . 'ovesio_list';
    $query = $wpdb->prepare(
        "SELECT lang, content_id FROM {$table_name} WHERE resource = %s AND resource_id = %d AND translate_id = %d AND content_id IS NOT NULL",
        $resource,
        $resource_id,
        $translate_id
    );

    $rows = $wpdb->get_results($query);
    if (!is_array($rows)) {
        return [];
    }

    $translations = [];
    foreach ($rows as $saved_row) {
        $lang = isset($saved_row->lang) ? ovesio_normalize_polylang_slug($saved_row->lang) : '';
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

    $table_name = $wpdb->prefix . 'ovesio_list';
    $target_lang_ovesio = strtolower(trim((string) $callback->to));
    $target_lang = ovesio_normalize_polylang_slug($target_lang_ovesio);

   /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    $query = $wpdb->prepare(
        /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
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

        // Get existing translations
        $translations = pll_get_post_translations($id);

        //Check if it's an update
        if (isset($translations[$target_lang])) {
            $post['ID'] = $translations[$target_lang];
        }

        $post['post_status'] = $post_status;
        $yoast = $elementor = $tags = [];
        foreach($callback->content as $content) {
            if(substr($content->key, 0, 2) == 't:') { //tags
                $content->key = substr($content->key, 2, strlen($content->key));
                $tags[$content->key] = $content->value;
            } elseif(substr($content->key, 0, 2) == 'e:') { //elementor
                $content->key = substr($content->key, 2, strlen($content->key));
                $elementor[] = (array) $content;
            } elseif(substr($content->key, 0, 2) == 'y:') { //yoast
                $content->key = substr($content->key, 2, strlen($content->key));
                $yoast[$content->key] = $content->value;
            } else {
                $post[$content->key] = $content->value;
            }
        }

        // if(empty($post['ID'])) {
        //     $new_post_id = wp_update_post($post);
        // } else {
        $post['post_name'] = sanitize_title($post['post_title']);

        $new_post_id = wp_insert_post($post);
        // }

        //Update tags if exists
        if(!empty($tags) && in_array($type, ['post', 'product'])) {
            $tag_type = $type . '_tag';

            $new_tag_ids = [];
            foreach($tags as $tag_id => $tag_value) {
                $term = (array) get_term($tag_id, $tag_type);
                if (is_wp_error($term) || !$term) {
                    continue;
                }
                unset($term['name']);
                unset($term['description']);

                $tag_translation = pll_get_term_translations($tag_id);

                $name = esc_html(wp_unslash($tag_value));

                // Create the translated term
                $parent_cat = ovesio_parent_category_relations($tag_id, $target_lang);

                // Check if the Term exists in the target language To append new lang sulg
                $term_exists = term_exists($name, $tag_type, $parent_cat);
                // If the term already exists, append the language slug to the title
                // if(!empty($term_exists)) {
                //     $existing_term = get_term($term_exists['term_id'], $tag_type);
                //     $term['slug'] = $existing_term->slug;
                // } else {
                    $term['slug'] = sanitize_title($name);
                // }

                $target_tag_id = isset($tag_translation[$target_lang]) ? (int) $tag_translation[$target_lang] : 0;

                //Check if it's an update
                if ($target_tag_id <= 0) {
                    $new_term = wp_insert_term($name, $tag_type, $term);

                    $new_term_id = (is_array($new_term) && !empty($new_term['term_id'])) ? $new_term['term_id'] : $new_term->term_id;
                    if (is_wp_error($new_term)) {
                        if(!empty($new_term->error_data['term_exists'])) {
                            $new_term_id = $new_term->error_data['term_exists'];
                        } else {
                            continue;
                        }
                    }

                    $target_tag_id = (int) $new_term_id;

                    // Set language
                    if (function_exists('pll_set_term_language')) {
                        pll_set_term_language($target_tag_id, $target_lang);
                    }

                    // Keep tag translations in one Polylang group when multiple callbacks run together.
                    if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
                        $lock_key = ovesio_acquire_translation_lock('term:' . $callback->id . ':' . $tag_type . ':' . $tag_id, 12);
                        try {
                            $source_lang = function_exists('pll_get_term_language') ? pll_get_term_language($tag_id, 'slug') : '';

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

            //Add tags to post
            if($new_tag_ids) {
                $new_tag_ids = array_values(array_unique(array_map('intval', $new_tag_ids)));
                if ($type === 'product') {
                    wp_set_object_terms($new_post_id, $new_tag_ids, 'product_tag', false);
                } else {
                    wp_set_post_terms($new_post_id, $new_tag_ids, 'post_tag', false);
                }
            }
        }

        // Check if the post was created successfully
        if (is_wp_error($new_post_id)) {
            wp_send_json_error('Post creation failed: ' . $new_post_id->get_error_message(), 500);
        }

        // Copy custom fields
        $meta = get_post_meta($id);
        if($elementor && !empty($meta['_elementor_data'][0])) {
            // $doc = \Elementor\Plugin::$instance->documents->get( $id );
            // if ( $doc && $doc->is_built_with_elementor() ) {
                $raw_data = json_decode($meta['_elementor_data'][0], true);
                if($raw_data) {
                    $elementor_meta_update = ovesio_apply_translations_to_elements($raw_data, $elementor);
                    add_post_meta($new_post_id, '_elementor_data', $elementor_meta_update);
                }
            // }

            // if(!empty($post['_elementor_page_settings']))
            // {
            //     update_post_meta($new_post_id, '_elementor_page_settings', $post['_elementor_page_settings']);
            // }
        }

        if($yoast) {
            foreach($yoast as $y_key => $y_value)
            {
                update_post_meta($new_post_id, $y_key, $y_value);
            }
        }

        if (!empty($meta)) {
            foreach ($meta as $key => $values) {
                // Skip Polylang and WordPress core fields if needed
                if (in_array($key, [
                    '_edit_lock',
                    '_edit_last',
                    '_thumbnail_id',
                    '_wp_old_slug',
                    '_icl_lang_duplicate_of',
                    '_polylang_translation',
                    '_pll_language',
                    '_pll_trid'
                ])) {
                    continue;
                }
                foreach ($values as $value) {
                    add_post_meta($new_post_id, $key, maybe_unserialize($value));
                }
            }
        }

        // Copy featured image
        $thumbnail_id = get_post_thumbnail_id($id);
        if ($thumbnail_id && get_post($thumbnail_id)) {
            // Make sure the image exists before setting
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        if (function_exists('pll_set_post_language')) {
            // Set the language for the new post
            pll_set_post_language($new_post_id, $target_lang);

            // Serialize translation-group writes to avoid callback race conditions.
            $lock_key = ovesio_acquire_translation_lock('post:' . $callback->id . ':' . $id, 12);
            try {
                $source_lang = function_exists('pll_get_post_language') ? pll_get_post_language($id, 'slug') : '';

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

            // Categories relations
            $cat_replations = ovesio_categories_relations($id, $target_lang, $type);
            if (!empty($cat_replations)) {
                if ($type != 'post' && $type != 'page') {
                    foreach ($cat_replations as $cat) {
                        $catType = get_term($cat)->taxonomy;
                        wp_set_object_terms(
                            $new_post_id,
                            [$cat],
                            $catType,
                            true
                        );
                    }
                } else {
                    wp_set_post_categories($new_post_id, ovesio_categories_relations($id, $target_lang, $type));
                }
            }

            // Tags relations
            $tags_taxonomy = ($type === 'product') ? 'product_tag' : 'post_tag';
            $tags_relations = ovesio_tags_relations($id, $target_lang, $tags_taxonomy);
            if (!empty($tags_relations)) {
                if ($type === 'product') {
                    wp_set_object_terms($new_post_id, $tags_relations, 'product_tag', false);
                } else {
                    wp_set_post_terms($new_post_id, $tags_relations, 'post_tag', false);
                }
            }

            // Set product Type and variations for WooCommerce products
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

        // Create the translated term
        $parent_cat = ovesio_parent_category_relations($id, $target_lang);

        // Check if the Term exists in the target language To append new lang sulg
        $term_exists = term_exists($name, $type, $parent_cat);
        // If the term already exists, append the language slug to the title
        if(!empty($term_exists)) {
            $existing_term = get_term($term_exists['term_id'], $type);
            $term['slug'] = $existing_term->slug;
        } else {
            $term['slug'] = sanitize_title($name);
        }

        //Check if it's an update
        if (isset($translations[$target_lang])) {
            $term['name'] = $name;
            $new_term = wp_update_term($translations[$target_lang], $type, $term);
        } else {
            $new_term = wp_insert_term($name, $type, $term);
        }

        $new_term_id = (is_array($new_term) && !empty($new_term['term_id'])) ? $new_term['term_id'] : $new_term->term_id;

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

        // Set language
        if (function_exists('pll_set_term_language')) {
            pll_set_term_language($new_term_id, $target_lang);
        }

        // Keep term translations in a single Polylang group even when callbacks run in parallel.
        if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
            $lock_key = ovesio_acquire_translation_lock('term:' . $callback->id . ':' . $type . ':' . $id, 12);
            try {
                $source_lang = function_exists('pll_get_term_language') ? pll_get_term_language($id, 'slug') : '';

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

    // Update table
    /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
    $wpdb->update(
        $table_name,
        [
            'translate_status' => 1,
            'content_id' => $new_post_id
        ],
        [
            'id' => $row->id,
        ],
        ['%d', '%d'],
        ['%d']
    );
}
