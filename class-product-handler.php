<?php

class CPM_Product_Handler {
    
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
        // Ürün kaydetme hook'ları
        add_action('woocommerce_admin_process_product_object', array($this, 'save_product_price_group'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_price_group_legacy'), 10, 2);
        
        // Fiyat güncelleme hook'ları
        add_action('cpm_price_group_updated', array($this, 'update_products_price'), 10, 2);
        add_action('cpm_price_group_deleted', array($this, 'handle_price_group_deletion'), 10, 1);
        
        // Fiyat görüntüleme hook'ları
        add_filter('woocommerce_product_get_price', array($this, 'get_dynamic_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'get_dynamic_regular_price'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'get_dynamic_sale_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'get_dynamic_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array($this, 'get_dynamic_regular_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_sale_price', array($this, 'get_dynamic_sale_price'), 10, 2);
        
        // Admin listeleme hook'ları
        add_filter('woocommerce_product_filters', array($this, 'add_price_group_filter'));
        add_action('pre_get_posts', array($this, 'handle_price_group_filter'));
        
        // Toplu işlem hook'ları
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_actions'), 10, 3);
        
        // Ürün silme hook'u
        add_action('before_delete_post', array($this, 'handle_product_deletion'));
    }
    
    /**
     * Ürün kaydedilirken fiyat grubunu kaydet VE FİYAT ALANLARINI DOLDUR
     */
    public function save_product_price_group($product) {
        // Backward compatibility için her iki methodu da kontrol et
        if (isset($_POST['_cpm_price_group']) || isset($_REQUEST['_cpm_price_group'])) {
            $group_id = isset($_POST['_cpm_price_group']) ? 
                        intval($_POST['_cpm_price_group']) : 
                        intval($_REQUEST['_cpm_price_group']);
            
            $current_group_id = $product->get_meta('_cpm_price_group');
            
            // Grup değiştiyse veya ilk kez atanıyorsa
            if ($current_group_id != $group_id) {
                $product->update_meta_data('_cpm_price_group', $group_id);
                
                // Fiyatları hemen güncelle VE FİYAT ALANLARINI DOLDUR
                if ($group_id > 0) {
                    $this->update_product_price_from_group($product, $group_id);
                    $this->log_price_update($product->get_id(), $group_id, 'product_updated');
                } elseif ($group_id == 0 && $current_group_id > 0) {
                    // Grup kaldırıldıysa, fiyatları sıfırla
                    $this->clear_product_prices($product);
                    $this->log_price_update($product->get_id(), 0, 'group_removed');
                }
            }
        }
    }
    
    /**
     * Eski WooCommerce versiyonları için legacy support
     */
    public function save_product_price_group_legacy($post_id, $post) {
        if (isset($_POST['_cpm_price_group'])) {
            $product = wc_get_product($post_id);
            if ($product) {
                $this->save_product_price_group($product);
            }
        }
    }
    
    /**
     * Fiyat grubu güncellendiğinde tüm ürünleri güncelle
     */
    public function update_products_price($group_id, $group_data) {
        $products = $this->get_products_by_group($group_id);
        $updated_count = 0;
        
        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $this->update_product_price_from_group($product, $group_id, $group_data)) {
                $updated_count++;
                $this->log_price_update($product_id, $group_id, 'group_updated');
            }
        }
        
        // Güncelleme istatistiğini logla
        if ($updated_count > 0) {
            error_log("CPM: Group {$group_id} updated - {$updated_count} products affected");
        }
        
        return $updated_count;
    }
    
    /**
     * Fiyat grubu silindiğinde bağlı ürünleri işle
     */
    public function handle_price_group_deletion($group_id) {
        $products = $this->get_products_by_group($group_id);
        
        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                // Grup meta verisini temizle
                $product->delete_meta_data('_cpm_price_group');
                // Fiyatları sıfırla
                $this->clear_product_prices($product);
                $product->save();
                
                $this->log_price_update($product_id, $group_id, 'group_deleted');
            }
        }
        
