<?php

class CPM_Admin_Interface {
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Fiyat grubu alanını ekle
        add_action('woocommerce_product_options_pricing', array($this, 'add_price_group_field'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Merkezi Fiyat Yönetimi',
            'Fiyat Grupları',
            'manage_woocommerce',
            'cpm-price-groups',
            array($this, 'price_groups_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'woocommerce_page_cpm-price-groups') {
            wp_enqueue_style('cpm-admin-style', CPM_PLUGIN_URL . 'assets/css/admin.css');
            wp_enqueue_script('cpm-admin-script', CPM_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
            
            wp_localize_script('cpm-admin-script', 'cpm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cpm_nonce')
            ));
        }
    }
    
    public function add_price_group_field() {
        global $post;
        
        // Güvenlik kontrolleri
        if (!$post || !$post->ID) {
            return;
        }
        
        $product = wc_get_product($post->ID);
        if (!$product) {
            return;
        }
        
        $selected_group = $product->get_meta('_cpm_price_group');
        $price_groups = CPM_Price_Groups::get_instance()->get_all_groups();
        
        // Grup yoksa alanı gösterme
        if (empty($price_groups)) {
            echo '<div class="options_group">';
            echo '<p class="form-field">';
            echo '<label>Fiyat Grubu</label>';
            echo '<span class="description">Henüz fiyat grubu bulunmuyor. Önce <a href="' . admin_url('admin.php?page=cpm-price-groups') . '">fiyat grupları</a> oluşturun.</span>';
            echo '</p>';
            echo '</div>';
            return;
        }
        
        // Fiyat grubu seçim alanını oluştur
        echo '<div class="options_group">';
        
        woocommerce_wp_select(array(
            'id' => '_cpm_price_group',
            'label' => __('Fiyat Grubu', 'central-price-manager'),
            'options' => $this->get_price_groups_options($price_groups),
            'value' => $selected_group,
            'description' => __('Bu ürün için fiyat grubu seçin. Fiyat grubu seçildiğinde ürün fiyatları otomatik olarak güncellenecektir.', 'central-price-manager'),
            'desc_tip' => true,
            'wrapper_class' => 'form-field-wide'
        ));
        
        // Seçili grup bilgisi
        if ($selected_group) {
            $group_data = CPM_Price_Groups::get_instance()->get_group($selected_group);
            if ($group_data) {
                echo '<p class="form-field cpm-group-info">';
                echo '<label>&nbsp;</label>';
                echo '<span class="description">';
                echo '<strong>Mevcut Fiyatlar:</strong> ';
                echo 'Normal: ' . wc_price($group_data->regular_price);
                if ($group_data->sale_price && $group_data->sale_price < $group_data->regular_price) {
                    echo ' | İndirimli: ' . wc_price($group_data->sale_price);
                }
                echo '</span>';
                echo '</p>';
            }
        }
        
        echo '</div>';
    }
    
    private function get_price_groups_options($groups) {
        $options = array(
            '' => __('Fiyat Grubu Seçin', 'central-price-manager')
        );
        
        foreach ($groups as $group) {
            // Grup adı + kısa fiyat bilgisi
            $price_display = ' (₺' . number_format($group->regular_price, 2);
            
            if ($group->sale_price && $group->sale_price < $group->regular_price) {
                $price_display .= ' | ₺' . number_format($group->sale_price, 2);
            }
            
            $price_display .= ')';
            
            $options[$group->id] = $group->group_name . $price_display;
        }
        
        return $options;
    }
    
