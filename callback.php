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

function ovesio_wp_post_callback($type, $id, $callback)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'ovesio_list';
    $target_lang = $callback->to;

   /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    $query = $wpdb->prepare(
        /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        "SELECT * FROM {$table_name} WHERE resource = %s AND resource_id = %d AND lang = %s AND translate_id = %d AND content_id IS NULL",
        $type,
        $id,
        $target_lang,
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

            $new_tags = [];
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

                //Check if it's an update
                if (!isset($tag_translation[$target_lang])) {
                    $new_term = wp_insert_term($name, $tag_type, $term);

                    $new_term_id = (is_array($new_term) && !empty($new_term['term_id'])) ? $new_term['term_id'] : $new_term->term_id;
                    if (is_wp_error($new_term)) {
                        if(!empty($new_term->error_data['term_exists'])) {
                            $new_term_id = $new_term->error_data['term_exists'];
                        } else {
                            continue;
                        }
                    }

                    // Set language
                    if (function_exists('pll_set_term_language')) {
                        pll_set_term_language($new_term_id, $target_lang);
                    }

                    // Get existing translations
                    if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
                        if (!isset($tag_translation[$target_lang])) {
                            // Add the new translation
                            $tag_translation[$target_lang] = $new_term_id;
                            // Save the updated translations
                            pll_save_term_translations($tag_translation);
                        }
                    }

                    $new_tags[] = $name;
                }
            }

            //Add tags to post
            if($new_tags) {
                wp_set_post_tags($new_post_id, $new_tags, true);
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

            // Add the new translation
            if (!isset($translations[$target_lang])) {
                $translations[$target_lang] = $new_post_id;
                // Save the updated translations
                pll_save_post_translations($translations);
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
            if (!empty(ovesio_tags_relations($id, $target_lang))) {
                wp_set_post_tags($new_post_id, ovesio_tags_relations($id, $target_lang));
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

        // Get existing translations
        if (function_exists('pll_get_term_translations') && function_exists('pll_save_term_translations')) {
            if (!isset($translations[$target_lang])) {
                // Add the new translation
                $translations[$target_lang] = $new_term_id;
                // Save the updated translations
                pll_save_term_translations($translations);
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