        error_log("CPM: Group {$group_id} deleted - " . count($products) . " products affected");
    }
    
    /**
     * Belirli bir gruba ait ürünleri getir
     */
    private function get_products_by_group($group_id, $include_variations = true) {
        $args = array(
            'post_type' => $include_variations ? array('product', 'product_variation') : 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => array('publish', 'pending', 'draft', 'private'),
            'meta_query' => array(
                array(
                    'key' => '_cpm_price_group',
                    'value' => $group_id,
                    'compare' => '='
                )
            )
        );
        
        $products = get_posts($args);
        
        // Ana ürünleri filtrele (sadece variation değilse)
        if ($include_variations) {
            $main_products = array();
            foreach ($products as $product_id) {
                $product = wc_get_product($product_id);
                if ($product && $product->is_type('variation')) {
                    $main_products[] = $product->get_parent_id();
                } else {
                    $main_products[] = $product_id;
                }
            }
            return array_unique($main_products);
        }
        
        return $products;
    }
    
    /**
     * Ürün fiyatlarını gruptan güncelle VE FİYAT ALANLARINI DOLDUR
     */
    private function update_product_price_from_group($product, $group_id, $group_data = null) {
        if (!$group_data) {
            $price_groups = CPM_Price_Groups::get_instance();
            $group_data = $price_groups->get_group($group_id);
        }
        
        // Grup verisi yoksa işlemi durdur
        if (!$group_data) {
            error_log("CPM: Group {$group_id} not found for product {$product->get_id()}");
            return false;
        }
        
        try {
            // ⚡ ÖNEMLİ: Fiyat alanlarını doldur (manuel girilmiş gibi)
            $product->set_regular_price($group_data->regular_price);
            
            // İndirimli fiyat kontrolü
            if ($group_data->sale_price && $group_data->sale_price < $group_data->regular_price) {
                $product->set_sale_price($group_data->sale_price);
                $product->set_price($group_data->sale_price); // Görünen fiyat
            } else {
                // İndirim yoksa sale_price'ı temizle
                $product->set_sale_price('');
                $product->set_price($group_data->regular_price);
            }
            
            // ⚡ ÖNEMLİ: Meta verileri de güncelle (diğer eklentiler için)
            $product->update_meta_data('_regular_price', $group_data->regular_price);
            $product->update_meta_data('_price', $group_data->sale_price ? $group_data->sale_price : $group_data->regular_price);
            
            if ($group_data->sale_price && $group_data->sale_price < $group_data->regular_price) {
                $product->update_meta_data('_sale_price', $group_data->sale_price);
            } else {
                $product->delete_meta_data('_sale_price');
            }
            
            $product->save();
            
            // Variation ürünlerse parent'ı da güncelle
            if ($product->is_type('variation')) {
                $this->update_parent_product_price($product->get_parent_id());
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("CPM: Error updating product {$product->get_id()} - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ürün fiyatlarını temizle (grup kaldırıldığında)
     */
    private function clear_product_prices($product) {
        try {
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_price('');
            
            // Meta verileri de temizle
            $product->delete_meta_data('_regular_price');
            $product->delete_meta_data('_sale_price');
            $product->delete_meta_data('_price');
            
            $product->save();
            
            // Variation ürünlerse parent'ı da güncelle
            if ($product->is_type('variation')) {
                $this->update_parent_product_price($product->get_parent_id());
            }
            
        } catch (Exception $e) {
            error_log("CPM: Error clearing prices for product {$product->get_id()} - " . $e->getMessage());
        }
    }
    
    /**
     * Ana ürün fiyatını güncelle (variationlar için)
     */
    private function update_parent_product_price($parent_id) {
        $parent_product = wc_get_product($parent_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $parent_product->variable_product_sync();
        }
    }
    
    /**
     * Stok durumunu güncelle (gerekirse)
     */
    private function maybe_update_stock_status($product) {
        // Eğer fiyat sıfırlanmışsa ve stok yönetimi açıksa
        if ($product->get_regular_price() == 0 && $product->get_manage_stock()) {
            $product->set_stock_status('onbackorder');
        }
    }
    
    // ==================== DYNAMIC PRICE HOOKS ====================
    
    /**
     * Dinamik fiyat getirme - Görünen fiyat
     */
    public function get_dynamic_price($price, $product) {
        $group_id = $product->get_meta('_cpm_price_group');
        if ($group_id) {
            $group_data = CPM_Price_Groups::get_instance()->get_group($group_id);
            if ($group_data) {
                // Sale price aktifse onu döndür, değilse regular price
                if ($group_data->sale_price && $group_data->sale_price < $group_data->regular_price) {
                    return $group_data->sale_price;
                }
                return $group_data->regular_price;
            }
        }
        return $price;
    }
    
    /**
     * Dinamik normal fiyat getirme
     */
    public function get_dynamic_regular_price($price, $product) {
        $group_id = $product->get_meta('_cpm_price_group');
        if ($group_id) {
            $group_data = CPM_Price_Groups::get_instance()->get_group($group_id);
            if ($group_data) {
                return $group_data->regular_price;
            }
        }
        return $price;
    }
    
    /**
     * Dinamik indirimli fiyat getirme
     */
    public function get_dynamic_sale_price($price, $product) {
        $group_id = $product->get_meta('_cpm_price_group');
        if ($group_id) {
            $group_data = CPM_Price_Groups::get_instance()->get_group($group_id);
            if ($group_data && $group_data->sale_price && $group_data->sale_price < $group_data->regular_price) {
                return $group_data->sale_price;
            }
        }
        return $price;
    }
    
    // ==================== ADMIN FILTERS ====================
    
    /**
     * Ürün listesine fiyat grubu filtresi ekle
     */
    public function add_price_group_filter($filters) {
        $groups = CPM_Price_Groups::get_instance()->get_all_groups();
        
        if ($groups && is_array($groups)) {
            $current_filter = isset($_GET['_cpm_price_group']) ? intval($_GET['_cpm_price_group']) : '';
            
            // DÜZELTME: $filters değişkeninin array olduğundan emin ol
            if (!is_array($filters)) {
                $filters = array();
            }
            
            $filter_html = '<select name="_cpm_price_group">';
            $filter_html .= '<option value="">' . __('Tüm Fiyat Grupları', 'central-price-manager') . '</option>';
            
            foreach ($groups as $group) {
                $selected = $current_filter == $group->id ? 'selected="selected"' : '';
                $filter_html .= '<option value="' . esc_attr($group->id) . '" ' . $selected . '>' . 
                    esc_html($group->group_name) . '</option>';
            }
            
            $filter_html .= '</select>';
            
            // DÜZELTME: Array'e güvenli şekilde ekle
            $filters['cpm_price_group'] = $filter_html;
        }
        
        return $filters;
    }
    
    /**
     * Fiyat grubu filtresini işle
     */
    public function handle_price_group_filter($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        if (isset($_GET['_cpm_price_group']) && !empty($_GET['_cpm_price_group'])) {
            $group_id = intval($_GET['_cpm_price_group']);
            
            $meta_query = $query->get('meta_query') ?: array();
            $meta_query[] = array(
                'key' => '_cpm_price_group',
                'value' => $group_id,
                'compare' => '='
            );
            
            $query->set('meta_query', $meta_query);
        }
    }
    
    // ==================== BULK ACTIONS ====================
    
    /**
     * Toplu işlemlere fiyat grubu atama ekle
     */
    public function add_bulk_actions($bulk_actions) {
        $groups = CPM_Price_Groups::get_instance()->get_all_groups();
        
        if ($groups && is_array($groups)) {
            foreach ($groups as $group) {
                $bulk_actions['cpm_set_group_' . $group->id] = sprintf(
                    __('Fiyat grubuna ata: %s', 'central-price-manager'),
                    $group->group_name
                );
            }
            $bulk_actions['cpm_remove_group'] = __('Fiyat grubunu kaldır', 'central-price-manager');
        }
        
        return $bulk_actions;
    }
    
    /**
     * Toplu işlemleri yönet
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        // Fiyat grubu atama işlemleri
        if (strpos($doaction, 'cpm_set_group_') === 0) {
            $group_id = intval(str_replace('cpm_set_group_', '', $doaction));
            $updated_count = 0;
            
            foreach ($post_ids as $post_id) {
                $product = wc_get_product($post_id);
                if ($product) {
                    $product->update_meta_data('_cpm_price_group', $group_id);
                    if ($this->update_product_price_from_group($product, $group_id)) {
                        $updated_count++;
                    }
                }
            }
            
            $redirect_to = add_query_arg('cpm_bulk_updated', $updated_count, $redirect_to);
        }
        // Fiyat grubu kaldırma işlemi
        elseif ($doaction === 'cpm_remove_group') {
            $updated_count = 0;
            
            foreach ($post_ids as $post_id) {
                $product = wc_get_product($post_id);
                if ($product) {
                    $product->delete_meta_data('_cpm_price_group');
                    $this->clear_product_prices($product);
                    $updated_count++;
                }
            }
            
            $redirect_to = add_query_arg('cpm_bulk_removed', $updated_count, $redirect_to);
        }
        
        return $redirect_to;
    }
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * Ürün silme işlemini yönet
     */
    public function handle_product_deletion($post_id) {
        $product = wc_get_product($post_id);
        if ($product) {
            $group_id = $product->get_meta('_cpm_price_group');
            if ($group_id) {
                $this->log_price_update($post_id, $group_id, 'product_deleted');
            }
        }
    }
    
    /**
     * Fiyat güncellemelerini logla
     */
    private function log_price_update($product_id, $group_id, $action) {
        // Basit log sistemi - geliştirilebilir
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'product_id' => $product_id,
            'group_id' => $group_id,
            'action' => $action,
            'user_id' => get_current_user_id()
        );
        
        // Debug için error_log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CPM Log: Product {$product_id} - Group {$group_id} - Action: {$action}");
        }
    }
    
    /**
     * Grup ID'sine göre ürün sayısını getir
     */
    public function get_product_count_by_group($group_id) {
        return count($this->get_products_by_group($group_id, false));
    }
}