    public function price_groups_page() {
        // Manuel form submit işleme (AJAX olmadan çalışması için yedek)
        $this->handle_manual_form_submit();
        
        ?>
        <div class="wrap">
            <h1>Merkezi Fiyat Grupları</h1>
            
            <?php $this->display_admin_notices(); ?>
            
            <div class="cpm-admin-container">
                <!-- Fiyat Grubu Ekleme Formu -->
                <div class="cpm-form-section">
                    <h2>Yeni Fiyat Grubu Ekle</h2>
                    <form method="post" action="" id="cpm-add-group-form">
                        <?php wp_nonce_field('cpm_add_group', 'cpm_nonce'); ?>
                        <input type="hidden" name="cpm_action" value="add_group">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="group_name">Grup Adı *</label>
                                </th>
                                <td>
                                    <input type="text" name="group_name" id="group_name" class="regular-text" required 
                                           value="<?php echo isset($_POST['group_name']) ? esc_attr($_POST['group_name']) : ''; ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="regular_price">Normal Fiyat *</label>
                                </th>
                                <td>
                                    <input type="number" name="regular_price" id="regular_price" step="0.01" min="0" required
                                           value="<?php echo isset($_POST['regular_price']) ? esc_attr($_POST['regular_price']) : ''; ?>">
                                    <p class="description">Zorunlu alan</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sale_price">İndirimli Fiyat</label>
                                </th>
                                <td>
                                    <input type="number" name="sale_price" id="sale_price" step="0.01" min="0"
                                           value="<?php echo isset($_POST['sale_price']) ? esc_attr($_POST['sale_price']) : ''; ?>">
                                    <p class="description">Boş bırakılırsa indirim uygulanmaz. İndirimli fiyat, normal fiyattan düşük olmalıdır.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary">Fiyat Grubu Ekle</button>
                            <span class="cpm-ajax-loader" style="display: none;">İşleniyor...</span>
                        </p>
                    </form>
                </div>
                
                <!-- Fiyat Grupları Listesi -->
                <div class="cpm-list-section">
                    <h2>Mevcut Fiyat Grupları</h2>
                    <?php
                    $groups = CPM_Price_Groups::get_instance()->get_all_groups();
                    if ($groups) :
                    ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Grup Adı</th>
                                <th>Normal Fiyat</th>
                                <th>İndirimli Fiyat</th>
                                <th>Ürün Sayısı</th>
                                <th>Son Güncelleme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group) : 
                                $product_count = $this->get_product_count_by_group($group->id);
                                $last_updated = $group->updated_at !== $group->created_at ? 
                                    date('d.m.Y H:i', strtotime($group->updated_at)) : 
                                    'Oluşturuldu';
                            ?>
                            <tr data-group-id="<?php echo $group->id; ?>">
                                <td><?php echo $group->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($group->group_name); ?></strong>
                                </td>
                                <td>
                                    <span class="cpm-price"><?php echo wc_price($group->regular_price); ?></span>
                                </td>
                                <td>
                                    <?php if ($group->sale_price && $group->sale_price < $group->regular_price) : ?>
                                        <span class="cpm-sale-price"><?php echo wc_price($group->sale_price); ?></span>
                                    <?php else : ?>
                                        <span class="cpm-no-sale">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cpm-product-count">
                                        <?php echo $product_count; ?> ürün
                                        <?php if ($product_count > 0) : ?>
                                            <br>
                                            <a href="<?php echo admin_url('edit.php?post_type=product&_cpm_price_group=' . $group->id); ?>" 
                                               class="cpm-view-products" title="Ürünleri Görüntüle">
                                                görüntüle
                                            </a>
                                        <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo $last_updated; ?></small>
                                </td>
                                <td>
                                    <div class="cpm-action-buttons">
                                        <button class="button cpm-edit-group" data-group-id="<?php echo $group->id; ?>">
                                            <span class="dashicons dashicons-edit"></span> Düzenle
                                        </button>
                                        <?php if ($product_count === 0) : ?>
                                        <button class="button button-link-delete cpm-delete-group" data-group-id="<?php echo $group->id; ?>">
                                            <span class="dashicons dashicons-trash"></span> Sil
                                        </button>
                                        <?php else : ?>
                                        <span class="cpm-cannot-delete" title="Bu gruba bağlı ürünler olduğu için silinemez">
                                            Silinemez
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="cpm-list-info">
                        <p><strong>Toplam:</strong> <?php echo count($groups); ?> fiyat grubu</p>
                    </div>
                    
