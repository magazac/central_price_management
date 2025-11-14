jQuery(document).ready(function($) {
    // Fiyat grubu ekleme
    $('#cpm-add-group-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        formData += '&action=cpm_add_price_group&nonce=' + cpm_ajax.nonce;
        
        $.post(cpm_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Hata: ' + response.data);
            }
        });
    });
    
    // Fiyat grubu düzenleme modalını aç
    $('.cpm-edit-group').on('click', function() {
        var groupId = $(this).data('group-id');
        
        $.post(cpm_ajax.ajax_url, {
            action: 'cpm_get_group_data',
            group_id: groupId,
            nonce: cpm_ajax.nonce
        }, function(response) {
            if (response.success) {
                var group = response.data;
                $('#edit_group_id').val(group.id);
                $('#edit_group_name').val(group.group_name);
                $('#edit_regular_price').val(group.regular_price);
                $('#edit_sale_price').val(group.sale_price);
                $('#cpm-edit-modal').show();
            } else {
                alert('Hata: ' + response.data);
            }
        });
    });
    
    // Düzenleme formu gönderimi
    $('#cpm-edit-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        formData += '&action=cpm_edit_price_group&nonce=' + cpm_ajax.nonce;
        
        $.post(cpm_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Hata: ' + response.data);
            }
        });
    });
    
    // Fiyat grubu silme
    $('.cpm-delete-group').on('click', function() {
        if (!confirm('Bu fiyat grubunu silmek istediğinizden emin misiniz?')) {
            return;
        }
        
        var groupId = $(this).data('group-id');
        
        $.post(cpm_ajax.ajax_url, {
            action: 'cpm_delete_price_group',
            group_id: groupId,
            nonce: cpm_ajax.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Hata: ' + response.data);
            }
        });
    });
    
    // Modal kapatma
    $('.cpm-cancel-edit').on('click', function() {
        $('#cpm-edit-modal').hide();
    });

    $('.cpm-modal-close').on('click', function() {
        $('#cpm-edit-modal').hide();
    });
    
    // REAL-TIME FİYAT GÜNCELLEME - Ürün edit sayfasında
    $('#_cpm_price_group').on('change', function() {
        var selectedGroup = $(this).val();
        var $priceField = $(this).closest('.form-field');
        
        if (selectedGroup) {
            // AJAX ile grup fiyatlarını getir
            $.post(cpm_ajax.ajax_url, {
                action: 'cpm_get_group_data',
                group_id: selectedGroup,
                nonce: cpm_ajax.nonce
            }, function(response) {
                if (response.success) {
                    var group = response.data;
                    
                    // Fiyat formatını oluştur
                    var priceHtml = '<strong>Mevcut Fiyatlar:</strong> Normal: ' + group.regular_price_formatted;
                    
                    if (group.sale_price && group.sale_price < group.regular_price) {
                        priceHtml += ' | İndirimli: ' + group.sale_price_formatted;
                    }
                    
                    // Eski fiyat bilgisini kaldır, yenisini ekle
                    $('.cpm-group-info').remove();
                    $priceField.after(
                        '<p class="form-field cpm-group-info">' +
                        '<label>&nbsp;</label>' +
                        '<span class="description">' + priceHtml + '</span>' +
                        '</p>'
                    );
                    
                    // Fiyat alanlarını otomatik doldur
                    $('#_regular_price').val(group.regular_price);
                    if (group.sale_price && group.sale_price > 0) {
                        $('#_sale_price').val(group.sale_price);
                    } else {
                        $('#_sale_price').val('');
                    }
                    
                }
            });
        } else {
            // Grup seçilmediyse fiyat bilgisini temizle
            $('.cpm-group-info').remove();
            
            // Fiyat alanlarını temizle
            $('#_regular_price').val('');
            $('#_sale_price').val('');
        }
    });

    // ESC tuşu ile modal kapatma
    $(document).on('keyup', function(e) {
        if (e.keyCode === 27 && $('#cpm-edit-modal').is(':visible')) {
            $('#cpm-edit-modal').hide();
        }
    });

    // Modal dışına tıklayarak kapatma
    $('#cpm-edit-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
});