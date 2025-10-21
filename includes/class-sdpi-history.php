<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SDPI_History {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sdpi_history';

        // Ensure table schema is up to date
        $this->maybe_upgrade_schema();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_sdpi_get_history', array($this, 'ajax_get_history'));
        add_action('wp_ajax_sdpi_delete_history_item', array($this, 'ajax_delete_history_item'));
        add_action('wp_ajax_sdpi_export_history', array($this, 'ajax_export_history'));
        add_action('wp_ajax_sdpi_get_quote_details', array($this, 'ajax_get_quote_details'));
        add_action('wp_ajax_sdpi_bulk_send_zapier', array($this, 'ajax_bulk_send_zapier'));
        add_action('wp_ajax_sdpi_bulk_delete_history', array($this, 'ajax_bulk_delete_history'));
    }
    
    /**
     * Create history table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            flow_status varchar(20) NOT NULL DEFAULT 'inicial',
            user_ip varchar(45) NOT NULL,
            user_agent text,
            pickup_zip varchar(10),
            delivery_zip varchar(10),
            pickup_city varchar(100),
            delivery_city varchar(100),
            trailer_type varchar(20),
            vehicle_type varchar(50),
            vehicle_inoperable tinyint(1) DEFAULT 0,
            vehicle_make varchar(50),
            vehicle_model varchar(50),
            vehicle_year varchar(4),
            vehicle_electric tinyint(1) DEFAULT 0,
            pickup_contact_name varchar(100),
            pickup_contact_street varchar(255),
            pickup_contact_type varchar(50),
            delivery_contact_name varchar(100),
            delivery_contact_street varchar(255),
            additional_shipping longtext,
            client_name varchar(100),
            client_phone varchar(20),
            client_email varchar(100),
            client_info_captured_at datetime,
            api_response text,
            api_price decimal(10,2),
            api_confidence decimal(5,2),
            api_price_per_mile decimal(8,4),
            final_price decimal(10,2),
            company_profit decimal(10,2),
            confidence_adjustment decimal(10,2),
            maritime_involved tinyint(1) DEFAULT 0,
            maritime_direction varchar(20) DEFAULT NULL,
            maritime_cost decimal(10,2),
            us_port_name varchar(100),
            us_port_zip varchar(10),
            total_terrestrial_cost decimal(10,2),
            total_maritime_cost decimal(10,2),
            inoperable_fee decimal(10,2) DEFAULT 0,
            maritime_details longtext,
            documentation_files longtext,
            price_breakdown text,
            error_message text,
            zapier_status varchar(20) DEFAULT 'pending',
            zapier_last_sent_at datetime DEFAULT NULL,
            zapier_scheduled_at datetime DEFAULT NULL,
            zapier_trigger_reason varchar(20) DEFAULT NULL,
            zapier_last_error text,
            status_updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_ip (user_ip),
            KEY pickup_zip (pickup_zip),
            KEY delivery_zip (delivery_zip),
            KEY created_at (created_at),
            KEY flow_status (flow_status),
            KEY maritime_involved (maritime_involved),
            KEY maritime_direction (maritime_direction)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'SDPI Historial',
            'SDPI',
            'manage_options',
            'sdpi-history',
            array($this, 'history_page'),
            'dashicons-chart-line',
            30
        );
        
        add_submenu_page(
            'sdpi-history',
            'Historial de Cotizaciones',
            'Historial',
            'manage_options',
            'sdpi-history',
            array($this, 'history_page')
        );
        
        add_submenu_page(
            'sdpi-history',
            'Estadísticas SDPI',
            'Estadísticas',
            'manage_options',
            'sdpi-statistics',
            array($this, 'statistics_page')
        );

        add_submenu_page(
            'sdpi-history',
            'Enviar a Zapier (manual)',
            'Enviar a Zapier',
            'manage_options',
            'sdpi-send-zapier',
            array($this, 'send_zapier_page')
        );
    }
    
    /**
     * History page
     */
    public function history_page() {
        ?>
        <div class="wrap">
            <h1>SDPI - Historial de Cotizaciones</h1>
            
            <div class="sdpi-history-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="sdpi-history">
                    
                    <div class="sdpi-filter-row">
                        <div class="sdpi-filter-group">
                            <label for="date_from">Desde:</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($_GET['date_from'] ?? ''); ?>">
                        </div>
                        <div class="sdpi-filter-group">
                            <label for="flow_status">Estado del Flujo:</label>
                            <select id="flow_status" name="flow_status">
                                <option value="">Todos los estados</option>
                                <option value="inicial" <?php selected($_GET['flow_status'] ?? '', 'inicial'); ?>>Inicial</option>
                                <option value="cotizador" <?php selected($_GET['flow_status'] ?? '', 'cotizador'); ?>>Cotizador</option>
                                <option value="checkout" <?php selected($_GET['flow_status'] ?? '', 'checkout'); ?>>Checkout</option>
                                <option value="completado" <?php selected($_GET['flow_status'] ?? '', 'completado'); ?>>Completado</option>
                            </select>
                        </div>
                        
                        <div class="sdpi-filter-group">
                            <label for="date_to">Hasta:</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($_GET['date_to'] ?? ''); ?>">
                        </div>
                        
                        <div class="sdpi-filter-group">
                            <label for="maritime">Tipo:</label>
                            <select id="maritime" name="maritime">
                                <option value="">Todos</option>
                                <option value="1" <?php selected($_GET['maritime'] ?? '', '1'); ?>>Marítimo</option>
                                <option value="0" <?php selected($_GET['maritime'] ?? '', '0'); ?>>Terrestre</option>
                            </select>
                        </div>
                        
                        <div class="sdpi-filter-group">
                            <label for="search">Buscar:</label>
                            <input type="text" id="search" name="search" placeholder="ZIP, ciudad, marca..." value="<?php echo esc_attr($_GET['search'] ?? ''); ?>">
                        </div>
                        
                        <div class="sdpi-filter-group">
                            <input type="submit" class="button button-primary" value="Filtrar">
                            <a href="<?php echo admin_url('admin.php?page=sdpi-history'); ?>" class="button">Limpiar</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="sdpi-history-actions">
                <button id="export-history" class="button button-secondary">Exportar CSV</button>
                <button id="refresh-history" class="button button-secondary">Actualizar</button>
            </div>
            
            <div id="sdpi-history-table-container">
                <?php $this->render_history_table(); ?>
            </div>
        </div>
        
        <style>
        .sdpi-history-filters {
            background: #fff;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .sdpi-filter-row {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .sdpi-filter-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }
        
        .sdpi-filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .sdpi-history-actions {
            margin: 20px 0;
        }
        
        .sdpi-history-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        
        .sdpi-history-table th,
        .sdpi-history-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .sdpi-history-table th {
            background: #f1f1f1;
            font-weight: 600;
        }
        
        .sdpi-history-table tr:hover {
            background: #f9f9f9;
        }
        
        .sdpi-maritime-badge {
            background: #0073aa;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .sdpi-terrestrial-badge {
            background: #00a32a;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .sdpi-price {
            font-weight: 600;
            color: #0073aa;
        }
        
        .sdpi-error {
            color: #d63638;
            font-style: italic;
        }
        
        .sdpi-actions {
            display: flex;
            gap: 5px;
        }
        
        .sdpi-actions button {
            padding: 4px 8px;
            font-size: 11px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Export functionality
            $('#export-history').on('click', function() {
                var params = new URLSearchParams(window.location.search);
                params.set('action', 'export');
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?' + params.toString();
            });
            
            // Refresh functionality
            $('#refresh-history').on('click', function() {
                location.reload();
            });
        });
        
        function sdpiViewDetails(id) {
            // Show loading
            jQuery('#sdpi-details-modal').remove();
            jQuery('body').append(
                '<div id="sdpi-details-modal" class="sdpi-modal-wrapper" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">' +
                    '<div class="sdpi-modal-backdrop" onclick="sdpiCloseModal()" style="position:absolute;inset:0;background:rgba(23,32,95,0.55);backdrop-filter:blur(2px);"></div>' +
                    '<div class="sdpi-modal-dialog" style="position:relative;width:min(900px,100%);max-height:90vh;overflow-y:auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 30px 70px rgba(23,32,95,0.35);display:flex;flex-direction:column;gap:24px;">' +
                        '<div class="sdpi-modal-loading" style="min-height:160px;display:flex;align-items:center;justify-content:center;font-weight:600;color:#17205F;font-size:16px;">Cargando detalles...</div>' +
                    '</div>' +
                '</div>'
            );
            
            // Fetch details via AJAX
            jQuery.post(ajaxurl, {
                action: 'sdpi_get_quote_details',
                id: id,
                nonce: '<?php echo wp_create_nonce('sdpi_get_details'); ?>'
            }, function(response) {
                if (response.success) {
                    jQuery('#sdpi-details-modal .sdpi-modal-dialog').html(response.data);
                } else {
                    jQuery('#sdpi-details-modal .sdpi-modal-dialog').html(
                        '<div class="sdpi-modal-content" style="display:flex;flex-direction:column;gap:18px;">' +
                            '<h3 class="sdpi-modal-title" style="margin:0;font-size:20px;color:#17205F;">Error</h3>' +
                            '<p class="sdpi-modal-message" style="margin:0;color:#5c628f;">' + response.data + '</p>' +
                            '<div class="sdpi-modal-actions" style="display:flex;justify-content:flex-end;">' +
                                '<button type="button" class="sdpi-modal-close" onclick="sdpiCloseModal()" style="background:#d63638;color:#fff;border:none;border-radius:12px;padding:10px 18px;font-weight:600;cursor:pointer;">Cerrar</button>' +
                            '</div>' +
                        '</div>'
                    );
                }
            });
        }
        
        function sdpiCloseModal() {
            jQuery('#sdpi-details-modal').remove();
        }
        </script>
        <?php
    }

    /**
     * Manual send to Zapier page
     */
    public function send_zapier_page() {
        if (!current_user_can('manage_options')) { return; }

        $message = '';
        $notice_type = 'info';

        if (isset($_POST['sdpi_manual_send'])) {
            check_admin_referer('sdpi_manual_send_action', 'sdpi_manual_send_nonce');
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');

            if (!empty($session_id)) {
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE session_id = %s", $session_id));

                if ($row) {
                    $zapier_args = $this->build_zapier_arguments_from_history_row($row);

                    if ($zapier_args) {
                        $form = new SDPI_Form();

                        try {
                            $result = $form->send_to_zapier(
                                $zapier_args['pickup_zip'],
                                $zapier_args['delivery_zip'],
                                $zapier_args['trailer_type'],
                                $zapier_args['vehicle_type'],
                                $zapier_args['vehicle_inoperable'],
                                $zapier_args['vehicle_electric'],
                                $zapier_args['vehicle_make'],
                                $zapier_args['vehicle_model'],
                                $zapier_args['vehicle_year'],
                                $zapier_args['final'],
                                $zapier_args['involves_maritime'],
                                $zapier_args['extra']
                            );

                            if (is_wp_error($result)) {
                                $error_message = $result->get_error_message();
                                $this->mark_zapier_status($row->session_id, 'error', array(
                                    'trigger_reason' => 'manual',
                                    'last_error' => $error_message
                                ));
                                $message = sprintf('Error al enviar a Zapier: %s', $error_message);
                                $notice_type = 'error';
                            } else {
                                $this->mark_zapier_status($row->session_id, 'sent', array(
                                    'trigger_reason' => 'manual',
                                    'last_error' => ''
                                ));
                                $message = 'Datos enviados a Zapier.';
                                $notice_type = 'success';
                            }
                        } catch (Exception $e) {
                            $error_message = $e->getMessage();
                            $this->mark_zapier_status($row->session_id, 'error', array(
                                'trigger_reason' => 'manual',
                                'last_error' => $error_message
                            ));
                            $message = sprintf('Error al enviar a Zapier: %s', $error_message);
                            $notice_type = 'error';
                        }
                    } else {
                        $message = 'No se pudo reconstruir el payload para Zapier.';
                        $notice_type = 'error';
                    }
                } else {
                    $message = 'Sesión no encontrada.';
                    $notice_type = 'error';
                }
            } else {
                $message = 'Ingrese un Session ID válido.';
                $notice_type = 'error';
            }
        }

        $notice_class = 'notice-info';
        if ($notice_type === 'success') {
            $notice_class = 'notice-success';
        } elseif ($notice_type === 'error') {
            $notice_class = 'notice-error';
        }

        ?>
        <div class="wrap">
            <h1>Enviar a Zapier (manual)</h1>
            <?php if (!empty($message)): ?>
                <div class="notice <?php echo esc_attr($notice_class); ?>"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('sdpi_manual_send_action', 'sdpi_manual_send_nonce'); ?>
                <p>
                    <label for="session_id">Session ID:</label><br>
                    <input type="text" id="session_id" name="session_id" class="regular-text" placeholder="UUID de sesión" required>
                </p>
                <p>
                    <input type="submit" name="sdpi_manual_send" class="button button-primary" value="Enviar ahora">
                </p>
            </form>
        </div>
        <?php
    }

    private function build_zapier_arguments_from_history_row($row) {
        if (!$row) {
            return null;
        }

        $shipping_details = array();
        if (!empty($row->additional_shipping)) {
            $decoded_shipping = json_decode($row->additional_shipping, true);
            if (is_array($decoded_shipping)) {
                $shipping_details = $decoded_shipping;
            }
        }

        if (empty($shipping_details)) {
            $shipping_details = array(
                'pickup' => array(
                    'name' => $row->pickup_contact_name,
                    'street' => $row->pickup_contact_street,
                    'city' => $row->pickup_city,
                    'zip' => $row->pickup_zip,
                    'type' => $row->pickup_contact_type
                ),
                'delivery' => array(
                    'name' => $row->delivery_contact_name,
                    'street' => $row->delivery_contact_street,
                    'city' => $row->delivery_city,
                    'zip' => $row->delivery_zip
                )
            );
        }

        if (empty($shipping_details['saved_at']) && !empty($row->status_updated_at)) {
            $shipping_details['saved_at'] = $row->status_updated_at;
        }

        $maritime_details = array();
        if (!empty($row->maritime_details)) {
            $decoded_maritime = json_decode($row->maritime_details, true);
            if (is_array($decoded_maritime)) {
                $maritime_details = $decoded_maritime;
            }
        }

        $documentation_files = $this->sanitize_documentation_entries($row->documentation_files ?? array());

        $final = array(
            'final_price' => floatval($row->final_price),
            'base_price' => floatval($row->api_price),
            'confidence_percentage' => floatval($row->api_confidence),
            'company_profit' => floatval($row->company_profit),
            'confidence_adjustment' => floatval($row->confidence_adjustment),
            'maritime_involved' => intval($row->maritime_involved) === 1,
            'maritime_cost' => floatval($row->maritime_cost),
            'terrestrial_cost' => floatval($row->total_terrestrial_cost),
            'us_port' => !empty($row->us_port_name) ? array('port' => $row->us_port_name, 'zip' => $row->us_port_zip) : null
        );

        return array(
            'pickup_zip' => $row->pickup_zip,
            'delivery_zip' => $row->delivery_zip,
            'trailer_type' => $row->trailer_type,
            'vehicle_type' => $row->vehicle_type,
            'vehicle_inoperable' => intval($row->vehicle_inoperable) === 1,
            'vehicle_electric' => intval($row->vehicle_electric) === 1,
            'vehicle_make' => $row->vehicle_make,
            'vehicle_model' => $row->vehicle_model,
            'vehicle_year' => $row->vehicle_year,
            'final' => $final,
            'involves_maritime' => intval($row->maritime_involved) === 1,
            'extra' => array(
                'shipping' => $shipping_details,
                'maritime_details' => $maritime_details,
                'client' => array(
                    'name' => $row->client_name,
                    'email' => $row->client_email,
                    'phone' => $row->client_phone,
                    'captured_at' => $row->client_info_captured_at
                ),
                'transport_type' => intval($row->maritime_involved) === 1 ? 'maritime' : 'terrestrial',
                'documentation_files' => $documentation_files,
                'session_id' => $row->session_id
            )
        );
    }
    
    /**
     * Statistics page
     */
    public function statistics_page() {
        global $wpdb;
        
        // Get statistics
        $total_quotes = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $maritime_quotes = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE maritime_involved = 1");
        $terrestrial_quotes = $total_quotes - $maritime_quotes;

        $registered_clients = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE client_name IS NOT NULL AND client_name != ''");
        $unregistered_clients = $total_quotes - $registered_clients;

        $total_revenue = $wpdb->get_var("SELECT SUM(final_price) FROM {$this->table_name} WHERE final_price > 0");
        $avg_price = $wpdb->get_var("SELECT AVG(final_price) FROM {$this->table_name} WHERE final_price > 0");
        
        $top_origins = $wpdb->get_results("
            SELECT pickup_city, pickup_zip, COUNT(*) as count 
            FROM {$this->table_name} 
            WHERE pickup_city IS NOT NULL 
            GROUP BY pickup_city, pickup_zip 
            ORDER BY count DESC 
            LIMIT 10
        ");
        
        $top_destinations = $wpdb->get_results("
            SELECT delivery_city, delivery_zip, COUNT(*) as count 
            FROM {$this->table_name} 
            WHERE delivery_city IS NOT NULL 
            GROUP BY delivery_city, delivery_zip 
            ORDER BY count DESC 
            LIMIT 10
        ");
        
        $recent_quotes = $wpdb->get_results("
            SELECT * FROM {$this->table_name} 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        ?>
        <div class="wrap">
            <h1>SDPI - Estadísticas</h1>
            
            <div class="sdpi-stats-grid">
                <div class="sdpi-stat-card">
                    <h3>Total de Cotizaciones</h3>
                    <div class="sdpi-stat-number"><?php echo number_format($total_quotes); ?></div>
                </div>
                
                <div class="sdpi-stat-card">
                    <h3>Transporte Marítimo</h3>
                    <div class="sdpi-stat-number"><?php echo number_format($maritime_quotes); ?></div>
                    <div class="sdpi-stat-percentage"><?php echo $total_quotes > 0 ? round(($maritime_quotes / $total_quotes) * 100, 1) : 0; ?>%</div>
                </div>
                
                <div class="sdpi-stat-card">
                    <h3>Transporte Terrestre</h3>
                    <div class="sdpi-stat-number"><?php echo number_format($terrestrial_quotes); ?></div>
                    <div class="sdpi-stat-percentage"><?php echo $total_quotes > 0 ? round(($terrestrial_quotes / $total_quotes) * 100, 1) : 0; ?>%</div>
                </div>
                
                <div class="sdpi-stat-card">
                    <h3>Ingresos Totales</h3>
                    <div class="sdpi-stat-number">$<?php echo number_format($total_revenue, 2); ?></div>
                </div>
                
                <div class="sdpi-stat-card">
                    <h3>Precio Promedio</h3>
                    <div class="sdpi-stat-number">$<?php echo number_format($avg_price, 2); ?></div>
                </div>

                <div class="sdpi-stat-card">
                    <h3>Clientes Registrados</h3>
                    <div class="sdpi-stat-number"><?php echo number_format($registered_clients); ?></div>
                    <div class="sdpi-stat-percentage"><?php echo $total_quotes > 0 ? round(($registered_clients / $total_quotes) * 100, 1) : 0; ?>%</div>
                </div>

                <div class="sdpi-stat-card">
                    <h3>Clientes No Registrados</h3>
                    <div class="sdpi-stat-number"><?php echo number_format($unregistered_clients); ?></div>
                    <div class="sdpi-stat-percentage"><?php echo $total_quotes > 0 ? round(($unregistered_clients / $total_quotes) * 100, 1) : 0; ?>%</div>
                </div>
            </div>
            
            <div class="sdpi-stats-sections">
                <div class="sdpi-stats-section">
                    <h2>Orígenes Más Populares</h2>
                    <table class="sdpi-stats-table">
                        <thead>
                            <tr>
                                <th>Ciudad</th>
                                <th>ZIP</th>
                                <th>Cotizaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_origins as $origin): ?>
                            <tr>
                                <td><?php echo esc_html($origin->pickup_city); ?></td>
                                <td><?php echo esc_html($origin->pickup_zip); ?></td>
                                <td><?php echo $origin->count; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="sdpi-stats-section">
                    <h2>Destinos Más Populares</h2>
                    <table class="sdpi-stats-table">
                        <thead>
                            <tr>
                                <th>Ciudad</th>
                                <th>ZIP</th>
                                <th>Cotizaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_destinations as $destination): ?>
                            <tr>
                                <td><?php echo esc_html($destination->delivery_city); ?></td>
                                <td><?php echo esc_html($destination->delivery_zip); ?></td>
                                <td><?php echo $destination->count; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="sdpi-stats-section">
                <h2>Cotizaciones Recientes</h2>
                <table class="sdpi-stats-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Origen</th>
                            <th>Destino</th>
                            <th>Tipo</th>
                            <th>Precio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_quotes as $quote): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($quote->created_at)); ?></td>
                            <td><?php echo esc_html($quote->pickup_city . ' ' . $quote->pickup_zip); ?></td>
                            <td><?php echo esc_html($quote->delivery_city . ' ' . $quote->delivery_zip); ?></td>
                            <td>
                                <?php if ($quote->maritime_involved): ?>
                                    <span class="sdpi-maritime-badge">Marítimo</span>
                                <?php else: ?>
                                    <span class="sdpi-terrestrial-badge">Terrestre</span>
                                <?php endif; ?>
                            </td>
                            <td class="sdpi-price">$<?php echo number_format($quote->final_price, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .sdpi-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .sdpi-stat-card {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            text-align: center;
        }
        
        .sdpi-stat-card h3 {
            margin: 0 0 10px 0;
            color: #23282d;
            font-size: 14px;
        }
        
        .sdpi-stat-number {
            font-size: 32px;
            font-weight: 600;
            color: #0073aa;
            margin-bottom: 5px;
        }
        
        .sdpi-stat-percentage {
            font-size: 14px;
            color: #666;
        }
        
        .sdpi-stats-sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .sdpi-stats-section {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .sdpi-stats-section h2 {
            margin-top: 0;
            color: #23282d;
        }
        
        .sdpi-stats-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .sdpi-stats-table th,
        .sdpi-stats-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .sdpi-stats-table th {
            background: #f1f1f1;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .sdpi-stats-sections {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Render history table
     */
    private function render_history_table() {
        global $wpdb;
        
        // Get filters
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');
        $flow_status = sanitize_text_field($_GET['flow_status'] ?? '');
        $maritime = sanitize_text_field($_GET['maritime'] ?? '');
        $search = sanitize_text_field($_GET['search'] ?? '');
        
        // Build WHERE clause
        $where_conditions = array();
        $where_values = array();
        
        if ($date_from) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = $date_from . ' 00:00:00';
        }
        
        if ($date_to) {
            $where_conditions[] = "created_at <= %s";
            $where_values[] = $date_to . ' 23:59:59';
        }
        
        if ($flow_status) {
            $where_conditions[] = "flow_status = %s";
            $where_values[] = $flow_status;
        }
        
        if ($maritime !== '') {
            $where_conditions[] = "maritime_involved = %d";
            $where_values[] = intval($maritime);
        }
        
        if ($search) {
            $where_conditions[] = "(pickup_city LIKE %s OR delivery_city LIKE %s OR pickup_zip LIKE %s OR delivery_zip LIKE %s OR vehicle_make LIKE %s OR vehicle_model LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values = array_merge($where_values, array_fill(0, 6, $search_term));
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} $where_clause";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_items = $wpdb->get_var($count_query);
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get items
        $query = "SELECT * FROM {$this->table_name} $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $query = $wpdb->prepare($query, $query_values);
        
        $items = $wpdb->get_results($query);
        
        $server_now = current_time('timestamp', true);
        $wp_timezone = wp_timezone();
        
        // Pagination
        $total_pages = ceil($total_items / $per_page);
        
        ?>
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> elementos</span>
                <?php if ($total_pages > 1): ?>
                <span class="pagination-links">
                    <?php
                    $pagination_args = array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;'
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        
        <form id="sdpi-bulk-send-form">
        <?php wp_nonce_field('sdpi_bulk_send', 'sdpi_bulk_send_nonce'); ?>
        <table class="sdpi-history-table">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="sdpi-select-all"></th>
                    <th>ID</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Cliente</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Vehículo</th>
                    <th>Tipo</th>
                    <th>Precio API</th>
                    <th>Precio Final</th>
                    <th>Confianza</th>
                    <th>Estado Zapier</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="14" style="text-align: center; padding: 40px;">
                        No se encontraron cotizaciones con los filtros aplicados.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><input type="checkbox" class="sdpi-select" name="ids[]" value="<?php echo esc_attr($item->id); ?>"></td>
                    <td><?php echo $item->id; ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($item->created_at)); ?></td>
                    <td>
                        <?php
                        $status_colors = array(
                            'inicial' => '#0073aa',
                            'cotizador' => '#00a32a',
                            'checkout' => '#dba617',
                            'completado' => '#00a32a'
                        );
                        $status_labels = array(
                            'inicial' => 'Inicial',
                            'cotizador' => 'Cotizador',
                            'checkout' => 'Checkout',
                            'completado' => 'Completado'
                        );
                        $status = $item->flow_status ?? 'inicial';
                        $color = $status_colors[$status] ?? '#666';
                        $label = $status_labels[$status] ?? ucfirst($status);
                        ?>
                        <span class="sdpi-status-badge" style="background-color: <?php echo $color; ?>; color: white; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                            <?php echo $label; ?>
                        </span>
                        <?php if (isset($item->status_updated_at) && $item->status_updated_at): ?>
                        <br><small style="color: #666;"><?php echo date('m/d H:i', strtotime($item->status_updated_at)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item->client_name): ?>
                            <strong><?php echo esc_html($item->client_name); ?></strong><br>
                            <small><?php echo esc_html($item->client_email); ?><br><?php echo esc_html($item->client_phone); ?></small>
                        <?php else: ?>
                            <em style="color: #999;">No registrado</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($item->pickup_city); ?><br>
                        <small><?php echo esc_html($item->pickup_zip); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html($item->delivery_city); ?><br>
                        <small><?php echo esc_html($item->delivery_zip); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html($item->vehicle_year . ' ' . $item->vehicle_make . ' ' . $item->vehicle_model); ?><br>
                        <small><?php echo esc_html($item->vehicle_type); ?></small>
                        <?php if ($item->vehicle_inoperable): ?>
                        <br><small style="color: #d63638;">No operativo</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item->maritime_involved): ?>
                            <span class="sdpi-maritime-badge">Marítimo</span>
                            <?php if ($item->us_port_name): ?>
                            <br><small>Puerto: <?php echo esc_html($item->us_port_name); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sdpi-terrestrial-badge">Terrestre</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item->api_price > 0): ?>
                            <span class="sdpi-price">$<?php echo number_format($item->api_price, 2); ?></span>
                        <?php else: ?>
                            <span class="sdpi-error">Error</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item->final_price > 0): ?>
                            <span class="sdpi-price">$<?php echo number_format($item->final_price, 2); ?></span>
                        <?php else: ?>
                            <span class="sdpi-error">Error</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item->api_confidence > 0): ?>
                            <?php echo number_format($item->api_confidence, 1); ?>%
                        <?php else: ?>
                            <span class="sdpi-error">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $scheduled_timestamp = null;
                        $countdown_label = '';
                        $countdown_diff = null;

                        if (!empty($item->zapier_scheduled_at)) {
                            $date_obj = date_create_from_format('Y-m-d H:i:s', $item->zapier_scheduled_at, $wp_timezone);
                            if ($date_obj instanceof DateTimeInterface) {
                                $scheduled_timestamp = $date_obj->getTimestamp();
                            } else {
                                $fallback_timestamp = strtotime($item->zapier_scheduled_at);
                                if ($fallback_timestamp) {
                                    $scheduled_timestamp = $fallback_timestamp;
                                }
                            }
                        }

                        if ($scheduled_timestamp) {
                            $countdown_diff = $scheduled_timestamp - $server_now;
                            $display_seconds = $countdown_diff > 0 ? $countdown_diff : 0;
                            if ($display_seconds >= 3600) {
                                $hours = floor($display_seconds / 3600);
                                $minutes = floor(($display_seconds % 3600) / 60);
                                $countdown_label = sprintf('En %dh %02dm', $hours, $minutes);
                            } elseif ($display_seconds > 0) {
                                $minutes = floor($display_seconds / 60);
                                $seconds = $display_seconds % 60;
                                $countdown_label = sprintf('En %02dm %02ds', $minutes, $seconds);
                            } else {
                                $countdown_label = 'En breve';
                            }
                        }
                        ?>
                        <?php if ($item->zapier_status === 'sent'): ?>
                            <span class="sdpi-terrestrial-badge">Enviado</span>
                            <?php if (!empty($item->zapier_last_sent_at)): ?>
                                <br><small><?php echo esc_html(date('Y-m-d H:i', strtotime($item->zapier_last_sent_at))); ?></small>
                            <?php endif; ?>
                        <?php elseif ($item->zapier_status === 'error'): ?>
                            <span class="sdpi-maritime-badge" style="background:#d63638;">Error</span>
                        <?php elseif ($item->zapier_status === 'scheduled' || ($item->zapier_status === 'pending' && $scheduled_timestamp)): ?>
                            <?php
                            $badge_label = $item->zapier_status === 'scheduled' ? 'Programado' : 'Pendiente';
                            $badge_color = $item->zapier_status === 'scheduled' ? '#2271b1' : '#777';
                            ?>
                            <span class="sdpi-maritime-badge" style="background:<?php echo esc_attr($badge_color); ?>;"><?php echo esc_html($badge_label); ?></span>
                            <?php if ($scheduled_timestamp): ?>
                                <br><small><?php echo esc_html(wp_date('Y-m-d H:i', $scheduled_timestamp)); ?></small>
                            <?php endif; ?>
                            <?php if ($countdown_diff !== null && $countdown_label !== ''): ?>
                                <br><small class="sdpi-zapier-countdown" data-scheduled="<?php echo esc_attr($scheduled_timestamp); ?>"><?php echo esc_html($countdown_label); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sdpi-maritime-badge" style="background:#777;">Pendiente</span>
                            <br><small>En espera de programación</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="sdpi-actions">
                            <button type="button" class="button button-small" onclick="sdpiViewDetails(<?php echo $item->id; ?>)">Ver</button>
                            <button type="button" class="button button-small button-link-delete" onclick="sdpiDeleteItem(<?php echo $item->id; ?>)">Eliminar</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
            <button type="button" id="sdpi-bulk-send-btn" class="button button-primary">Enviar seleccionados a Zapier</button>
            <button type="button" id="sdpi-bulk-delete-btn" class="button">Eliminar seleccionados</button>
        </div>
        </form>
        
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> elementos</span>
                <?php if ($total_pages > 1): ?>
                <span class="pagination-links">
                    <?php echo paginate_links($pagination_args); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        

        <script>
        function sdpiDeleteItem(id) {
            if (confirm('¿Estás seguro de que quieres eliminar este elemento?')) {
                jQuery.post(ajaxurl, {
                    action: 'sdpi_delete_history_item',
                    id: id,
                    nonce: '<?php echo wp_create_nonce('sdpi_delete_history'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error al eliminar: ' + response.data);
                    }
                });
            }
        }
        </script>
        <script>
        jQuery(document).ready(function($){
            $('#sdpi-select-all').on('change', function(){
                $('.sdpi-select').prop('checked', $(this).is(':checked'));
            });

            $('#sdpi-bulk-send-btn').on('click', function(){
                var ids = $('.sdpi-select:checked').map(function(){ return this.value; }).get();
                if (ids.length === 0) { alert('Seleccione al menos una cotización.'); return; }
                var nonce = $('#sdpi-bulk-send-form').find('input[name="sdpi_bulk_send_nonce"]').val();
                var btn = $(this);
                btn.prop('disabled', true).text('Enviando...');
                jQuery.post(ajaxurl, { action: 'sdpi_bulk_send_zapier', nonce: nonce, ids: ids }, function(resp){
                    btn.prop('disabled', false).text('Enviar seleccionados a Zapier');
                    if (resp && resp.success) {
                        alert('Envío completado. Éxito: ' + resp.data.sent + ', Fallas: ' + resp.data.failed);
                    } else {
                        alert('Error al enviar: ' + (resp && resp.data ? resp.data : 'Desconocido'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('Enviar seleccionados a Zapier');
                    alert('Error de red.');
                });
            });

            $('#sdpi-bulk-delete-btn').on('click', function(){
                var ids = $('.sdpi-select:checked').map(function(){ return this.value; }).get();
                if (ids.length === 0) { alert('Seleccione al menos una cotización.'); return; }
                if (!confirm('¿Está seguro de eliminar los elementos seleccionados?')) { return; }
                var btn = $(this);
                btn.prop('disabled', true).text('Eliminando...');
                jQuery.post(ajaxurl, { action: 'sdpi_bulk_delete_history', ids: ids, nonce: '<?php echo wp_create_nonce('sdpi_bulk_delete'); ?>' }, function(resp){
                    btn.prop('disabled', false).text('Eliminar seleccionados');
                    if (resp && resp.success) {
                        location.reload();
                    } else {
                        alert('Error al eliminar: ' + (resp && resp.data ? resp.data : 'Desconocido'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('Eliminar seleccionados');
                    alert('Error de red.');
                });
            });

            var $zapierCountdowns = $('.sdpi-zapier-countdown');
            if ($zapierCountdowns.length) {
                var serverNow = <?php echo (int) $server_now; ?>;
                var clientStart = Math.floor(Date.now() / 1000);

                function currentServerTime() {
                    var clientNow = Math.floor(Date.now() / 1000);
                    return serverNow + (clientNow - clientStart);
                }

                function formatZapierCountdown(seconds) {
                    if (seconds <= 0) {
                        return 'En breve';
                    }
                    var hours = Math.floor(seconds / 3600);
                    var minutes = Math.floor((seconds % 3600) / 60);
                    var secs = seconds % 60;
                    if (hours > 0) {
                        return 'En ' + hours + 'h ' + (minutes < 10 ? '0' : '') + minutes + 'm';
                    }
                    return 'En ' + (minutes < 10 ? '0' : '') + minutes + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
                }

                function refreshZapierCountdowns() {
                    var now = currentServerTime();
                    $zapierCountdowns.each(function(){
                        var $el = $(this);
                        var scheduled = parseInt($el.data('scheduled'), 10);
                        if (isNaN(scheduled)) {
                            return;
                        }
                        var remaining = scheduled - now;
                        $el.text(formatZapierCountdown(remaining));
                    });
                }

                refreshZapierCountdowns();
                setInterval(refreshZapierCountdowns, 1000);
            }
        });
        </script>
        <?php
    }
    
    /**
     * Create initial history record when client info is captured
     */
    public function create_initial_record($session_id, $client_name, $client_email, $client_phone) {
        global $wpdb;
        
        // Check if record already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE session_id = %s",
            $session_id
        ));
        
        if ($existing) {
            // Update existing record to 'inicial' status
            $wpdb->update(
                $this->table_name,
                array(
                    'flow_status' => 'inicial',
                    'client_name' => sanitize_text_field($client_name),
                    'client_email' => sanitize_email($client_email),
                    'client_phone' => sanitize_text_field($client_phone),
                    'client_info_captured_at' => current_time('mysql'),
                    'status_updated_at' => current_time('mysql')
                ),
                array('session_id' => $session_id),
                array('%s', '%s', '%s', '%s', '%s', '%s'),
                array('%s')
            );
            return $existing->id;
        }
        
        // Create new record
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'session_id' => sanitize_text_field($session_id),
                'flow_status' => 'inicial',
                'user_ip' => $this->get_user_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'client_name' => sanitize_text_field($client_name),
                'client_email' => sanitize_email($client_email),
                'client_phone' => sanitize_text_field($client_phone),
                'client_info_captured_at' => current_time('mysql'),
                'status_updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $result !== false ? $wpdb->insert_id : false;
    }
    
    /**
     * Update history record to 'cotizador' status with quote data
     */
    public function update_to_cotizador($session_id, $form_data, $api_response, $final_price, $price_breakdown = '') {
        global $wpdb;
        
        // Extract data
        $pickup_zip = sanitize_text_field($form_data['pickup_zip'] ?? '');
        $delivery_zip = sanitize_text_field($form_data['delivery_zip'] ?? '');
        $pickup_city = sanitize_text_field($form_data['pickup_city'] ?? '');
        $delivery_city = sanitize_text_field($form_data['delivery_city'] ?? '');
        $trailer_type = sanitize_text_field($form_data['trailer_type'] ?? '');
        $vehicle_type = sanitize_text_field($form_data['vehicle_type'] ?? '');
        $vehicle_inoperable = intval($form_data['vehicle_inoperable'] ?? 0);
        $vehicle_make = sanitize_text_field($form_data['vehicle_make'] ?? '');
        $vehicle_model = sanitize_text_field($form_data['vehicle_model'] ?? '');
        $vehicle_year = sanitize_text_field($form_data['vehicle_year'] ?? '');
        $vehicle_electric = intval(!empty($form_data['vehicle_electric']));

        $pickup_contact_name = sanitize_text_field($form_data['pickup_contact_name'] ?? '');
        $pickup_contact_street = sanitize_text_field($form_data['pickup_contact_street'] ?? '');
        $pickup_contact_type = sanitize_text_field($form_data['pickup_contact_type'] ?? '');
        $delivery_contact_name = sanitize_text_field($form_data['delivery_contact_name'] ?? '');
        $delivery_contact_street = sanitize_text_field($form_data['delivery_contact_street'] ?? '');

        $additional_shipping_json = '';
        if (!empty($form_data['additional_shipping'])) {
            if (is_array($form_data['additional_shipping'])) {
                $additional_shipping_json = wp_json_encode($form_data['additional_shipping']);
            } elseif (is_string($form_data['additional_shipping'])) {
                $additional_shipping_json = (string) $form_data['additional_shipping'];
            }
        }

        $documentation_entries = $this->sanitize_documentation_entries($form_data['documentation_files'] ?? array());
        if (empty($documentation_entries) && !empty($session_id)) {
            $documentation_entries = $this->get_documentation_files_from_session($session_id);
        }
        $documentation_files_json = !empty($documentation_entries) ? wp_json_encode($documentation_entries) : '';

        // Client information
        $client_name = sanitize_text_field($form_data['client_name'] ?? '');
        $client_phone = sanitize_text_field($form_data['client_phone'] ?? '');
        $client_email = sanitize_text_field($form_data['client_email'] ?? '');
        $client_info_captured_at = sanitize_text_field($form_data['client_info_captured_at'] ?? null);
        
        // API response data
        $api_price = 0;
        $api_confidence = 0;
        $api_price_per_mile = 0;
        $error_message = '';
        
        if (is_wp_error($api_response)) {
            $error_message = $api_response->get_error_message();
        } else {
            $api_price = floatval($api_response['recommended_price'] ?? 0);
            $api_confidence = floatval($api_response['confidence'] ?? 0);
            $api_price_per_mile = floatval($api_response['price_per_mile'] ?? 0);
        }
        
        // Maritime data
        $maritime_involved = 0;
        $maritime_cost = 0;
        $us_port_name = '';
        $us_port_zip = '';
        $total_terrestrial_cost = 0;
        $total_maritime_cost = 0;
        $maritime_direction = sanitize_text_field($form_data['maritime_direction'] ?? '');
        $maritime_details = '';
        
        if (isset($form_data['maritime_involved']) && $form_data['maritime_involved']) {
            $maritime_involved = 1;
            $maritime_cost = floatval($form_data['maritime_cost'] ?? 0);
            $us_port_name = sanitize_text_field($form_data['us_port_name'] ?? '');
            $us_port_zip = sanitize_text_field($form_data['us_port_zip'] ?? '');
            $total_terrestrial_cost = floatval($form_data['total_terrestrial_cost'] ?? 0);
            $total_maritime_cost = floatval($form_data['total_maritime_cost'] ?? 0);

            if (!empty($form_data['maritime_details'])) {
                $maritime_details = wp_json_encode($form_data['maritime_details']);
            }
        }

        $inoperable_fee = floatval($form_data['inoperable_fee'] ?? 0);
        
        // Calculate adjustments
        $company_profit = 200.00;
        $confidence_adjustment = 0;
        
        if ($api_confidence >= 60 && $api_confidence <= 100) {
            $remaining = 100 - $api_confidence;
            $confidence_adjustment = ($api_price * $remaining) / 100;
        } elseif ($api_confidence >= 30 && $api_confidence <= 59) {
            $confidence_adjustment = 150.00;
        } elseif ($api_confidence >= 0 && $api_confidence <= 29) {
            $confidence_adjustment = 200.00;
        }
        
        // Update record
        $data = array(
            'flow_status' => 'cotizador',
            'pickup_zip' => $pickup_zip,
            'delivery_zip' => $delivery_zip,
            'pickup_city' => $pickup_city,
            'delivery_city' => $delivery_city,
            'trailer_type' => $trailer_type,
            'vehicle_type' => $vehicle_type,
            'vehicle_inoperable' => $vehicle_inoperable,
            'vehicle_make' => $vehicle_make,
            'vehicle_model' => $vehicle_model,
            'vehicle_year' => $vehicle_year,
            'vehicle_electric' => $vehicle_electric,
            'client_name' => $client_name,
            'client_phone' => $client_phone,
            'client_email' => $client_email,
            'client_info_captured_at' => $client_info_captured_at,
            'api_response' => json_encode($api_response),
            'api_price' => $api_price,
            'api_confidence' => $api_confidence,
            'api_price_per_mile' => $api_price_per_mile,
            'final_price' => $final_price,
            'company_profit' => $company_profit,
            'confidence_adjustment' => $confidence_adjustment,
            'maritime_involved' => $maritime_involved,
            'maritime_direction' => $maritime_direction,
            'maritime_cost' => $maritime_cost,
            'us_port_name' => $us_port_name,
            'us_port_zip' => $us_port_zip,
            'total_terrestrial_cost' => $total_terrestrial_cost,
            'total_maritime_cost' => $total_maritime_cost,
            'inoperable_fee' => $inoperable_fee,
            'maritime_details' => $maritime_details,
            'documentation_files' => $documentation_files_json,
            'additional_shipping' => $additional_shipping_json,
            'pickup_contact_name' => $pickup_contact_name,
            'pickup_contact_street' => $pickup_contact_street,
            'pickup_contact_type' => $pickup_contact_type,
            'delivery_contact_name' => $delivery_contact_name,
            'delivery_contact_street' => $delivery_contact_street,
            'price_breakdown' => $price_breakdown,
            'error_message' => $error_message,
            'status_updated_at' => current_time('mysql')
        );

        $formats = array(
            '%s', // flow_status
            '%s', // pickup_zip
            '%s', // delivery_zip
            '%s', // pickup_city
            '%s', // delivery_city
            '%s', // trailer_type
            '%s', // vehicle_type
            '%d', // vehicle_inoperable
            '%s', // vehicle_make
            '%s', // vehicle_model
            '%s', // vehicle_year
            '%d', // vehicle_electric
            '%s', // client_name
            '%s', // client_phone
            '%s', // client_email
            '%s', // client_info_captured_at
            '%s', // api_response
            '%f', // api_price
            '%f', // api_confidence
            '%f', // api_price_per_mile
            '%f', // final_price
            '%f', // company_profit
            '%f', // confidence_adjustment
            '%d', // maritime_involved
            '%s', // maritime_direction
            '%f', // maritime_cost
            '%s', // us_port_name
            '%s', // us_port_zip
            '%f', // total_terrestrial_cost
            '%f', // total_maritime_cost
            '%f', // inoperable_fee
            '%s', // maritime_details
            '%s', // documentation_files
            '%s', // additional_shipping
            '%s', // pickup_contact_name
            '%s', // pickup_contact_street
            '%s', // pickup_contact_type
            '%s', // delivery_contact_name
            '%s', // delivery_contact_street
            '%s', // price_breakdown
            '%s', // error_message
            '%s'  // status_updated_at
        );

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('session_id' => $session_id),
            $formats,
            array('%s')
        );

        return $result !== false;
    }
    
    /**
     * Update history record to 'checkout' status
     */
    public function update_to_checkout($session_id) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'flow_status' => 'checkout',
                'status_updated_at' => current_time('mysql')
            ),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );
        
        return $result !== false;
    }
    
    /**
     * Store maritime details captured after quote confirmation
     */
    public function update_maritime_details($session_id, $maritime_details, $direction = '') {
        global $wpdb;

        if (empty($session_id)) {
            return false;
        }

        $data = array(
            'maritime_details' => wp_json_encode($maritime_details),
            'status_updated_at' => current_time('mysql')
        );
        $formats = array('%s', '%s');

        if (!empty($direction)) {
            $data['maritime_direction'] = sanitize_text_field($direction);
            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('session_id' => sanitize_text_field($session_id)),
            $formats,
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Store additional terrestrial shipping details captured after quote confirmation
     */
    public function update_additional_shipping($session_id, $shipping_details = array()) {
        global $wpdb;

        if (empty($session_id)) {
            return false;
        }

        $pickup = is_array($shipping_details) && isset($shipping_details['pickup']) ? $shipping_details['pickup'] : array();
        $delivery = is_array($shipping_details) && isset($shipping_details['delivery']) ? $shipping_details['delivery'] : array();

        $pickup_name = sanitize_text_field($pickup['name'] ?? '');
        $pickup_street = sanitize_text_field($pickup['street'] ?? '');
        $pickup_type = sanitize_text_field($pickup['type'] ?? '');
        $delivery_name = sanitize_text_field($delivery['name'] ?? '');
        $delivery_street = sanitize_text_field($delivery['street'] ?? '');

        $shipping_json = !empty($shipping_details) ? wp_json_encode($shipping_details) : null;

        $data = array(
            'pickup_contact_name' => $pickup_name,
            'pickup_contact_street' => $pickup_street,
            'pickup_contact_type' => $pickup_type,
            'delivery_contact_name' => $delivery_name,
            'delivery_contact_street' => $delivery_street,
            'status_updated_at' => current_time('mysql')
        );
        $formats = array('%s', '%s', '%s', '%s', '%s', '%s');

        if (!is_null($shipping_json)) {
            $data['additional_shipping'] = $shipping_json;
            $formats[] = '%s';
        }

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('session_id' => sanitize_text_field($session_id)),
            $formats,
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Persist the documentation list associated to a session
     */
    public function update_documentation_files($session_id, $documents = array()) {
        global $wpdb;

        if (empty($session_id)) {
            return false;
        }

        $entries = $this->sanitize_documentation_entries($documents);
        $json = wp_json_encode($entries);

        $data = array(
            'documentation_files' => $json,
            'status_updated_at' => current_time('mysql')
        );

        $formats = array_fill(0, count($data), '%s');

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('session_id' => sanitize_text_field($session_id)),
            $formats,
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Update history record to 'completado' status
     */
    public function update_to_completado($session_id) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'flow_status' => 'completado',
                'status_updated_at' => current_time('mysql')
            ),
            array('session_id' => $session_id),
            array('%s', '%s'),
            array('%s')
        );
        
        return $result !== false;
    }

    /**
     * Mark Zapier status for a given session
     */
    public function mark_zapier_status($session_id, $status, $args = array()) {
        global $wpdb;

        if (empty($session_id) || empty($status)) {
            return false;
        }

        $status = sanitize_text_field($status);
        $now_mysql = current_time('mysql');

        $data = array(
            'zapier_status' => $status,
            'status_updated_at' => $now_mysql
        );

        if ($status === 'sent') {
            $data['zapier_last_sent_at'] = $now_mysql;
        }

        if (!empty($args['trigger_reason'])) {
            $data['zapier_trigger_reason'] = sanitize_text_field($args['trigger_reason']);
        }

        if (isset($args['scheduled_at'])) {
            $timestamp = is_numeric($args['scheduled_at']) ? intval($args['scheduled_at']) : strtotime((string) $args['scheduled_at']);
            if ($timestamp) {
                $data['zapier_scheduled_at'] = wp_date('Y-m-d H:i:s', $timestamp);
            }
        }

        if (array_key_exists('last_error', $args)) {
            $data['zapier_last_error'] = sanitize_textarea_field((string) $args['last_error']);
        }

        $formats = array_fill(0, count($data), '%s');

        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('session_id' => sanitize_text_field($session_id)),
            $formats,
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Log when a Zapier send has been scheduled for later execution
     */
    public function log_zapier_schedule($session_id, $timestamp) {
        if (empty($session_id) || empty($timestamp)) {
            return false;
        }

        $scheduled_at = is_numeric($timestamp) ? intval($timestamp) : strtotime((string) $timestamp);
        if (!$scheduled_at) {
            return false;
        }

        return $this->mark_zapier_status($session_id, 'scheduled', array(
            'scheduled_at' => $scheduled_at,
            'trigger_reason' => 'delay',
            'last_error' => ''
        ));
    }

    /**
     * Get history record by session ID
     */
    public function get_by_session_id($session_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE session_id = %s",
            $session_id
        ), ARRAY_A);
    }

    /**
     * Log a quote to history (legacy method - now redirects to update_to_cotizador)
     */
    public function log_quote($form_data, $api_response, $final_price, $price_breakdown = '') {
        global $wpdb;
        
        // Extract data
        $pickup_zip = sanitize_text_field($form_data['pickup_zip'] ?? '');
        $delivery_zip = sanitize_text_field($form_data['delivery_zip'] ?? '');
        $pickup_city = sanitize_text_field($form_data['pickup_city'] ?? '');
        $delivery_city = sanitize_text_field($form_data['delivery_city'] ?? '');
        $trailer_type = sanitize_text_field($form_data['trailer_type'] ?? '');
        $vehicle_type = sanitize_text_field($form_data['vehicle_type'] ?? '');
        $vehicle_inoperable = intval($form_data['vehicle_inoperable'] ?? 0);
        $vehicle_make = sanitize_text_field($form_data['vehicle_make'] ?? '');
        $vehicle_model = sanitize_text_field($form_data['vehicle_model'] ?? '');
        $vehicle_year = sanitize_text_field($form_data['vehicle_year'] ?? '');

        // Client information
        $client_name = sanitize_text_field($form_data['client_name'] ?? '');
        $client_phone = sanitize_text_field($form_data['client_phone'] ?? '');
        $client_email = sanitize_text_field($form_data['client_email'] ?? '');
        $client_info_captured_at = sanitize_text_field($form_data['client_info_captured_at'] ?? null);
        
        // API response data
        $api_price = 0;
        $api_confidence = 0;
        $api_price_per_mile = 0;
        $error_message = '';
        
        if (is_wp_error($api_response)) {
            $error_message = $api_response->get_error_message();
        } else {
            $api_price = floatval($api_response['recommended_price'] ?? 0);
            $api_confidence = floatval($api_response['confidence'] ?? 0);
            $api_price_per_mile = floatval($api_response['price_per_mile'] ?? 0);
        }
        
        // Maritime data
        $maritime_involved = 0;
        $maritime_cost = 0;
        $us_port_name = '';
        $us_port_zip = '';
        $total_terrestrial_cost = 0;
        $total_maritime_cost = 0;
        $maritime_direction = sanitize_text_field($form_data['maritime_direction'] ?? '');
        $maritime_details = '';
        
        if (isset($form_data['maritime_involved']) && $form_data['maritime_involved']) {
            $maritime_involved = 1;
            $maritime_cost = floatval($form_data['maritime_cost'] ?? 0);
            $us_port_name = sanitize_text_field($form_data['us_port_name'] ?? '');
            $us_port_zip = sanitize_text_field($form_data['us_port_zip'] ?? '');
            $total_terrestrial_cost = floatval($form_data['total_terrestrial_cost'] ?? 0);
            $total_maritime_cost = floatval($form_data['total_maritime_cost'] ?? 0);

            if (!empty($form_data['maritime_details'])) {
                $maritime_details = wp_json_encode($form_data['maritime_details']);
            }
        }

        $documentation_entries = $this->sanitize_documentation_entries($form_data['documentation_files'] ?? array());
        if (empty($documentation_entries) && !empty($form_data['session_id'] ?? '')) {
            $documentation_entries = $this->get_documentation_files_from_session($form_data['session_id']);
        }
        $documentation_files_json = !empty($documentation_entries) ? wp_json_encode($documentation_entries) : '';
        
        // Calculate adjustments
        $company_profit = 200.00;
        $confidence_adjustment = 0;
        
        if ($api_confidence >= 60 && $api_confidence <= 100) {
            $remaining = 100 - $api_confidence;
            $confidence_adjustment = ($api_price * $remaining) / 100;
        } elseif ($api_confidence >= 30 && $api_confidence <= 59) {
            $confidence_adjustment = 150.00;
        } elseif ($api_confidence >= 0 && $api_confidence <= 29) {
            $confidence_adjustment = 200.00;
        }
        
        // Insert into database
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'user_ip' => $this->get_user_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'pickup_zip' => $pickup_zip,
                'delivery_zip' => $delivery_zip,
                'pickup_city' => $pickup_city,
                'delivery_city' => $delivery_city,
                'trailer_type' => $trailer_type,
                'vehicle_type' => $vehicle_type,
                'vehicle_inoperable' => $vehicle_inoperable,
                'vehicle_make' => $vehicle_make,
                'vehicle_model' => $vehicle_model,
                'vehicle_year' => $vehicle_year,
                'vehicle_electric' => $vehicle_electric,
                'client_name' => $client_name,
                'client_phone' => $client_phone,
                'client_email' => $client_email,
                'client_info_captured_at' => $client_info_captured_at,
                'api_response' => json_encode($api_response),
                'api_price' => $api_price,
                'api_confidence' => $api_confidence,
                'api_price_per_mile' => $api_price_per_mile,
                'final_price' => $final_price,
                'company_profit' => $company_profit,
                'confidence_adjustment' => $confidence_adjustment,
                'maritime_involved' => $maritime_involved,
                'maritime_direction' => $maritime_direction,
                'maritime_cost' => $maritime_cost,
                'us_port_name' => $us_port_name,
                'us_port_zip' => $us_port_zip,
                'total_terrestrial_cost' => $total_terrestrial_cost,
                'total_maritime_cost' => $total_maritime_cost,
                'maritime_details' => $maritime_details,
                'documentation_files' => $documentation_files_json,
                'additional_shipping' => $additional_shipping_json,
                'pickup_contact_name' => $pickup_contact_name,
                'pickup_contact_street' => $pickup_contact_street,
                'pickup_contact_type' => $pickup_contact_type,
                'delivery_contact_name' => $delivery_contact_name,
                'delivery_contact_street' => $delivery_contact_street,
                'price_breakdown' => $price_breakdown,
                'error_message' => $error_message
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d',
                '%s', '%s', '%s', '%s',
                '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%d', '%s', '%f', '%s', '%s', '%f', '%f',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );
        
        return $result !== false;
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * AJAX: Get history
     */
    public function ajax_get_history() {
        check_ajax_referer('sdpi_history_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // This would be used for AJAX pagination if needed
        wp_send_json_success(array('message' => 'History loaded'));
    }
    
    /**
     * AJAX: Delete history item
     */
    public function ajax_delete_history_item() {
        check_ajax_referer('sdpi_delete_history', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $id = intval($_POST['id']);
        
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Item deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete item'));
        }
    }

    /**
     * AJAX: Bulk delete selected history items
     */
    public function ajax_bulk_delete_history() {
        check_ajax_referer('sdpi_bulk_delete', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : array();
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_error('No se recibieron IDs.');
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = "DELETE FROM {$this->table_name} WHERE id IN ($placeholders)";
        $prepared = $wpdb->prepare($query, $ids);
        $result = $wpdb->query($prepared);
        if ($result === false) {
            wp_send_json_error('Fallo al eliminar.');
        }
        wp_send_json_success(array('deleted' => $result));
    }
    
    /**
     * AJAX: Get quote details
     */
    public function ajax_get_quote_details() {
        check_ajax_referer('sdpi_get_details', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $id = intval($_POST['id']);
        
        global $wpdb;
        $quote = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
        
        if (!$quote) {
            wp_send_json_error('Cotización no encontrada');
        }
        
        // Parse API response
        $api_response = json_decode($quote->api_response, true);
        
        // Generate detailed HTML
        $html = $this->generate_quote_details_html($quote, $api_response);
        
        wp_send_json_success($html);
    }
    
    /**
     * Generate detailed HTML for quote
     */
    private function generate_quote_details_html($quote, $api_response) {
        $additional_shipping = array();
        if (!empty($quote->additional_shipping)) {
            $decoded_shipping = json_decode($quote->additional_shipping, true);
            if (is_array($decoded_shipping)) {
                $additional_shipping = $decoded_shipping;
            }
        }

        $maritime_details = array();
        if (!empty($quote->maritime_details)) {
            $decoded_maritime_details = json_decode($quote->maritime_details, true);
            if (is_array($decoded_maritime_details)) {
                $maritime_details = $decoded_maritime_details;
            }
        }

        $documentation_files = array();
        if (!empty($quote->documentation_files)) {
            $decoded_docs = json_decode($quote->documentation_files, true);
            if (is_array($decoded_docs)) {
                $documentation_files = array_values(array_filter($decoded_docs, function($item) {
                    return is_array($item);
                }));
            }
        }

        $created_at = $quote->created_at ? date('d/m/Y H:i:s', strtotime($quote->created_at)) : '';
        $client_captured = $quote->client_info_captured_at ? date('d/m/Y H:i', strtotime($quote->client_info_captured_at)) : '';
        $shipping_updated = !empty($additional_shipping['saved_at']) ? date('d/m/Y H:i', strtotime($additional_shipping['saved_at'])) : '';

        ob_start();
        ?>
        <div class="sdpi-modal-content">
            <header class="sdpi-modal-header">
                <div class="sdpi-modal-heading">
                    <h2 class="sdpi-modal-title">Detalles de Cotizaci&oacute;n #<?php echo esc_html($quote->id); ?></h2>
                    <?php if ($created_at) : ?>
                        <p class="sdpi-modal-subtitle">Creada el <?php echo esc_html($created_at); ?></p>
                    <?php endif; ?>
                </div>
                <button type="button" class="sdpi-modal-close" onclick="sdpiCloseModal()">Cerrar</button>
            </header>

            <section class="sdpi-modal-section">
                <div class="sdpi-modal-grid sdpi-modal-grid--two">
                    <article class="sdpi-modal-card">
                        <h3 class="sdpi-modal-card-title">Informaci&oacute;n del Cliente</h3>
                        <?php if ($quote->client_name) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Nombre</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->client_name); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Correo</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->client_email); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Tel&eacute;fono</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->client_phone); ?></span>
                            </div>
                            <?php if ($client_captured) : ?>
                                <div class="sdpi-modal-note">Datos capturados el <?php echo esc_html($client_captured); ?></div>
                            <?php endif; ?>
                        <?php else : ?>
                            <p class="sdpi-modal-empty">Cliente no registrado.</p>
                        <?php endif; ?>
                    </article>

                    <article class="sdpi-modal-card">
                        <h3 class="sdpi-modal-card-title">Informaci&oacute;n General</h3>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">ID</span>
                            <span class="sdpi-modal-value">#<?php echo esc_html($quote->id); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">IP Usuario</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->user_ip); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Navegador</span>
                            <span class="sdpi-modal-value"><?php echo esc_html(substr($quote->user_agent, 0, 80)); ?><?php echo strlen($quote->user_agent) > 80 ? '&hellip;' : ''; ?></span>
                        </div>
                        <?php if (!empty($quote->flow_status)) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Estado del Flujo</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->flow_status); ?></span>
                            </div>
                        <?php endif; ?>
                    </article>
                </div>
            </section>

            <section class="sdpi-modal-section">
                <h3 class="sdpi-modal-section-title">Informaci&oacute;n del Env&iacute;o</h3>
                <article class="sdpi-modal-card">
                    <div class="sdpi-modal-grid sdpi-modal-grid--two">
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Origen</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->pickup_city); ?> (<?php echo esc_html($quote->pickup_zip); ?>)</span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Destino</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->delivery_city); ?> (<?php echo esc_html($quote->delivery_zip); ?>)</span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Trailer</span>
                            <span class="sdpi-modal-value"><?php echo esc_html(ucfirst($quote->trailer_type)); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Transporte</span>
                            <span class="sdpi-modal-value">
                                <span class="sdpi-modal-tag <?php echo $quote->maritime_involved ? 'is-maritime' : 'is-ground'; ?>"><?php echo $quote->maritime_involved ? 'Mar&iacute;timo' : 'Terrestre'; ?></span>
                            </span>
                        </div>
                        <?php if (!empty($additional_shipping['distance'])) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Distancia</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($additional_shipping['distance']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($additional_shipping['duration'])) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Duraci&oacute;n</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($additional_shipping['duration']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($additional_shipping['avg_miles_per_day'])) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Millas por d&iacute;a</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($additional_shipping['avg_miles_per_day']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($additional_shipping['estimated_days'])) : ?>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">D&iacute;as estimados</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($additional_shipping['estimated_days']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($additional_shipping['route_summary'])) : ?>
                        <div class="sdpi-modal-note">Ruta: <?php echo esc_html($additional_shipping['route_summary']); ?></div>
                    <?php endif; ?>

                    <?php if ($quote->pickup_contact_name || $quote->pickup_contact_street || $quote->pickup_contact_type || $quote->delivery_contact_name || $quote->delivery_contact_street) : ?>
                        <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--compact">
                            <?php if ($quote->pickup_contact_name) : ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Contacto origen</span>
                                    <span class="sdpi-modal-value"><?php echo esc_html($quote->pickup_contact_name); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($quote->pickup_contact_type) : ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Tipo de recogida</span>
                                    <span class="sdpi-modal-value"><?php echo esc_html($quote->pickup_contact_type); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($quote->pickup_contact_street) : ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Direcci&oacute;n origen</span>
                                    <span class="sdpi-modal-value"><?php echo esc_html($quote->pickup_contact_street); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($quote->delivery_contact_name) : ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Contacto destino</span>
                                    <span class="sdpi-modal-value"><?php echo esc_html($quote->delivery_contact_name); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($quote->delivery_contact_street) : ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Direcci&oacute;n destino</span>
                                    <span class="sdpi-modal-value"><?php echo esc_html($quote->delivery_contact_street); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($shipping_updated) : ?>
                        <div class="sdpi-modal-note">Datos de env&iacute;o actualizados el <?php echo esc_html($shipping_updated); ?></div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="sdpi-modal-section">
                <h3 class="sdpi-modal-section-title">Informaci&oacute;n del Veh&iacute;culo</h3>
                <article class="sdpi-modal-card">
                    <div class="sdpi-modal-grid sdpi-modal-grid--two">
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Tipo</span>
                            <span class="sdpi-modal-value"><?php echo esc_html(ucfirst($quote->vehicle_type)); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Marca</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->vehicle_make); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Modelo</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->vehicle_model); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">A&ntilde;o</span>
                            <span class="sdpi-modal-value"><?php echo esc_html($quote->vehicle_year); ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">Estado</span>
                            <span class="sdpi-modal-value"><?php echo $quote->vehicle_inoperable ? 'No operativo' : 'Operativo'; ?></span>
                        </div>
                        <div class="sdpi-modal-field">
                            <span class="sdpi-modal-label">El&eacute;ctrico</span>
                            <span class="sdpi-modal-value"><?php echo $quote->vehicle_electric ? 'S&iacute;' : 'No'; ?></span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="sdpi-modal-section">
                <h3 class="sdpi-modal-section-title">Documentaci&oacute;n Adjunta</h3>
                <article class="sdpi-modal-card">
                    <?php if (!empty($documentation_files)) : ?>
                        <ul class="sdpi-modal-documents">
                            <?php foreach ($documentation_files as $file_entry) :
                                $doc_name = isset($file_entry['name']) ? (string) $file_entry['name'] : '';
                                $doc_url = isset($file_entry['url']) ? (string) $file_entry['url'] : '';
                                $doc_size = '';
                                if (!empty($file_entry['size'])) {
                                    $doc_size = size_format((int) $file_entry['size']);
                                }
                                $doc_extension = '';
                                if ($doc_name && strpos($doc_name, '.') !== false) {
                                    $doc_extension = strtoupper(pathinfo($doc_name, PATHINFO_EXTENSION));
                                } elseif (!empty($file_entry['type'])) {
                                    $doc_extension = strtoupper((string) $file_entry['type']);
                                }
                                $doc_uploaded = '';
                                if (!empty($file_entry['uploaded_at'])) {
                                    $timestamp = strtotime($file_entry['uploaded_at']);
                                    if ($timestamp) {
                                        $doc_uploaded = 'Subido el ' . date('d/m/Y H:i', $timestamp);
                                    }
                                }
                                $meta_parts = array_filter(array($doc_size, $doc_extension, $doc_uploaded));
                            ?>
                                <li class="sdpi-modal-documents-item">
                                    <div class="sdpi-modal-documents-main">
                                        <span class="sdpi-modal-documents-name"><?php echo esc_html($doc_name); ?></span>
                                        <?php if (!empty($meta_parts)) : ?>
                                            <span class="sdpi-modal-documents-meta"><?php echo esc_html(implode(' · ', $meta_parts)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sdpi-modal-documents-actions">
                                        <?php if (!empty($doc_url)) : ?>
                                            <a href="<?php echo esc_url($doc_url); ?>" target="_blank" rel="noopener">Ver</a>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="sdpi-modal-empty">Sin documentos adjuntos.</p>
                    <?php endif; ?>
                </article>
            </section>

            <section class="sdpi-modal-section">
                <h3 class="sdpi-modal-section-title">Informaci&oacute;n de Precios</h3>
                <article class="sdpi-modal-card">
                    <?php if ($quote->api_price > 0) : ?>
                        <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--balanced">
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Precio base API</span>
                                <span class="sdpi-modal-value">$<?php echo number_format((float) $quote->api_price, 2); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Confianza API</span>
                                <span class="sdpi-modal-value"><?php echo number_format((float) $quote->api_confidence, 1); ?>%</span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Precio por milla</span>
                                <span class="sdpi-modal-value">$<?php echo number_format((float) $quote->api_price_per_mile, 4); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Precio final</span>
                                <span class="sdpi-modal-value sdpi-modal-highlight">$<?php echo number_format((float) $quote->final_price, 2); ?></span>
                            </div>
                        </div>

                        <div class="sdpi-modal-subcard">
                            <h4 class="sdpi-modal-subcard-title">Desglose de costos</h4>
                            <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--compact">
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Precio base API</span>
                                    <span class="sdpi-modal-value">$<?php echo number_format((float) $quote->api_price, 2); ?></span>
                                </div>
                                <?php if ($quote->confidence_adjustment > 0) : ?>
                                    <div class="sdpi-modal-field">
                                        <span class="sdpi-modal-label">Ajuste por confianza</span>
                                        <span class="sdpi-modal-value">+$<?php echo number_format((float) $quote->confidence_adjustment, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="sdpi-modal-field">
                                    <span class="sdpi-modal-label">Ganancia empresa</span>
                                    <span class="sdpi-modal-value">+$<?php echo number_format((float) $quote->company_profit, 2); ?></span>
                                </div>
                                <?php if ($quote->maritime_involved && $quote->maritime_cost > 0) : ?>
                                    <div class="sdpi-modal-field">
                                        <span class="sdpi-modal-label">Costo mar&iacute;timo</span>
                                        <span class="sdpi-modal-value">+$<?php echo number_format((float) $quote->maritime_cost, 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="sdpi-modal-total">
                                    Total final: $<?php echo number_format((float) $quote->final_price, 2); ?>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <p class="sdpi-modal-error">Error en la cotizaci&oacute;n.</p>
                        <?php if ($quote->error_message) : ?>
                            <div class="sdpi-modal-alert"><?php echo esc_html($quote->error_message); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            </section>

            <?php if ($quote->maritime_involved) :
                $direction_label = '';
                if (!empty($maritime_details['direction'])) {
                    switch ($maritime_details['direction']) {
                        case 'usa_to_pr':
                            $direction_label = 'USA → Puerto Rico';
                            break;
                        case 'pr_to_usa':
                            $direction_label = 'Puerto Rico → USA';
                            break;
                        case 'pr_pr':
                            $direction_label = 'Puerto Rico ↔ Puerto Rico';
                            break;
                        default:
                            $direction_label = str_replace('_', ' ', strtoupper($maritime_details['direction']));
                    }
                }

                $contact_labels = array(
                    'name' => 'Nombre',
                    'street' => 'Direcci&oacute;n',
                    'city' => 'Ciudad',
                    'state' => 'Estado',
                    'country' => 'Pa&iacute;s',
                    'zip' => 'ZIP',
                    'phone1' => 'Tel&eacute;fono 1',
                    'phone2' => 'Tel&eacute;fono 2'
                );
                $vehicle_labels = array(
                    'conditions' => 'Condici&oacute;n',
                    'fuel_type' => 'Tipo de combustible',
                    'unit_value' => 'Valor declarado',
                    'color' => 'Color',
                    'dimensions' => 'Dimensiones'
                );
                $other_labels = array(
                    'title' => 'T&iacute;tulo',
                    'registration' => 'Registro',
                    'id' => 'Identificaci&oacute;n'
                );
            ?>
                <section class="sdpi-modal-section">
                    <h3 class="sdpi-modal-section-title">Informaci&oacute;n Mar&iacute;tima</h3>
                    <article class="sdpi-modal-card is-accent">
                        <div class="sdpi-modal-grid sdpi-modal-grid--two">
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Puerto USA</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->us_port_name); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">ZIP del puerto</span>
                                <span class="sdpi-modal-value"><?php echo esc_html($quote->us_port_zip); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Costo mar&iacute;timo</span>
                                <span class="sdpi-modal-value">$<?php echo number_format((float) $quote->maritime_cost, 2); ?></span>
                            </div>
                            <div class="sdpi-modal-field">
                                <span class="sdpi-modal-label">Costo terrestre</span>
                                <span class="sdpi-modal-value">$<?php echo number_format((float) $quote->total_terrestrial_cost, 2); ?></span>
                            </div>
                        </div>
                        <?php if ($direction_label) : ?>
                            <div class="sdpi-modal-note">Direcci&oacute;n: <?php echo esc_html($direction_label); ?></div>
                        <?php endif; ?>
                    </article>
                    <?php
                        $contact_sections = array(
                            'shipper' => 'Shipper Information',
                            'consignee' => 'Consignee Information',
                            'pickup' => 'Pick Up Information',
                            'dropoff' => 'Drop Off Information'
                        );
                        $has_contact_details = false;
                        foreach ($contact_sections as $section_key => $section_title) {
                            if (!empty($maritime_details[$section_key]) && is_array($maritime_details[$section_key])) {
                                $filtered = array_filter($maritime_details[$section_key], function($value) {
                                    return is_string($value) ? trim($value) !== '' : !empty($value);
                                });
                                if (!empty($filtered)) {
                                    $has_contact_details = true;
                                    break;
                                }
                            }
                        }
                        $has_vehicle_details = !empty(array_filter(
                            isset($maritime_details['vehicle']) && is_array($maritime_details['vehicle']) ? $maritime_details['vehicle'] : array(),
                            function($value) {
                                return is_string($value) ? trim($value) !== '' : !empty($value);
                            }
                        ));
                        $has_other_details = !empty(array_filter(
                            isset($maritime_details['others']) && is_array($maritime_details['others']) ? $maritime_details['others'] : array(),
                            function($value) {
                                return is_string($value) ? trim($value) !== '' : !empty($value);
                            }
                        ));
                    ?>
                    <?php if ($has_contact_details || $has_vehicle_details || $has_other_details) : ?>
                        <article class="sdpi-modal-card">
                            <?php foreach ($contact_sections as $section_key => $section_title) :
                                if (empty($maritime_details[$section_key]) || !is_array($maritime_details[$section_key])) {
                                    continue;
                                }
                                $section_values = array_filter($maritime_details[$section_key], function($value) {
                                    return is_string($value) ? trim($value) !== '' : !empty($value);
                                });
                                if (empty($section_values)) {
                                    continue;
                                }
                            ?>
                                <div class="sdpi-modal-subcard">
                                    <h4 class="sdpi-modal-subcard-title"><?php echo esc_html($section_title); ?></h4>
                                    <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--compact">
                                        <?php foreach ($contact_labels as $field_key => $field_label) :
                                            if (empty($maritime_details[$section_key][$field_key])) {
                                                continue;
                                            }
                                        ?>
                                            <div class="sdpi-modal-field">
                                                <span class="sdpi-modal-label"><?php echo esc_html($field_label); ?></span>
                                                <span class="sdpi-modal-value"><?php echo esc_html($maritime_details[$section_key][$field_key]); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($has_vehicle_details) : ?>
                                <div class="sdpi-modal-subcard">
                                    <h4 class="sdpi-modal-subcard-title">Vehicle Information</h4>
                                    <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--compact">
                                        <?php foreach ($vehicle_labels as $field_key => $field_label) :
                                            if (empty($maritime_details['vehicle'][$field_key])) {
                                                continue;
                                            }
                                        ?>
                                            <div class="sdpi-modal-field">
                                                <span class="sdpi-modal-label"><?php echo esc_html($field_label); ?></span>
                                                <span class="sdpi-modal-value"><?php echo esc_html($maritime_details['vehicle'][$field_key]); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($has_other_details) : ?>
                                <div class="sdpi-modal-subcard">
                                    <h4 class="sdpi-modal-subcard-title">Otros</h4>
                                    <div class="sdpi-modal-grid sdpi-modal-grid--two sdpi-modal-grid--compact">
                                        <?php foreach ($other_labels as $field_key => $field_label) :
                                            if (empty($maritime_details['others'][$field_key])) {
                                                continue;
                                            }
                                        ?>
                                            <div class="sdpi-modal-field">
                                                <span class="sdpi-modal-label"><?php echo esc_html($field_label); ?></span>
                                                <span class="sdpi-modal-value"><?php echo esc_html($maritime_details['others'][$field_key]); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($api_response && is_array($api_response)) : ?>
                <section class="sdpi-modal-section">
                    <h3 class="sdpi-modal-section-title">Respuesta de la API</h3>
                    <article class="sdpi-modal-card">
                        <pre class="sdpi-modal-pre"><?php echo esc_html(json_encode($api_response, JSON_PRETTY_PRINT)); ?></pre>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($quote->price_breakdown) : ?>
                <section class="sdpi-modal-section">
                    <h3 class="sdpi-modal-section-title">Desglose Visual</h3>
                    <article class="sdpi-modal-card">
                        <div class="sdpi-modal-html"><?php echo wp_kses_post($quote->price_breakdown); ?></div>
                    </article>
                </section>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Export history
     */
    public function ajax_export_history() {
        check_ajax_referer('sdpi_export_history', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        // Get all history data
        $data = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC", ARRAY_A);
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sdpi-history-' . date('Y-m-d') . '.csv"');
        
        // Create CSV
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, array(
            'ID', 'Session ID', 'Estado del Flujo', 'Fecha', 'IP Usuario', 'Cliente Nombre', 'Cliente Telefono', 'Cliente Email', 'Info Capturada',
            'Origen Ciudad', 'Origen ZIP', 'Destino Ciudad', 'Destino ZIP',
            'Tipo Trailer', 'Tipo Vehiculo', 'Marca', 'Modelo', 'Año', 'No Operativo', 'Vehiculo Electrico',
            'Contacto Origen', 'Direccion Origen', 'Tipo de Recogida', 'Contacto Destino', 'Direccion Destino', 'Detalle Adicional de Envio',
            'Precio API', 'Confianza API', 'Precio por Milla', 'Precio Final', 'Ganancia Empresa',
            'Ajuste Confianza', 'Maritimo', 'Costo Maritimo', 'Puerto USA', 'ZIP Puerto',
            'Costo Terrestre Total', 'Costo Maritimo Total', 'Mensaje Error', 'Fecha Actualizacion Estado'
        ));
        
        // Data rows
        foreach ($data as $row) {
            fputcsv($output, array(
                $row['id'],
                $row['session_id'],
                $row['flow_status'],
                $row['created_at'],
                $row['user_ip'],
                $row['client_name'],
                $row['client_phone'],
                $row['client_email'],
                $row['client_info_captured_at'],
                $row['pickup_city'],
                $row['pickup_zip'],
                $row['delivery_city'],
                $row['delivery_zip'],
                $row['trailer_type'],
                $row['vehicle_type'],
                $row['vehicle_make'],
                $row['vehicle_model'],
                $row['vehicle_year'],
                $row['vehicle_inoperable'] ? 'Si' : 'No',
                $row['vehicle_electric'] ? 'Si' : 'No',
                $row['pickup_contact_name'],
                $row['pickup_contact_street'],
                $row['pickup_contact_type'],
                $row['delivery_contact_name'],
                $row['delivery_contact_street'],
                $row['additional_shipping'],
                $row['api_price'],
                $row['api_confidence'],
                $row['api_price_per_mile'],
                $row['final_price'],
                $row['company_profit'],
                $row['confidence_adjustment'],
                $row['maritime_involved'] ? 'Si' : 'No',
                $row['maritime_cost'],
                $row['us_port_name'],
                $row['us_port_zip'],
                $row['total_terrestrial_cost'],
                $row['total_maritime_cost'],
                $row['error_message'],
                $row['status_updated_at']
            ));
        }
        
        fclose($output);
        exit;
    }

    /**
     * AJAX: Bulk send selected history rows to Zapier
     */
    public function ajax_bulk_send_zapier() {
        check_ajax_referer('sdpi_bulk_send', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : array();
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_error('No se recibieron IDs.');
        }

        global $wpdb;
        $sent = 0; $failed = 0;
        $form = new SDPI_Form();

        foreach ($ids as $id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
            if (!$row) { $failed++; continue; }

            $zapier_args = $this->build_zapier_arguments_from_history_row($row);
            if (!$zapier_args) { $failed++; continue; }

            try {
                $result = $form->send_to_zapier(
                    $zapier_args['pickup_zip'],
                    $zapier_args['delivery_zip'],
                    $zapier_args['trailer_type'],
                    $zapier_args['vehicle_type'],
                    $zapier_args['vehicle_inoperable'],
                    $zapier_args['vehicle_electric'],
                    $zapier_args['vehicle_make'],
                    $zapier_args['vehicle_model'],
                    $zapier_args['vehicle_year'],
                    $zapier_args['final'],
                    $zapier_args['involves_maritime'],
                    $zapier_args['extra']
                );

                if (is_wp_error($result)) {
                    $this->mark_zapier_status($row->session_id, 'error', array(
                        'trigger_reason' => 'manual',
                        'last_error' => $result->get_error_message()
                    ));
                    $failed++;
                    continue;
                }

                $this->mark_zapier_status($row->session_id, 'sent', array(
                    'trigger_reason' => 'manual',
                    'last_error' => ''
                ));
                $sent++;
            } catch (Exception $e) {
                $this->mark_zapier_status($row->session_id, 'error', array(
                    'trigger_reason' => 'manual',
                    'last_error' => $e->getMessage()
                ));
                $failed++;
            }
        }

        wp_send_json_success(array('sent' => $sent, 'failed' => $failed));
    }

    /**
     * Ensure history table has latest columns
     */
    private function maybe_upgrade_schema() {
        global $wpdb;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)) !== $this->table_name) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}", 0);
        $missing = array();

        $target_columns = array(
            'vehicle_electric' => "ALTER TABLE {$this->table_name} ADD COLUMN vehicle_electric TINYINT(1) DEFAULT 0 AFTER vehicle_year",
            'pickup_contact_name' => "ALTER TABLE {$this->table_name} ADD COLUMN pickup_contact_name VARCHAR(100) AFTER vehicle_electric",
            'pickup_contact_street' => "ALTER TABLE {$this->table_name} ADD COLUMN pickup_contact_street VARCHAR(255) AFTER pickup_contact_name",
            'pickup_contact_type' => "ALTER TABLE {$this->table_name} ADD COLUMN pickup_contact_type VARCHAR(50) AFTER pickup_contact_street",
            'delivery_contact_name' => "ALTER TABLE {$this->table_name} ADD COLUMN delivery_contact_name VARCHAR(100) AFTER pickup_contact_type",
            'delivery_contact_street' => "ALTER TABLE {$this->table_name} ADD COLUMN delivery_contact_street VARCHAR(255) AFTER delivery_contact_name",
            'additional_shipping' => "ALTER TABLE {$this->table_name} ADD COLUMN additional_shipping LONGTEXT AFTER delivery_contact_street",
            'inoperable_fee' => "ALTER TABLE {$this->table_name} ADD COLUMN inoperable_fee DECIMAL(10,2) DEFAULT 0 AFTER total_maritime_cost",
            'documentation_files' => "ALTER TABLE {$this->table_name} ADD COLUMN documentation_files LONGTEXT AFTER maritime_details",
            'zapier_scheduled_at' => "ALTER TABLE {$this->table_name} ADD COLUMN zapier_scheduled_at DATETIME DEFAULT NULL AFTER zapier_last_sent_at",
            'zapier_trigger_reason' => "ALTER TABLE {$this->table_name} ADD COLUMN zapier_trigger_reason VARCHAR(20) DEFAULT NULL AFTER zapier_scheduled_at",
            'zapier_last_error' => "ALTER TABLE {$this->table_name} ADD COLUMN zapier_last_error TEXT AFTER zapier_trigger_reason"
        );

        foreach ($target_columns as $column => $ddl) {
            if (!in_array($column, $columns, true)) {
                $missing[$column] = $ddl;
            }
        }

        foreach ($missing as $ddl) {
            $wpdb->query($ddl);
        }
    }

    /**
     * Normalize and sanitize documentation entries coming from various sources
     */
    private function sanitize_documentation_entries($entries) {
        if (empty($entries)) {
            return array();
        }

        if (is_string($entries)) {
            $decoded = json_decode($entries, true);
            $entries = is_array($decoded) ? $decoded : array();
        }

        if (!is_array($entries)) {
            return array();
        }

        $sanitized = array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $attachment_id = isset($entry['id']) ? intval($entry['id']) : 0;
            if (!$attachment_id) {
                continue;
            }

            $url = isset($entry['url']) ? esc_url_raw($entry['url']) : '';
            if (empty($url)) {
                $url = esc_url_raw(wp_get_attachment_url($attachment_id));
            }
            if (empty($url)) {
                continue;
            }

            $size = isset($entry['size']) ? intval($entry['size']) : 0;
            if (!$size) {
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $size = (int) filesize($file_path);
                }
            }

            $uploaded_at = isset($entry['uploaded_at']) ? sanitize_text_field($entry['uploaded_at']) : '';
            if (empty($uploaded_at)) {
                $meta_uploaded = get_post_meta($attachment_id, '_sdpi_document_uploaded_at', true);
                if (!empty($meta_uploaded)) {
                    $uploaded_at = sanitize_text_field($meta_uploaded);
                }
            }

            $sanitized[$attachment_id] = array(
                'id' => $attachment_id,
                'url' => $url,
                'name' => isset($entry['name']) ? sanitize_text_field($entry['name']) : ('document-' . $attachment_id),
                'type' => isset($entry['type']) ? sanitize_text_field($entry['type']) : '',
                'size' => $size,
                'uploaded_at' => $uploaded_at
            );
        }

        return array_values($sanitized);
    }

    /**
     * Retrieve documentation references stored in the session table when needed
     */
    private function get_documentation_files_from_session($session_id) {
        if (empty($session_id)) {
            return array();
        }

        $session = new SDPI_Session();
        $session_row = $session->get($session_id);
        if (!$session_row || empty($session_row['data'])) {
            return array();
        }

        $data = is_array($session_row['data']) ? $session_row['data'] : array();
        $sources = array();

        if (!empty($data['documentation_files'])) {
            $sources[] = $data['documentation_files'];
        }
        if (!empty($data['quote']['documentation_files'])) {
            $sources[] = $data['quote']['documentation_files'];
        }

        if (empty($sources)) {
            return array();
        }

        $merged = array();
        foreach ($sources as $source) {
            foreach ($this->sanitize_documentation_entries($source) as $entry) {
                $merged[$entry['id']] = $entry;
            }
        }

        return array_values($merged);
    }
}