                    <?php else : ?>
                    <div class="cpm-no-groups">
                        <p>Henüz fiyat grubu bulunmuyor. İlk fiyat grubunuzu yukarıdaki formdan oluşturabilirsiniz.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Düzenleme Modalı -->
            <div id="cpm-edit-modal" style="display: none;">
                <div class="cpm-modal-content">
                    <div class="cpm-modal-header">
                        <h2>Fiyat Grubunu Düzenle</h2>
                        <button type="button" class="cpm-modal-close">&times;</button>
                    </div>
                    <form id="cpm-edit-form">
                        <?php wp_nonce_field('cpm_edit_group', 'cpm_edit_nonce'); ?>
                        <input type="hidden" name="group_id" id="edit_group_id">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="edit_group_name">Grup Adı *</label>
                                </th>
                                <td>
                                    <input type="text" name="group_name" id="edit_group_name" class="regular-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="edit_regular_price">Normal Fiyat *</label>
                                </th>
                                <td>
                                    <input type="number" name="regular_price" id="edit_regular_price" step="0.01" min="0" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="edit_sale_price">İndirimli Fiyat</label>
                                </th>
                                <td>
                                    <input type="number" name="sale_price" id="edit_sale_price" step="0.01" min="0">
                                    <p class="description">Boş bırakılırsa indirim kaldırılır</p>
                                </td>
                            </tr>
                        </table>
                        <div class="cpm-modal-footer">
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-yes"></span> Güncelle
                            </button>
                            <button type="button" class="button cpm-cancel-edit">İptal</button>
                            <span class="cpm-ajax-loader" style="display: none;">Güncelleniyor...</span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Manuel form submit işleme (AJAX çalışmazsa yedek)
     */
    private function handle_manual_form_submit() {
        if (isset($_POST['cpm_action']) && $_POST['cpm_action'] === 'add_group') {
            if (!isset($_POST['cpm_nonce']) || !wp_verify_nonce($_POST['cpm_nonce'], 'cpm_add_group')) {
                $this->add_admin_notice('Güvenlik hatası. Lütfen tekrar deneyin.', 'error');
                return;
            }
            
            if (!current_user_can('manage_woocommerce')) {
                $this->add_admin_notice('Bu işlem için yetkiniz yok.', 'error');
                return;
            }
            
            $data = array(
                'group_name' => sanitize_text_field($_POST['group_name']),
                'regular_price' => floatval($_POST['regular_price']),
                'sale_price' => isset($_POST['sale_price']) ? floatval($_POST['sale_price']) : 0
            );
            
            // Validasyon
            if (empty($data['group_name']) || empty($data['regular_price'])) {
                $this->add_admin_notice('Lütfen gerekli alanları doldurun.', 'error');
                return;
            }
            
            if ($data['sale_price'] > 0 && $data['sale_price'] >= $data['regular_price']) {
                $this->add_admin_notice('İndirimli fiyat, normal fiyattan düşük olmalıdır.', 'error');
                return;
            }
            
            $group_id = CPM_Price_Groups::get_instance()->save_group($data);
            
            if ($group_id) {
                $this->add_admin_notice('Fiyat grubu başarıyla eklendi.', 'success');
                // Formu temizlemek için yönlendirme
                wp_redirect(admin_url('admin.php?page=cpm-price-groups'));
                exit;
            } else {
                $this->add_admin_notice('Fiyat grubu eklenirken hata oluştu.', 'error');
            }
        }
    }

    /**
     * Admin bildirimleri göster
     */
    private function display_admin_notices() {
        if (isset($_GET['cpm_message'])) {
            $message = sanitize_text_field($_GET['cpm_message']);
            $type = isset($_GET['cpm_type']) ? sanitize_text_field($_GET['cpm_type']) : 'info';
            
            printf(
                '<div class="notice notice-%s is-dismissible><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }

    /**
     * Admin bildirimi ekle
     */
    private function add_admin_notice($message, $type = 'info') {
        add_action('admin_notices', function() use ($message, $type) {
            printf(
                '<div class="notice notice-%s is-dismissible><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        });
    }
    
    private function get_product_count_by_group($group_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_cpm_price_group' 
             AND meta_value = %s",
            $group_id
        ));
        
        return $count ? $count : 0;
    }
}