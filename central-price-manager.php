<?php
/**
 * Plugin Name: Central Price Manager
 * Description: WooCommerce Merkezi Fiyat Yönetimi
 * Version: 1.9
 * Author: Magazac
 */

defined('ABSPATH') || exit;

// Eklenti sabitleri
define('CPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CPM_PLUGIN_PATH', plugin_dir_path(__FILE__));

class Central_Price_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
private function includes() {
    $files = [
        'includes/class-price-groups.php',
        'includes/class-product-handler.php', 
        'includes/class-admin-interface.php',
        'includes/class-ajax-handler.php'
    ];
    
    foreach ($files as $file) {
        if (file_exists(CPM_PLUGIN_PATH . $file)) {
            require_once CPM_PLUGIN_PATH . $file;
        }
    }
}
    
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Sınıfları başlat
        CPM_Price_Groups::get_instance();
        CPM_Product_Handler::get_instance();
        CPM_Admin_Interface::get_instance();
        CPM_Ajax_Handler::get_instance();
    }
    
    public function activate() {
        // Aktivasyon işlemleri
        flush_rewrite_rules();
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>Central Price Manager eklentisi WooCommerce gerektirir.</p></div>';
    }
}

// Eklentiyi başlat
Central_Price_Manager::get_instance();