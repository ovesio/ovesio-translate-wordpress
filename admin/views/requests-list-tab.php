<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

function ovesio_requests_list_page()
{
    if (!class_exists('WP_List_Table')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }

    /**
     * Activity log table
     */
    class Ovesio_Requests_Table extends WP_List_Table
    {
        public function __construct()
        {
            parent::__construct([
                'singular' => 'request',
                'plural'   => 'requests',
                'ajax'     => false,
            ]);
        }

        public function get_columns()
        {
            return [
                'id'              => __('ID', 'ovesio'),
                'activity_type'   => __('Type', 'ovesio'),
                'resource'        => __('Resource', 'ovesio'),
                'lang'            => __('Language', 'ovesio'),
                'status'          => __('Status', 'ovesio'),
                'created_at'      => __('Date', 'ovesio'),
            ];
        }

        public function get_sortable_columns()
        {
            return [
                'id'         => ['id', false],
                'created_at' => ['created_at', false],
            ];
        }

        public function get_bulk_actions()
        {
            return [];
        }

        public function prepare_items()
        {
            global $wpdb;

            $table    = $wpdb->prefix . 'ovesio_list';
            $per_page = 25;
            $current  = $this->get_pagenum();
            $offset   = ($current - 1) * $per_page;

            /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
            $search        = isset($_REQUEST['s'])            ? sanitize_text_field(wp_unslash($_REQUEST['s']))            : '';
            /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
            $filter_type   = isset($_REQUEST['filter_type'])  ? sanitize_text_field(wp_unslash($_REQUEST['filter_type']))  : '';
            /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
            $filter_status = isset($_REQUEST['filter_status']) ? sanitize_text_field(wp_unslash($_REQUEST['filter_status'])) : '';

            $where_parts = [];
            $args        = [];

            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where_parts[] = '(resource LIKE %s OR lang LIKE %s OR CAST(resource_id AS CHAR) LIKE %s)';
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
            }

            if ($filter_type === 'translate') {
                $where_parts[] = 'translate_id IS NOT NULL';
            } elseif ($filter_type === 'generate_content') {
                $where_parts[] = 'generate_description_id IS NOT NULL';
            } elseif ($filter_type === 'generate_seo') {
                $where_parts[] = 'metatags_id IS NOT NULL';
            }

            if ($filter_status === 'pending') {
                $where_parts[] = '(translate_status = 0 OR generate_description_status = 0 OR metatags_status = 0)';
            } elseif ($filter_status === 'completed') {
                $where_parts[] = '(translate_status = 1 OR generate_description_status = 1 OR metatags_status = 1)';
            }

            $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

            /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
            /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
            $total = $args
                /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared */
                ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$args))
                /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                : (int) $wpdb->get_var($count_sql);

            /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $data_sql = "SELECT id, resource, resource_id, lang, translate_id, translate_status,
                                generate_description_id, generate_description_status,
                                metatags_id, metatags_status, content_id, created_at
                         FROM {$table} {$where}
                         ORDER BY id DESC
                         LIMIT %d OFFSET %d";

            $args[] = $per_page;
            $args[] = $offset;

            /* phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
            $this->items = $wpdb->get_results($wpdb->prepare($data_sql, $args), ARRAY_A);

            $columns  = $this->get_columns();
            $hidden   = [];
            $sortable = $this->get_sortable_columns();
            $this->_column_headers = [$columns, $hidden, $sortable];

            $this->set_pagination_args([
                'total_items' => $total,
                'per_page'    => $per_page,
                'total_pages' => ceil($total / $per_page),
            ]);
        }

        /** Returns an edit link for a given resource type + ID */
        private function resource_edit_link($type, $id)
        {
            $post_types = ['post', 'page', 'product', 'elementor_library'];
            $term_types = ['category', 'post_tag', 'product_cat', 'product_tag'];

            if (in_array($type, $post_types)) {
                return admin_url('post.php?post=' . (int) $id . '&action=edit');
            }

            if (in_array($type, $term_types)) {
                $tax = $type === 'category' ? 'category' : $type;
                return admin_url('edit-tags.php?taxonomy=' . $tax . '&tag_ID=' . (int) $id);
            }

            return '';
        }

        /** Render activity type badge */
        private function type_badge($type)
        {
            $map = [
                'translate'          => ['label' => esc_html__('Translate', 'ovesio'),      'class' => 'ov-badge-primary'],
                'generate_content'   => ['label' => esc_html__('Content', 'ovesio'),        'class' => 'ov-badge-success'],
                'generate_seo'       => ['label' => esc_html__('SEO', 'ovesio'),            'class' => 'ov-badge-warning'],
            ];
            $info = isset($map[$type]) ? $map[$type] : ['label' => esc_html($type), 'class' => 'ov-badge-secondary'];
            return '<span class="ov-badge ' . esc_attr($info['class']) . '">' . esc_html($info['label']) . '</span>';
        }

        /** Render status for a given activity type */
        private function status_badge($item, $activity_type)
        {
            if ($activity_type === 'translate') {
                $status = (int) $item['translate_status'];
            } elseif ($activity_type === 'generate_content') {
                $status = (int) $item['generate_description_status'];
            } else {
                $status = (int) $item['metatags_status'];
            }

            if ($status === 1) {
                return '<span class="ov-badge ov-badge-success">' . esc_html__('Completed', 'ovesio') . '</span>';
            } else {
                return '<span class="ov-badge ov-badge-warning">' . esc_html__('Pending', 'ovesio') . '</span>';
            }
        }

        /** Determine which activity type this row represents */
        private function detect_activity_type($item)
        {
            if (!empty($item['translate_id'])) {
                return 'translate';
            } elseif (!empty($item['generate_description_id'])) {
                return 'generate_content';
            } elseif (!empty($item['metatags_id'])) {
                return 'generate_seo';
            }
            return 'translate';
        }

        public function column_default($item, $column_name)
        {
            $activity_type = $this->detect_activity_type($item);

            switch ($column_name) {

                case 'activity_type':
                    return $this->type_badge($activity_type);

                case 'status':
                    return $this->status_badge($item, $activity_type);

                case 'resource':
                    $url = $this->resource_edit_link($item['resource'], $item['resource_id']);
                    $label = esc_html($item['resource'] . ' #' . $item['resource_id']);
                    if ($url) {
                        return '<a href="' . esc_url($url) . '" target="_blank">' . $label . '</a>';
                    }
                    return $label;

                case 'lang':
                    return $item['lang'] ? esc_html(strtoupper($item['lang'])) : '—';

                case 'created_at':
                    return esc_html($item['created_at'] ? date_i18n(get_option('date_format') . ' H:i', strtotime($item['created_at'])) : '—');

                default:
                    return esc_html($item[$column_name] ?? '—');
            }
        }

        public function no_items()
        {
            echo esc_html__('No activity found.', 'ovesio');
        }

        /** Render filters above the table */
        protected function extra_tablenav($which)
        {
            if ($which !== 'top') return;

            /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
            $filter_type   = isset($_REQUEST['filter_type'])   ? sanitize_text_field(wp_unslash($_REQUEST['filter_type']))   : '';
            /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
            $filter_status = isset($_REQUEST['filter_status']) ? sanitize_text_field(wp_unslash($_REQUEST['filter_status'])) : '';
            ?>
            <div class="alignleft actions" style="display:flex;gap:8px;align-items:center;">
                <select name="filter_type" id="filter_type">
                    <option value=""><?php esc_html_e('All types', 'ovesio'); ?></option>
                    <option value="translate"        <?php selected($filter_type, 'translate'); ?>><?php esc_html_e('Translate', 'ovesio'); ?></option>
                    <option value="generate_content" <?php selected($filter_type, 'generate_content'); ?>><?php esc_html_e('Generate Content', 'ovesio'); ?></option>
                    <option value="generate_seo"     <?php selected($filter_type, 'generate_seo'); ?>><?php esc_html_e('Generate SEO', 'ovesio'); ?></option>
                </select>
                <select name="filter_status" id="filter_status">
                    <option value=""><?php esc_html_e('All statuses', 'ovesio'); ?></option>
                    <option value="pending"   <?php selected($filter_status, 'pending'); ?>><?php esc_html_e('Pending', 'ovesio'); ?></option>
                    <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php esc_html_e('Completed', 'ovesio'); ?></option>
                </select>
                <?php submit_button(__('Filter', 'ovesio'), 'button', 'filter_action', false); ?>
            </div>
            <?php
        }
    }

    // --------------------------------------------------------
    // Render page
    // --------------------------------------------------------

    $table = new Ovesio_Requests_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Activity Log', 'ovesio'); ?></h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=ovesio')); ?>" class="page-title-action">
            &larr; <?php esc_html_e('Dashboard', 'ovesio'); ?>
        </a>
        <hr class="wp-header-end">

        <form method="get">
            <input type="hidden" name="page" value="ovesio_requests">
            <?php $table->search_box(__('Search', 'ovesio'), 'ovesio-requests'); ?>
            <?php $table->display(); ?>
        </form>
    </div>
    <?php
}
