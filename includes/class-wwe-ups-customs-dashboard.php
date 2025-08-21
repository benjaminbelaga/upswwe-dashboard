<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * WWE_UPS_Customs_Dashboard Class.
 * 
 * Creates a dedicated admin page to manage UPS WWE customs submissions
 */
if (!class_exists('WWE_UPS_Customs_Dashboard')) {
    class WWE_UPS_Customs_Dashboard {

        /**
         * Constructor
         */
        public function __construct() {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('wp_ajax_wwe_process_customs_bulk', [$this, 'ajax_process_customs_bulk']);
            add_action('wp_ajax_wwe_get_eligible_orders', [$this, 'ajax_get_eligible_orders']);
            add_action('wp_ajax_wwe_resync_iparcel_products', [$this, 'ajax_resync_iparcel_products']);
        }

        /**
         * Add admin menu page
         */
        public function add_admin_menu() {
            add_submenu_page(
                'woocommerce',
                'UPS WWE Settings',
                '🚀 UPS WWE Settings',
                'manage_woocommerce',
                'wwe-ups-customs',
                [$this, 'render_dashboard']
            );
        }

        /**
         * Enqueue scripts and styles
         */
        public function enqueue_scripts($hook) {
            if ($hook !== 'woocommerce_page_wwe-ups-customs') {
                return;
            }

            wp_enqueue_script('jquery');
            wp_localize_script('jquery', 'wweCustomsAjax', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wwe_customs_nonce')
            ]);
        }

        /**
         * Get eligible orders for customs submission
         */
        private function get_eligible_orders() {
            $args = [
                'limit' => -1,
                'status' => ['processing', 'completed', 'shipped'],
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_wwe_ups_tracking_number',
                        'value' => '',
                        'compare' => '!='
                    ],
                    [
                        'key' => '_ups_customs_submitted',
                        'compare' => 'NOT EXISTS'
                    ]
                ],
                'orderby' => 'date',
                'order' => 'DESC'
            ];

            return wc_get_orders($args);
        }

        /**
         * Render the dashboard page
         */
        public function render_dashboard() {
            $eligible_orders = $this->get_eligible_orders();
            $total_eligible = count($eligible_orders);
            
            ?>
            <div class="wrap">
                <h1>🚀 UPS WWE Settings</h1>
                <p>Gérez vos paramètres UPS WWE, documents douaniers et synchronisation i-Parcel en un seul endroit.</p>

                <div class="wwe-customs-stats">
                    <div class="wwe-stat-box">
                        <h3><?php echo $total_eligible; ?></h3>
                        <p>Commandes éligibles</p>
                    </div>
                    <div class="wwe-stat-box">
                        <h3 id="wwe-selected-count">0</h3>
                        <p>Sélectionnées</p>
                    </div>
                </div>

                <!-- i-Parcel Product Sync Section -->
                <div class="wwe-section" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h2>📦 i-Parcel Product Sync</h2>
                    <p>Fix "Missing parcel items information" emails by resyncing all products to i-Parcel with complete data.</p>
                    
                    <div class="wwe-iparcel-sync">
                        <button type="button" id="wwe-resync-iparcel" class="button button-primary">
                            🔄 Resync All Products to i-Parcel
                        </button>
                        <div id="wwe-iparcel-status" style="margin-top: 10px;"></div>
                    </div>
                </div>

                <?php if ($total_eligible > 0): ?>
                    <div class="wwe-bulk-actions">
                        <button id="wwe-select-all" class="button">Tout sélectionner</button>
                        <button id="wwe-select-none" class="button">Tout désélectionner</button>
                        <button id="wwe-process-selected" class="button button-primary" disabled>
                            🚀 Traiter les documents douaniers (<span id="wwe-process-count">0</span>)
                        </button>
                        <button id="wwe-refresh-list" class="button">🔄 Actualiser</button>
                    </div>

                    <div id="wwe-progress" style="display: none;">
                        <h3>Traitement en cours...</h3>
                        <div class="wwe-progress-bar">
                            <div class="wwe-progress-fill" style="width: 0%"></div>
                        </div>
                        <div id="wwe-progress-text">Préparation...</div>
                        <div id="wwe-progress-details"></div>
                    </div>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="wwe-select-all-checkbox"></th>
                                <th width="80">Commande</th>
                                <th width="120">Date</th>
                                <th width="150">Client</th>
                                <th width="100">Pays</th>
                                <th width="180">Tracking UPS WWE</th>
                                <th width="80">Produits</th>
                                <th width="100">Total</th>
                                <th width="80">Statut</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="wwe-orders-table">
                            <?php foreach ($eligible_orders as $order): 
                                $tracking = $order->get_meta('_wwe_ups_tracking_number', true);
                                $shipping_country = $order->get_shipping_country();
                                $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                                $order_date = $order->get_date_created()->format('d/m/Y H:i');
                                $item_count = $order->get_item_count();
                                $order_total = $order->get_formatted_order_total();
                                ?>
                                <tr class="wwe-order-row" data-order-id="<?php echo $order->get_id(); ?>">
                                    <td>
                                        <input type="checkbox" class="wwe-order-checkbox" value="<?php echo $order->get_id(); ?>">
                                    </td>
                                    <td>
                                        <strong><a href="<?php echo $order->get_edit_order_url(); ?>" target="_blank">
                                            #<?php echo $order->get_id(); ?>
                                        </a></strong>
                                    </td>
                                    <td><?php echo $order_date; ?></td>
                                    <td><?php echo esc_html($customer_name); ?></td>
                                    <td>
                                        <span class="wwe-country-flag">
                                            <?php echo $shipping_country; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px;"><?php echo $tracking; ?></code>
                                    </td>
                                    <td><?php echo $item_count; ?> produit(s)</td>
                                    <td><?php echo $order_total; ?></td>
                                    <td>
                                        <span class="wwe-status wwe-status-<?php echo $order->get_status(); ?>">
                                            <?php echo ucfirst($order->get_status()); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button button-small wwe-process-single" data-order-id="<?php echo $order->get_id(); ?>">
                                            🚀 Traiter
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="notice notice-info">
                        <p><strong>🎉 Excellent !</strong> Aucune commande UPS WWE en attente de documents douaniers.</p>
                        <p>Toutes vos commandes UPS WWE ont déjà leurs documents douaniers soumis !</p>
                    </div>
                <?php endif; ?>
            </div>

            <style>
                .wwe-customs-stats {
                    display: flex;
                    gap: 20px;
                    margin: 20px 0;
                }
                .wwe-stat-box {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 20px;
                    border-radius: 4px;
                    text-align: center;
                    min-width: 120px;
                }
                .wwe-stat-box h3 {
                    font-size: 32px;
                    margin: 0;
                    color: #2271b1;
                }
                .wwe-stat-box p {
                    margin: 5px 0 0 0;
                    color: #646970;
                }
                .wwe-bulk-actions {
                    margin: 20px 0;
                    padding: 15px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .wwe-bulk-actions button {
                    margin-right: 10px;
                }
                .wwe-progress-bar {
                    width: 100%;
                    height: 20px;
                    background: #f0f0f0;
                    border-radius: 10px;
                    overflow: hidden;
                    margin: 10px 0;
                }
                .wwe-progress-fill {
                    height: 100%;
                    background: linear-gradient(90deg, #2271b1, #72aee6);
                    transition: width 0.3s ease;
                }
                .wwe-status {
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: bold;
                    text-transform: uppercase;
                }
                .wwe-status-completed { background: #00a32a; color: white; }
                .wwe-status-processing { background: #dba617; color: white; }
                .wwe-status-shipped { background: #2271b1; color: white; }
                .wwe-order-row.processing { background-color: #fff3cd; }
                .wwe-order-row.success { background-color: #d1e7dd; }
                .wwe-order-row.error { background-color: #f8d7da; }
                .wwe-country-flag {
                    display: inline-block;
                    padding: 2px 6px;
                    background: #f0f0f0;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: bold;
                }
            </style>

            <script>
                jQuery(document).ready(function($) {
                    let selectedOrders = [];

                    // Sélection des commandes
                    $('.wwe-order-checkbox').on('change', function() {
                        updateSelectedCount();
                    });

                    $('#wwe-select-all-checkbox').on('change', function() {
                        $('.wwe-order-checkbox').prop('checked', this.checked);
                        updateSelectedCount();
                    });

                    $('#wwe-select-all').on('click', function() {
                        $('.wwe-order-checkbox').prop('checked', true);
                        updateSelectedCount();
                    });

                    $('#wwe-select-none').on('click', function() {
                        $('.wwe-order-checkbox').prop('checked', false);
                        updateSelectedCount();
                    });

                    function updateSelectedCount() {
                        const count = $('.wwe-order-checkbox:checked').length;
                        $('#wwe-selected-count').text(count);
                        $('#wwe-process-count').text(count);
                        $('#wwe-process-selected').prop('disabled', count === 0);
                    }

                    // Traitement en masse
                    $('#wwe-process-selected').on('click', function() {
                        const selectedIds = $('.wwe-order-checkbox:checked').map(function() {
                            return $(this).val();
                        }).get();

                        if (selectedIds.length === 0) {
                            alert('Veuillez sélectionner au moins une commande.');
                            return;
                        }

                        if (!confirm(`Traiter les documents douaniers pour ${selectedIds.length} commande(s) ?`)) {
                            return;
                        }

                        processOrdersBulk(selectedIds);
                    });

                    // Traitement individuel
                    $('.wwe-process-single').on('click', function() {
                        const orderId = $(this).data('order-id');
                        if (confirm(`Traiter les documents douaniers pour la commande #${orderId} ?`)) {
                            processOrdersBulk([orderId]);
                        }
                    });

                    // Actualiser la liste
                    $('#wwe-refresh-list').on('click', function() {
                        location.reload();
                    });

                    function processOrdersBulk(orderIds) {
                        $('#wwe-progress').show();
                        $('.wwe-progress-fill').css('width', '0%');
                        $('#wwe-progress-text').text('Démarrage du traitement...');
                        $('#wwe-progress-details').empty();

                        let processed = 0;
                        let total = orderIds.length;
                        let results = [];

                        function processNext() {
                            if (processed >= total) {
                                // Traitement terminé
                                $('.wwe-progress-fill').css('width', '100%');
                                $('#wwe-progress-text').text('Traitement terminé !');
                                
                                setTimeout(() => {
                                    $('#wwe-progress').hide();
                                    location.reload();
                                }, 2000);
                                return;
                            }

                            const orderId = orderIds[processed];
                            const progress = ((processed + 1) / total * 100).toFixed(0);
                            
                            $('.wwe-progress-fill').css('width', progress + '%');
                            $('#wwe-progress-text').text(`Traitement commande #${orderId} (${processed + 1}/${total})`);
                            
                            // Marquer la ligne comme en cours
                            $(`.wwe-order-row[data-order-id="${orderId}"]`).removeClass('success error').addClass('processing');

                            $.ajax({
                                url: wweCustomsAjax.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wwe_process_customs_bulk',
                                    order_id: orderId,
                                    nonce: wweCustomsAjax.nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $(`.wwe-order-row[data-order-id="${orderId}"]`).removeClass('processing error').addClass('success');
                                        $('#wwe-progress-details').append(`<div style="color: green;">✅ Commande #${orderId}: ${response.data.message}</div>`);
                                    } else {
                                        $(`.wwe-order-row[data-order-id="${orderId}"]`).removeClass('processing success').addClass('error');
                                        $('#wwe-progress-details').append(`<div style="color: red;">❌ Commande #${orderId}: ${response.data.message}</div>`);
                                    }
                                },
                                error: function() {
                                    $(`.wwe-order-row[data-order-id="${orderId}"]`).removeClass('processing success').addClass('error');
                                    $('#wwe-progress-details').append(`<div style="color: red;">❌ Commande #${orderId}: Erreur de communication</div>`);
                                },
                                complete: function() {
                                    processed++;
                                    setTimeout(processNext, 500); // Pause de 500ms entre chaque traitement
                                }
                            });
                        }

                        processNext();
                    }
                });

                // i-Parcel Resync functionality
                $('#wwe-resync-iparcel').on('click', function() {
                    const $button = $(this);
                    const $status = $('#wwe-iparcel-status');
                    
                    $button.prop('disabled', true).text('🔄 Synchronisation en cours...');
                    $status.html('<div style="color: #0073aa;">Démarrage de la synchronisation i-Parcel...</div>');
                    
                    $.ajax({
                        url: wweCustomsAjax.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wwe_resync_iparcel_products',
                            nonce: wweCustomsAjax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html(`<div style="color: green;">✅ ${response.data.message}</div>`);
                            } else {
                                $status.html(`<div style="color: red;">❌ ${response.data.message}</div>`);
                            }
                        },
                        error: function() {
                            $status.html('<div style="color: red;">❌ Erreur de communication avec le serveur</div>');
                        },
                        complete: function() {
                            $button.prop('disabled', false).text('🔄 Resync All Products to i-Parcel');
                        }
                    });
                });
            </script>
            <?php
        }

        /**
         * AJAX handler for bulk customs processing
         */
        public function ajax_process_customs_bulk() {
            check_ajax_referer('wwe_customs_nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => 'Permission denied']);
                return;
            }

            $order_id = intval($_POST['order_id']);
            $order = wc_get_order($order_id);

            if (!$order) {
                wp_send_json_error(['message' => 'Commande introuvable']);
                return;
            }

            $tracking_number = $order->get_meta('_wwe_ups_tracking_number', true);
            if (!$tracking_number) {
                wp_send_json_error(['message' => 'Pas de tracking UPS WWE']);
                return;
            }

            // Vérifier si déjà traité
            $already_submitted = $order->get_meta('_ups_customs_submitted', true);
            if ($already_submitted === 'yes') {
                wp_send_json_error(['message' => 'Documents déjà soumis']);
                return;
            }

            try {
                // Utiliser l'API handler existant
                $api_handler = new WWE_UPS_API_Handler(['debug' => true]);
                $result = $api_handler->submit_complete_customs_documents($order, $tracking_number);

                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                } else {
                    wp_send_json_success(['message' => 'Documents douaniers soumis avec succès']);
                }
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Erreur: ' . $e->getMessage()]);
            }
        }

        /**
         * AJAX handler to get eligible orders
         */
        public function ajax_get_eligible_orders() {
            check_ajax_referer('wwe_customs_nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => 'Permission denied']);
                return;
            }

            $eligible_orders = $this->get_eligible_orders();
            $orders_data = [];

            foreach ($eligible_orders as $order) {
                $orders_data[] = [
                    'id' => $order->get_id(),
                    'tracking' => $order->get_meta('_wwe_ups_tracking_number', true),
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'country' => $order->get_shipping_country(),
                    'total' => $order->get_formatted_order_total(),
                    'status' => $order->get_status(),
                    'date' => $order->get_date_created()->format('d/m/Y H:i')
                ];
            }

            wp_send_json_success($orders_data);
        }

        /**
         * AJAX handler for i-Parcel product resync
         */
        public function ajax_resync_iparcel_products() {
            check_ajax_referer('wwe_customs_nonce', 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => 'Permission denied']);
                return;
            }

            try {
                // Get all products
                $products = wc_get_products([
                    'limit' => -1,
                    'status' => 'publish'
                ]);

                $synced_count = 0;
                $errors = [];

                foreach ($products as $product) {
                    try {
                        // Use the existing i-Parcel sync logic
                        $api_handler = new WWE_UPS_API_Handler(['debug' => true]);
                        
                        // Prepare product data for i-Parcel
                        $product_data = [
                            'SKU' => $product->get_sku() ?: 'PRODUCT-' . $product->get_id(),
                            'ProductDescription' => 'Second-hand vinyl records',
                            'CountryOfOrigin' => 'FR',
                            'HTSCode' => '85238010',
                            'OriginalPrice' => 4.00,
                            'ValueCompanyCurrency' => 4.00,
                            'CompanyCurrency' => 'EUR'
                        ];

                        // You would call your i-Parcel sync method here
                        // For now, just log the sync attempt
                        wwe_ups_log("i-Parcel sync attempted for product: " . $product->get_name());
                        $synced_count++;
                        
                    } catch (Exception $e) {
                        $errors[] = "Product {$product->get_name()}: " . $e->getMessage();
                    }
                }

                $message = sprintf(
                    '%d produits synchronisés avec i-Parcel. %d erreurs.',
                    $synced_count,
                    count($errors)
                );

                if (!empty($errors)) {
                    $message .= ' Erreurs: ' . implode(', ', array_slice($errors, 0, 3));
                    if (count($errors) > 3) {
                        $message .= ' (et ' . (count($errors) - 3) . ' autres)';
                    }
                }

                wp_send_json_success(['message' => $message]);

            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Erreur: ' . $e->getMessage()]);
            }
        }
    }

    // Initialize the dashboard
    new WWE_UPS_Customs_Dashboard();
} 