jQuery(document).ready(function($) {
    'use strict';

    // Bail if script parameters are not injected
    if (typeof wwe_ups_admin_params === 'undefined') {
        return;
    }

    /* ---------- paramètres localisés ---------- */
    var params         = wwe_ups_admin_params;
    var ajaxUrl        = params.ajax_url;
    var generateNonce  = params.generate_nonce;
    var voidNonce      = params.void_nonce;
    var simulateNonce  = params.simulate_nonce;
    var resetNonce     = params.reset_nonce;
    var downloadAllNonce = params.download_all_nonce;

    /* ---------- helpers ---------- */
    var metaBox  = $('#wwe_ups_metabox');
    var actionsC = metaBox.find('.wwe-ups-metabox-actions');

    function showLoading($btn) {
        $btn.prop('disabled', true).css('opacity', .5);
        actionsC.find('.spinner').remove();
        actionsC.append('<span class="spinner is-active" style="vertical-align:middle;"></span>');
    }
    function hideLoading($btn) {
        actionsC.find('.spinner').remove();
        if ($btn) $btn.prop('disabled', false).css('opacity', 1);
    }
    function flash(msg, err) {
        metaBox.find('.wwe-ups-message-area').remove();
        var cls = err ? 'wwe-ups-error-message'
                      : 'wwe-ups-success-message notice notice-success inline';
        metaBox.find('.inside')
               .prepend('<div class="wwe-ups-message-area '+cls+'"><p>'+msg+'</p></div>');
    }

    /* remise à zéro de la metabox quand l’annulation est confirmée */
    function handleVoidSuccess(orderId){
        flash('Envoi annulé ! Rechargement…');
        location.reload();
    }

    /* ---------- 1. Générer l’étiquette ---------- */
    $(document).on('click', '#wwe_ups_metabox .wwe-ups-generate-label-button', function(e) {
        e.preventDefault();
        var $btn = $(this), orderId = $btn.data('order-id');

        var data = {
            action:   'wwe_ups_generate_label',
            order_id: orderId,
            security: generateNonce
        };
        showLoading($btn); flash('');
        $.post(ajaxUrl, data, function(res) {
            hideLoading($btn);
            if (res.success) { flash('Étiquette générée ! Rechargement…'); location.reload(); }
            else             { flash('Erreur : '+(res.data.message||'inconnue'), true); }
        }, 'json').fail(function(jq) {
            hideLoading($btn);
            var errorMsg = 'Requête AJAX échouée.';
            // Try to extract more specific error from response
            if (jq.responseJSON && jq.responseJSON.data && jq.responseJSON.data.message) {
                errorMsg = jq.responseJSON.data.message;
            } else if (jq.responseText) {
                // If response is not JSON, try to parse text
                try {
                    var parsed = JSON.parse(jq.responseText);
                    if (parsed.data && parsed.data.message) {
                        errorMsg = parsed.data.message;
                    }
                } catch (e) {
                    // If not JSON, show HTTP status
                    if (jq.status) {
                        errorMsg += ' (HTTP ' + jq.status + ')';
                    }
                }
            }
            flash('Erreur : ' + errorMsg, true);
        });
    });

    /* ---------- 2. Annuler l’envoi ---------- */
    $(document).on('click', '#wwe_ups_metabox .wwe-ups-void-button', function(e) {
        e.preventDefault();
        if (!confirm('Confirmer l’annulation de cet envoi ?')) return;
        var $btn = $(this),
            orderId = $btn.data('order-id'),
            shipId = $btn.data('shipment-id');

        var data = {
            action:       'wwe_ups_void_shipment',
            order_id:     orderId,
            shipment_id:  shipId,
            security:     voidNonce
        };
        showLoading($btn); flash('');
        $.post(ajaxUrl, data, function(res) {
            hideLoading($btn);
            var msg = res.data && res.data.message ? res.data.message : '';
            if (res.success || /voided/i.test(msg)) {
                handleVoidSuccess(orderId);
            } else {
                flash('Erreur : ' + (msg || 'Annulation impossible'), true);
            }
        }, 'json').fail(function(jq) {
            hideLoading($btn);
            flash('Requête AJAX échouée.', true);
        });
    });

    /* ---------- 3. Simuler le tarif ---------- */
    $(document).on('click', '#wwe_ups_metabox .wwe-ups-simulate-rate-button', function(e) {
        e.preventDefault();
        var $btn    = $(this),
            orderId = $btn.data('order-id'),
            $res    = metaBox.find('.wwe-simulation-result');

        var data = {
            action:   'wwe_ups_simulate_rate',
            order_id: orderId,
            security: simulateNonce
        };
        $res.text('Simulation…'); showLoading($btn); flash('');
        $.post(ajaxUrl, data, function(res) {
            hideLoading($btn);
            if (res.success) {
                $res.html(res.data.message);
            } else {
                $res.html('<span style="color:red;">Erreur : '+(res.data.message||'impossible')+'</span>');
            }
        }, 'json').fail(function(jq) {
            hideLoading($btn);
            $res.html('<span style="color:red;">Erreur : requête AJAX échouée</span>');
        });
    });

    /* ---------- 4. Réinitialiser l’envoi ---------- */
    $(document).on('click', '#wwe_ups_metabox .wwe-ups-reset-button', function(e) {
        e.preventDefault();
        var $btn    = $(this),
            orderId = $btn.data('order-id');

        var data = {
            action:   'wwe_ups_reset_shipment',
            order_id: orderId,
            security: resetNonce
        };
        showLoading($btn); flash('');
        $.post(ajaxUrl, data, function(res) {
            hideLoading($btn);
            if (res.success) {
                flash('Réinitialisation réussie.');
                actionsC.empty()
                        .append('<button class="button wwe-ups-generate-label-button" data-order-id="'+orderId+'">Générer une étiquette</button>');
            } else {
                flash('Erreur : '+(res.data.message||'impossible'), true);
            }
        }, 'json').fail(function(jq) {
            hideLoading($btn);
            flash('Requête AJAX échouée.', true);
        });
    });

    /* ---------- 5. Download All Labels (PDF) ---------- */
    $(document).on('click', '#wwe_ups_metabox .wwe-ups-download-all-labels', function(e) {
        e.preventDefault();
        var $btn = $(this), orderId = $btn.data('order-id');

        // Create a form and submit it to trigger file download
        var form = $('<form>', {
            'method': 'POST',
            'action': ajaxUrl,
            'target': '_blank'
        });
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'wwe_ups_download_all_labels'
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'order_id',
            'value': orderId
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'security',
            'value': downloadAllNonce
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
        
        flash('Téléchargement du PDF en cours...');
    });

    /* ---------- 6. Resync All Products to i-Parcel ---------- */
    $(document).on('click', '#wwe-resync-iparcel', function(e) {
        e.preventDefault();
        
        if (!confirm('This will resync ALL products to i-Parcel. This may take several minutes. Continue?')) {
            return;
        }
        
        var $btn = $(this);
        var $status = $('#wwe-resync-status');
        
        $btn.prop('disabled', true).text('Resyncing...');
        $status.html('<span style="color: orange;">⏳ Processing all products...</span>');
        
        $.post(ajaxUrl, {
            action: 'wwe_resync_products',
            _wpnonce: params.generate_nonce // Reuse existing nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Resync All Products to i-Parcel');
            
            if (response.success) {
                $status.html('<span style="color: green;">✅ ' + response.data + '</span>');
                setTimeout(function() {
                    $status.empty();
                }, 10000);
            } else {
                $status.html('<span style="color: red;">❌ Error: ' + (response.data || 'Unknown error') + '</span>');
            }
        }, 'json').fail(function() {
            $btn.prop('disabled', false).text('Resync All Products to i-Parcel');
            $status.html('<span style="color: red;">❌ AJAX request failed</span>');
        });
    });
});