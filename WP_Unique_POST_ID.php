<?php
/**
 * Plugin Name:       WP Unique Post ID (Single File)
 * Description:       Creates a high-performance, sequential, and unique ID for custom post types, starting from 1.
 * Version:           2.1.0
 * Author:            Mehrshad Darzi
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// ============== Main Class Definition ==============

if (!class_exists('WP_Unique_POST_ID')) {

    class WP_Unique_POST_ID {

        private static $targeted_post_types = ['product', 'portfolio', 'event'];
        private static $table_name;
        private static $instance = null;

        public static function init() {
            if (self::$instance == null) {
                self::$instance = new self();
            }
        }

        private function __construct() {
            global $wpdb;
            self::$table_name = $wpdb->prefix . 'unique_post_ids';

            // Core Actions & Filters
            add_action('save_post', [$this, 'generate_custom_db_id'], 10, 2);
            add_action('init', [$this, 'add_rewrite_rules']);
            add_filter('post_type_link', [$this, 'change_permalink_structure'], 20, 2);
            
            // Query Modification
            add_filter('query_vars', [$this, 'add_query_vars']);
            add_action('pre_get_posts', [$this, 'modify_main_query']);

            // Deletion Hook
            add_action('before_delete_post', [$this, 'handle_post_deletion']);
        }
        
        /**
         * ===========================================================================
         * Plugin Activation & Deactivation
         * ===========================================================================
         */
        public static function on_activation() {
            self::create_custom_table();
            flush_rewrite_rules();
        }

        public static function on_deactivation() {
            flush_rewrite_rules();
        }

        private static function create_custom_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'unique_post_ids';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT(20) UNSIGNED NOT NULL,
                db_id BIGINT(20) UNSIGNED NOT NULL,
                post_type VARCHAR(20) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY post_id (post_id),
                UNIQUE KEY db_id_post_type (db_id, post_type)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        /**
         * ===========================================================================
         * Data Handling (Create, Read, Delete)
         * ===========================================================================
         */
        public function generate_custom_db_id($post_id, $post) {
            if (!in_array($post->post_type, self::$targeted_post_types) || $post->parent_id != 0 || $post->post_status !== 'publish') {
                return;
            }
            
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::$table_name . " WHERE post_id = %d", $post_id));
            if ($exists) {
                return;
            }

            $option_name = 'last_' . $post->post_type . '_db_id';
            
            // ======================================================================
            // ✨ CHANGE: The default value is now 0, so the first ID will be 1.
            // ======================================================================
            $last_id = get_option($option_name, 0);
            $new_id = $last_id + 1;

            $wpdb->insert(
                self::$table_name,
                ['post_id' => $post_id, 'db_id' => $new_id, 'post_type' => $post->post_type],
                ['%d', '%d', '%s']
            );

            update_option($option_name, $new_id);
        }
        
        public function handle_post_deletion($post_id) {
            global $wpdb;
            $wpdb->delete(self::$table_name, ['post_id' => $post_id], ['%d']);
        }

        public static function get_post_by_db_id($db_id, $post_type) {
            if (!is_numeric($db_id) || $db_id <= 0) return null;
            
            global $wpdb;
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM " . self::$table_name . " WHERE db_id = %d AND post_type = %s",
                $db_id, $post_type
            ));

            return $post_id ? get_post($post_id) : null;
        }

        /**
         * ===========================================================================
         * Permalink & Query Modification
         * ===========================================================================
         */
        public function add_query_vars($vars) {
            foreach (self::$targeted_post_types as $post_type) {
                $vars[] = $post_type . '_db_id';
            }
            return $vars;
        }
        
        public function add_rewrite_rules() {
            foreach (self::$targeted_post_types as $post_type) {
                $rule_base = $this->get_post_type_base($post_type);
                add_rewrite_rule(
                    "^{$rule_base}/([0-9]+)/?$",
                    "index.php?{$post_type}_db_id=\$matches[1]",
                    'top'
                );
            }
        }

        public function modify_main_query($query) {
            if (is_admin() || !$query->is_main_query()) return;

            foreach (self::$targeted_post_types as $post_type) {
                $db_id_var = $post_type . '_db_id';
                if ($db_id = $query->get($db_id_var)) {
                    $post = self::get_post_by_db_id($db_id, $post_type);
                    if ($post) {
                        $query->set('post_type', $post_type);
                        $query->set('p', $post->ID);
                        $query->set($db_id_var, null);
                    }
                }
            }
        }
        
        public function change_permalink_structure($post_link, $post) {
            if (in_array($post->post_type, self::$targeted_post_types)) {
                global $wpdb;
                $db_id = $wpdb->get_var($wpdb->prepare("SELECT db_id FROM " . self::$table_name . " WHERE post_id = %d", $post->ID));
                if ($db_id) {
                    $url_base = $this->get_post_type_base($post->post_type);
                    $post_link = home_url('/' . $url_base . '/' . $db_id . '/');
                }
            }
            return $post_link;
        }

        private function get_post_type_base($post_type) {
            if ($post_type === 'product' && get_option('woocommerce_permalinks')['product_base']) {
                return get_option('woocommerce_permalinks')['product_base'];
            }
            return $post_type;
        }
    }
}

// ============== Plugin Initialization ==============

// Register activation/deactivation hooks
register_activation_hook(__FILE__, ['WP_Unique_POST_ID', 'on_activation']);
register_deactivation_hook(__FILE__, ['WP_Unique_POST_ID', 'on_deactivation']);

// Initialize the plugin
if (class_exists('WP_Unique_POST_ID')) {
    WP_Unique_POST_ID::init();
}
