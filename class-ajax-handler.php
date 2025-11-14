<?php

class CPM_Ajax_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_cpm_add_price_group', array($this, 'add_price_group'));
        add_action('wp_ajax_cpm_edit_price_group', array($this, 'edit_price_group'));
        add_action('wp_ajax_cpm_delete_price_group', array($this, 'delete_price_group'));
        add_action('wp_ajax_cpm_get_group_data', array($this, 'get_group_data'));
    }
    
    public function add_price_group() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkiniz yok.');
        }
        
        $data = array(
            'group_name' => sanitize_text_field($_POST['group_name']),
            'regular_price' => floatval($_POST['regular_price']),
            'sale_price' => isset($_POST['sale_price']) ? floatval($_POST['sale_price']) : 0
        );
        
        $group_id = CPM_Price_Groups::get_instance()->save_group($data);
        
        if ($group_id) {
            wp_send_json_success(array(
                'message' => 'Fiyat grubu başarıyla eklendi.',
                'group_id' => $group_id
            ));
        } else {
            wp_send_json_error('Fiyat grubu eklenirken hata oluştu.');
        }
    }
    
    public function edit_price_group() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkiniz yok.');
        }
        
        $group_id = intval($_POST['group_id']);
        $data = array(
            'group_name' => sanitize_text_field($_POST['group_name']),
            'regular_price' => floatval($_POST['regular_price']),
            'sale_price' => isset($_POST['sale_price']) ? floatval($_POST['sale_price']) : 0
        );
        
        $result = CPM_Price_Groups::get_instance()->update_group($group_id, $data);
        
        if ($result !== false) {
            // Fiyat grubu güncellendi, ilgili ürünleri güncelle
            do_action('cpm_price_group_updated', $group_id, $data);
            
            wp_send_json_success('Fiyat grubu başarıyla güncellendi.');
        } else {
            wp_send_json_error('Fiyat grubu güncellenirken hata oluştu.');
        }
    }
    
    public function delete_price_group() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkiniz yok.');
        }
        
        $group_id = intval($_POST['group_id']);
        $result = CPM_Price_Groups::get_instance()->delete_group($group_id);
        
        if ($result) {
            wp_send_json_success('Fiyat grubu başarıyla silindi.');
        } else {
            wp_send_json_error('Fiyat grubu silinirken hata oluştu.');
        }
    }
    
    public function get_group_data() {
        $this->verify_nonce();
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkiniz yok.');
        }
        
        $group_id = intval($_POST['group_id']);
        $group = CPM_Price_Groups::get_instance()->get_group($group_id);
        
        if ($group) {
            wp_send_json_success($group);
        } else {
            wp_send_json_error('Fiyat grubu bulunamadı.');
        }
    }
    
    private function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cpm_nonce')) {
            wp_die('Güvenlik hatası.');
        }
    }
}