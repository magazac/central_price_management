<?php

class CPM_Price_Groups {
    
    private static $instance = null;
    private $table_name;
    private $cache_key = 'cpm_price_groups_cache';
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cpm_price_groups';
        
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        add_action('admin_init', array($this, 'create_table'));
        add_action('wp_loaded', array($this, 'clear_cache_on_update'));
    }
    
    /**
     * Veritabanı tablosunu oluştur
     */
    public function create_table() {
        global $wpdb;
        
        // Tablo zaten varsa ve yapısı doğruysa oluşturma
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name) {
            // Mevcut sütunları kontrol et ve gerekirse güncelle
            $this->maybe_update_table_structure();
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            group_name varchar(255) NOT NULL,
            group_slug varchar(255) NOT NULL,
            regular_price decimal(15,4) NOT NULL DEFAULT '0.0000',
            sale_price decimal(15,4) NOT NULL DEFAULT '0.0000',
            description text NULL,
            is_active tinyint(1) NOT NULL DEFAULT '1',
            created_by bigint(20) NOT NULL DEFAULT '0',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY group_slug (group_slug),
            KEY group_name (group_name),
            KEY is_active (is_active),
            KEY regular_price (regular_price),
            KEY sale_price (sale_price)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Örnek veri ekle (opsiyonel)
        $this->add_sample_data();
        
        $this->clear_cache();
    }
    
    /**
     * Tablo yapısını güncelle (gerekirse)
     */
    private function maybe_update_table_structure() {
        global $wpdb;
        
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}", ARRAY_A);
        $column_names = wp_list_pluck($columns, 'Field');
        
        $missing_columns = array();
        
        // Gerekli sütunları kontrol et
        $required_columns = array('group_slug', 'description', 'is_active', 'created_by');
        
        foreach ($required_columns as $column) {
            if (!in_array($column, $column_names)) {
                $missing_columns[] = $column;
            }
        }
        
        if (!empty($missing_columns)) {
            $this->update_table_columns($missing_columns);
        }
    }
    
    /**
     * Eksik sütunları ekle
     */
    private function update_table_columns($missing_columns) {
        global $wpdb;
        
        $alter_sql = "ALTER TABLE {$this->table_name} ";
        $additions = array();
        
        foreach ($missing_columns as $column) {
            switch ($column) {
                case 'group_slug':
                    $additions[] = "ADD COLUMN group_slug varchar(255) NOT NULL AFTER group_name";
                    break;
                case 'description':
                    $additions[] = "ADD COLUMN description text NULL AFTER sale_price";
                    break;
                case 'is_active':
                    $additions[] = "ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT '1' AFTER description";
                    break;
                case 'created_by':
                    $additions[] = "ADD COLUMN created_by bigint(20) NOT NULL DEFAULT '0' AFTER is_active";
                    break;
            }
        }
        
        if (!empty($additions)) {
            $alter_sql .= implode(', ', $additions);
            $wpdb->query($alter_sql);
            $this->clear_cache();
        }
    }
    
    /**
     * Örnek veri ekle
     */
    private function add_sample_data() {
        $sample_groups = array(
            array(
                'group_name' => 'Standart Ürünler',
                'regular_price' => 199.99,
                'sale_price' => 149.99,
                'description' => 'Standart ürünler için fiyat grubu'
            ),
            array(
                'group_name' => 'Premium Ürünler',
                'regular_price' => 399.99,
                'sale_price' => 349.99,
                'description' => 'Premium ürünler için fiyat grubu'
            ),
            array(
                'group_name' => 'Kampanya Ürünleri',
                'regular_price' => 99.99,
                'sale_price' => 79.99,
                'description' => 'Kampanya ürünleri için fiyat grubu'
            )
        );
        
        foreach ($sample_groups as $group_data) {
            $this->save_group($group_data);
        }
    }
    
    /**
     * Tüm fiyat gruplarını getir
     */
    public function get_all_groups($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => 'all', // all, active, inactive
            'orderby' => 'id',
            'order' => 'DESC',
            'limit' => -1,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Cache kontrolü
        $cache_key = $this->cache_key . '_' . md5(serialize($args));
        $cached = wp_cache_get($cache_key, 'cpm');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $where = "WHERE 1=1";
        
        // Status filtresi
        if ($args['status'] === 'active') {
            $where .= " AND is_active = 1";
        } elseif ($args['status'] === 'inactive') {
            $where .= " AND is_active = 0";
        }
        
        // Order by
        $allowed_orderby = array('id', 'group_name', 'regular_price', 'sale_price', 'created_at', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'id';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $order_clause = "ORDER BY {$orderby} {$order}";
        
        // Limit ve offset
        $limit_clause = "";
        if ($args['limit'] > 0) {
            $limit_clause = "LIMIT " . intval($args['offset']) . ", " . intval($args['limit']);
        }
        
        $sql = "SELECT * FROM {$this->table_name} {$where} {$order_clause} {$limit_clause}";
        
        $results = $wpdb->get_results($sql);
        
        // Sonuçları işle
        $groups = array();
        foreach ($results as $group) {
            $groups[] = $this->process_group_data($group);
        }
        
        // Cache'e kaydet
        wp_cache_set($cache_key, $groups, 'cpm', 3600); // 1 saat cache
        
        return $groups;
    }
    
    /**
     * Aktif fiyat gruplarını getir
     */
    public function get_active_groups() {
        return $this->get_all_groups(array('status' => 'active', 'orderby' => 'group_name', 'order' => 'ASC'));
    }
    
    /**
     * Belirli bir fiyat grubunu getir
     */
    public function get_group($group_id) {
        global $wpdb;
        
        if (empty($group_id)) {
            return false;
        }
        
        // Cache kontrolü
        $cache_key = $this->cache_key . '_' . $group_id;
        $cached = wp_cache_get($cache_key, 'cpm');
        
        if ($cached !== false) {
            return $cached;
        }
        
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d", 
            $group_id
        ));
        
        if ($group) {
            $group = $this->process_group_data($group);
            wp_cache_set($cache_key, $group, 'cpm', 3600);
        }
        
        return $group;
    }
    
    /**
     * Slug'a göre fiyat grubu getir
     */
    public function get_group_by_slug($slug) {
        global $wpdb;
        
        if (empty($slug)) {
            return false;
        }
        
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE group_slug = %s", 
            $slug
        ));
        
        return $group ? $this->process_group_data($group) : false;
    }
    
    /**
     * Grup verisini işle ve formatla
     */
    private function process_group_data($group) {
        if (!$group) {
            return false;
        }
        
        // Sayısal değerleri float'a çevir
        $group->regular_price = floatval($group->regular_price);
        $group->sale_price = floatval($group->sale_price);
        
        // Boolean değerleri dönüştür
        $group->is_active = (bool)$group->is_active;
        
        // İndirim oranını hesapla
        $group->discount_percent = 0;
        if ($group->sale_price > 0 && $group->regular_price > 0 && $group->sale_price < $group->regular_price) {
            $group->discount_percent = round((($group->regular_price - $group->sale_price) / $group->regular_price) * 100, 1);
        }
        
        // İndirim miktarını hesapla
        $group->discount_amount = $group->sale_price > 0 ? $group->regular_price - $group->sale_price : 0;
        
        // Tarihleri formatla
        $group->created_at_formatted = date('d.m.Y H:i', strtotime($group->created_at));
        $group->updated_at_formatted = date('d.m.Y H:i', strtotime($group->updated_at));
        
        // Kullanıcı bilgilerini getir
        $group->created_by_user = $group->created_by ? get_userdata($group->created_by) : false;
        
        return $group;
    }
    
    /**
     * Yeni fiyat grubu kaydet
     */
    public function save_group($data) {
        global $wpdb;
        
        $defaults = array(
            'group_name' => '',
            'regular_price' => 0,
            'sale_price' => 0,
            'description' => '',
            'is_active' => true,
            'created_by' => get_current_user_id()
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Validasyon
        $validation = $this->validate_group_data($data);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Slug oluştur
        $data['group_slug'] = $this->generate_group_slug($data['group_name']);
        
        // Fiyatları formatla
        $data['regular_price'] = floatval($data['regular_price']);
        $data['sale_price'] = floatval($data['sale_price']);
        
        // Boolean değeri dönüştür
        $data['is_active'] = $data['is_active'] ? 1 : 0;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'group_name' => sanitize_text_field($data['group_name']),
                'group_slug' => sanitize_title($data['group_slug']),
                'regular_price' => $data['regular_price'],
                'sale_price' => $data['sale_price'],
                'description' => sanitize_textarea_field($data['description']),
                'is_active' => $data['is_active'],
                'created_by' => intval($data['created_by'])
            ),
            array('%s', '%s', '%f', '%f', '%s', '%d', '%d')
        );
        
        if ($result) {
            $group_id = $wpdb->insert_id;
            $this->clear_cache();
            $this->log_group_action($group_id, 'created', $data);
            
            return $group_id;
        }
        
        return new WP_Error('db_error', 'Fiyat grubu kaydedilirken veritabanı hatası oluştu.');
    }
    
    /**
     * Fiyat grubunu güncelle
     */
    public function update_group($group_id, $data) {
        global $wpdb;
        
        // Mevcut grubu getir
        $existing_group = $this->get_group($group_id);
        if (!$existing_group) {
            return new WP_Error('not_found', 'Fiyat grubu bulunamadı.');
        }
        
        // Validasyon
        $validation = $this->validate_group_data($data, $group_id);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Güncelleme verilerini hazırla
        $update_data = array();
        $format = array();
        
        if (isset($data['group_name'])) {
            $update_data['group_name'] = sanitize_text_field($data['group_name']);
            $format[] = '%s';
            
            // İsim değiştiyse slug'ı da güncelle
            if ($data['group_name'] !== $existing_group->group_name) {
                $update_data['group_slug'] = $this->generate_group_slug($data['group_name'], $group_id);
                $format[] = '%s';
            }
        }
        
        if (isset($data['regular_price'])) {
            $update_data['regular_price'] = floatval($data['regular_price']);
            $format[] = '%f';
        }
        
        if (isset($data['sale_price'])) {
            $update_data['sale_price'] = floatval($data['sale_price']);
            $format[] = '%f';
        }
        
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $format[] = '%s';
        }
        
        if (isset($data['is_active'])) {
            $update_data['is_active'] = $data['is_active'] ? 1 : 0;
            $format[] = '%d';
        }
        
        if (empty($update_data)) {
            return new WP_Error('no_data', 'Güncelleme için veri sağlanmadı.');
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $group_id),
            $format,
            array('%d')
        );
        
        if ($result !== false) {
            $this->clear_cache();
            $this->log_group_action($group_id, 'updated', $data);
            
            // Fiyat değişikliği olduysa, bağlı ürünleri güncelle
            if (isset($data['regular_price']) || isset($data['sale_price'])) {
                $updated_group = $this->get_group($group_id);
                do_action('cpm_price_group_updated', $group_id, $updated_group);
            }
            
            return true;
        }
        
        return new WP_Error('db_error', 'Fiyat grubu güncellenirken veritabanı hatası oluştu.');
    }
    
    /**
     * Fiyat grubunu sil
     */
    public function delete_group($group_id) {
        global $wpdb;
        
        // Grubun var olduğunu kontrol et
        $existing_group = $this->get_group($group_id);
        if (!$existing_group) {
            return new WP_Error('not_found', 'Fiyat grubu bulunamadı.');
        }
        
        // Gruba bağlı ürün olup olmadığını kontrol et
        $product_count = CPM_Product_Handler::get_instance()->get_product_count_by_group($group_id);
        if ($product_count > 0) {
            return new WP_Error('has_products', 'Bu fiyat grubuna bağlı ürünler olduğu için silinemez.');
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $group_id),
            array('%d')
        );
        
        if ($result) {
            $this->clear_cache();
            $this->log_group_action($group_id, 'deleted', $existing_group);
            do_action('cpm_price_group_deleted', $group_id);
            
            return true;
        }
        
        return new WP_Error('db_error', 'Fiyat grubu silinirken veritabanı hatası oluştu.');
    }
    
    /**
     * Grup verisini validasyon et
     */
    private function validate_group_data($data, $group_id = null) {
        // Grup adı kontrolü
        if (empty($data['group_name'])) {
            return new WP_Error('empty_name', 'Grup adı boş olamaz.');
        }
        
        // Grup adı benzersizlik kontrolü
        $existing = $this->get_group_by_name($data['group_name'], $group_id);
        if ($existing) {
            return new WP_Error('duplicate_name', 'Bu isimde bir fiyat grubu zaten mevcut.');
        }
        
        // Fiyat validasyonu
        if (!isset($data['regular_price']) || $data['regular_price'] < 0) {
            return new WP_Error('invalid_price', 'Geçersiz normal fiyat.');
        }
        
        if (isset($data['sale_price']) && $data['sale_price'] < 0) {
            return new WP_Error('invalid_sale_price', 'Geçersiz indirimli fiyat.');
        }
        
        // İndirimli fiyat kontrolü
        if (isset($data['sale_price']) && $data['sale_price'] > 0 && 
            isset($data['regular_price']) && $data['sale_price'] >= $data['regular_price']) {
            return new WP_Error('invalid_discount', 'İndirimli fiyat, normal fiyattan düşük olmalıdır.');
        }
        
        return true;
    }
    
    /**
     * İsime göre grup getir (benzersizlik kontrolü için)
     */
    private function get_group_by_name($group_name, $exclude_id = null) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name} WHERE group_name = %s";
        $params = array($group_name);
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $sql .= " LIMIT 1";
        
        return $wpdb->get_row($wpdb->prepare($sql, $params));
    }
    
    /**
     * Grup slug'ı oluştur
     */
    private function generate_group_slug($group_name, $exclude_id = null) {
        $slug = sanitize_title($group_name);
        $original_slug = $slug;
        $counter = 1;
        
        while ($this->slug_exists($slug, $exclude_id)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * Slug'ın var olup olmadığını kontrol et
     */
    private function slug_exists($slug, $exclude_id = null) {
        global $wpdb;
        
        $sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE group_slug = %s";
        $params = array($slug);
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $count = $wpdb->get_var($wpdb->prepare($sql, $params));
        
        return $count > 0;
    }
    
    /**
     * Grup aksiyonlarını logla
     */
    private function log_group_action($group_id, $action, $data) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'group_id' => $group_id,
            'action' => $action,
            'user_id' => get_current_user_id(),
            'data' => $data
        );
        
        // Debug için error_log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CPM Group Log: Group {$group_id} - Action: {$action} - User: " . get_current_user_id());
        }
    }
    
    /**
     * Cache'i temizle
     */
    public function clear_cache() {
        wp_cache_delete($this->cache_key, 'cpm');
        
        // Tüm cache key'lerini temizle
        $cache_keys = array(
            $this->cache_key . '_active',
            $this->cache_key . '_all'
        );
        
        foreach ($cache_keys as $key) {
            wp_cache_delete($key, 'cpm');
        }
    }
    
    /**
     * Güncelleme sonrası cache temizleme
     */
    public function clear_cache_on_update() {
        if (isset($_GET['cpm_clear_cache'])) {
            $this->clear_cache();
        }
    }
    
    /**
     * İstatistikleri getir
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = array(
            'total_groups' => 0,
            'active_groups' => 0,
            'inactive_groups' => 0,
            'groups_with_discount' => 0,
            'average_regular_price' => 0,
            'average_sale_price' => 0
        );
        
        $stats['total_groups'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $stats['active_groups'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_active = 1");
        $stats['inactive_groups'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_active = 0");
        $stats['groups_with_discount'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE sale_price > 0 AND sale_price < regular_price"
        );
        $stats['average_regular_price'] = $wpdb->get_var("SELECT AVG(regular_price) FROM {$this->table_name}");
        $stats['average_sale_price'] = $wpdb->get_var("SELECT AVG(sale_price) FROM {$this->table_name} WHERE sale_price > 0");
        
        return $stats;
    }
}