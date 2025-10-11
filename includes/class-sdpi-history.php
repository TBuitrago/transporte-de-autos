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
            price_breakdown text,
            error_message text,
            zapier_status varchar(20) DEFAULT 'pending',
            zapier_last_sent_at datetime DEFAULT NULL,
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
            jQuery('body').append('<div id="sdpi-details-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;"><div style="background: white; padding: 20px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto; position: relative;"><div style="text-align: center; padding: 20px;">Cargando detalles...</div></div></div>');
            
            // Fetch details via AJAX
            jQuery.post(ajaxurl, {
                action: 'sdpi_get_quote_details',
                id: id,
                nonce: '<?php echo wp_create_nonce('sdpi_get_details'); ?>'
            }, function(response) {
                if (response.success) {
                    jQuery('#sdpi-details-modal').html(response.data);
                } else {
                    jQuery('#sdpi-details-modal').html('<div style="background: white; padding: 20px; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto; position: relative;"><h3>Error</h3><p>' + response.data + '</p><button onclick="jQuery(\'#sdpi-details-modal\').remove();" style="margin-top: 10px; padding: 8px 16px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Cerrar</button></div>');
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
        if (isset($_POST['sdpi_manual_send'])) {
            check_admin_referer('sdpi_manual_send_action', 'sdpi_manual_send_nonce');
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');
            if (!empty($session_id)) {
                $session = new SDPI_Session();
                $row = $session->get($session_id);
                if ($row) {
                    // Build minimal payload and reuse existing sender
                    $data = is_array($row['data']) ? $row['data'] : array();
                    $quote = isset($data['quote']) ? $data['quote'] : array();
                    $final = isset($quote['final']) ? $quote['final'] : array();
                    $pickup_zip = isset($quote['pickup_zip']) ? $quote['pickup_zip'] : '';
                    $delivery_zip = isset($quote['delivery_zip']) ? $quote['delivery_zip'] : '';
                    $trailer_type = isset($quote['trailer_type']) ? $quote['trailer_type'] : '';
                    $vehicle = isset($quote['vehicle']) ? $quote['vehicle'] : array();
                    $form = new SDPI_Form();
                    $ref = new ReflectionClass($form);
                    $m = $ref->getMethod('send_to_zapier');
                    $m->setAccessible(true);
                    $m->invoke($form,
                        $pickup_zip,
                        $delivery_zip,
                        $trailer_type,
                        isset($vehicle['type']) ? $vehicle['type'] : '',
                        isset($vehicle['inoperable']) ? $vehicle['inoperable'] : false,
                        isset($vehicle['electric']) ? $vehicle['electric'] : false,
                        isset($vehicle['make']) ? $vehicle['make'] : '',
                        isset($vehicle['model']) ? $vehicle['model'] : '',
                        isset($vehicle['year']) ? $vehicle['year'] : '',
                        $final,
                        isset($final['maritime_involved']) ? $final['maritime_involved'] : false
                    );
                    $message = 'Datos enviados a Zapier.';
                } else {
                    $message = 'Sesión no encontrada.';
                }
            } else {
                $message = 'Ingrese un Session ID válido.';
            }
        }

        ?>
        <div class="wrap">
            <h1>Enviar a Zapier (manual)</h1>
            <?php if (!empty($message)): ?>
                <div class="notice notice-info"><p><?php echo esc_html($message); ?></p></div>
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
                        <?php if ($item->zapier_status === 'sent'): ?>
                            <span class="sdpi-terrestrial-badge">Enviado</span>
                            <?php if (!empty($item->zapier_last_sent_at)): ?>
                                <br><small><?php echo esc_html(date('Y-m-d H:i', strtotime($item->zapier_last_sent_at))); ?></small>
                            <?php endif; ?>
                        <?php elseif ($item->zapier_status === 'error'): ?>
                            <span class="sdpi-maritime-badge" style="background:#d63638;">Error</span>
                        <?php else: ?>
                            <span class="sdpi-maritime-badge" style="background:#777;">Pendiente</span>
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
            'maritime_details' => $maritime_details,
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
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
            '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%d', '%s', '%f', '%s', '%s', '%f', '%f',
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
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
                'price_breakdown' => $price_breakdown,
                'error_message' => $error_message
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
                '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%d', '%s', '%f', '%s', '%s', '%f', '%f',
                '%s', '%s'
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
        $html = '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; max-height: 90vh; overflow-y: auto; position: relative;">';
        
        // Header
        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e1e5e9;">';
        $html .= '<h2 style="margin: 0; color: #23282d;">Detalles de Cotización #' . $quote->id . '</h2>';
        $html .= '<button onclick="sdpiCloseModal();" style="background: #d63638; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px;">✕ Cerrar</button>';
        $html .= '</div>';
        
        // Basic Info
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">';

        // Left Column
        $html .= '<div>';
        $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">👤 Información del Cliente</h3>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
        if ($quote->client_name) {
            $html .= '<p><strong>Nombre:</strong> ' . esc_html($quote->client_name) . '</p>';
            $html .= '<p><strong>Email:</strong> ' . esc_html($quote->client_email) . '</p>';
            $html .= '<p><strong>Teléfono:</strong> ' . esc_html($quote->client_phone) . '</p>';
            $html .= '<p><strong>Info Capturada:</strong> ' . date('d/m/Y H:i', strtotime($quote->client_info_captured_at)) . '</p>';
        } else {
            $html .= '<p><em style="color: #999;">Cliente no registrado</em></p>';
        }
        $html .= '</div>';
        $html .= '</div>';

        // Right Column
        $html .= '<div>';
        $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">📅 Información General</h3>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
        $html .= '<p><strong>Fecha y Hora:</strong> ' . date('d/m/Y H:i:s', strtotime($quote->created_at)) . '</p>';
        $html .= '<p><strong>ID de Cotización:</strong> #' . $quote->id . '</p>';
        $html .= '<p><strong>IP del Usuario:</strong> ' . esc_html($quote->user_ip) . '</p>';
        $html .= '<p><strong>Navegador:</strong> ' . esc_html(substr($quote->user_agent, 0, 50)) . '...</p>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        // Shipping Info
        $additional_shipping = array();
        if (!empty($quote->additional_shipping)) {
            $decoded_shipping = json_decode($quote->additional_shipping, true);
            if (is_array($decoded_shipping)) {
                $additional_shipping = $decoded_shipping;
            }
        }

        $html .= '<div style="margin-bottom: 25px;">';
        $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">🚛 Información del Envío</h3>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">';
        $html .= '<div><strong>Origen:</strong> ' . esc_html($quote->pickup_city) . ' (' . esc_html($quote->pickup_zip) . ')</div>';
        $html .= '<div><strong>Destino:</strong> ' . esc_html($quote->delivery_city) . ' (' . esc_html($quote->delivery_zip) . ')</div>';
        $html .= '<div><strong>Tipo de Trailer:</strong> ' . esc_html(ucfirst($quote->trailer_type)) . '</div>';
        $html .= '<div><strong>Tipo de Transporte:</strong> ';
        if ($quote->maritime_involved) {
            $html .= '<span style="background: #0073aa; color: white; padding: 2px 8px; border-radius: 3px; font-size: 12px;">&#128674; Maritimo</span>';
        } else {
            $html .= '<span style="background: #00a32a; color: white; padding: 2px 8px; border-radius: 3px; font-size: 12px;">&#128667; Terrestre</span>';
        }
        $html .= '</div>';
        $html .= '</div>';
        if ($quote->pickup_contact_name || $quote->pickup_contact_street || $quote->pickup_contact_type || $quote->delivery_contact_name || $quote->delivery_contact_street) {
            $html .= '<div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">';
            if ($quote->pickup_contact_name) {
                $html .= '<div><strong>Contacto Origen:</strong> ' . esc_html($quote->pickup_contact_name) . '</div>';
            }
            if ($quote->pickup_contact_street) {
                $html .= '<div><strong>Direccion Origen:</strong> ' . esc_html($quote->pickup_contact_street) . '</div>';
            }
            if ($quote->pickup_contact_type) {
                $html .= '<div><strong>Tipo de Recogida:</strong> ' . esc_html($quote->pickup_contact_type) . '</div>';
            }
            if ($quote->delivery_contact_name) {
                $html .= '<div><strong>Contacto Destino:</strong> ' . esc_html($quote->delivery_contact_name) . '</div>';
            }
            if ($quote->delivery_contact_street) {
                $html .= '<div><strong>Direccion Destino:</strong> ' . esc_html($quote->delivery_contact_street) . '</div>';
            }
            $html .= '</div>';
        }
        if (!empty($additional_shipping['saved_at'])) {
            $html .= '<p style="margin-top: 10px; font-size: 12px; color: #6c757d;"><em>Actualizado: ' . date('d/m/Y H:i', strtotime($additional_shipping['saved_at'])) . '</em></p>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Vehicle Info
        $html .= '<div style="margin-bottom: 25px;">';
        $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">🚗 Información del Vehículo</h3>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">';
        $html .= '<div><strong>Tipo:</strong> ' . esc_html(ucfirst($quote->vehicle_type)) . '</div>';
        $html .= '<div><strong>Marca:</strong> ' . esc_html($quote->vehicle_make) . '</div>';
        $html .= '<div><strong>Modelo:</strong> ' . esc_html($quote->vehicle_model) . '</div>';
        $html .= '<div><strong>Año:</strong> ' . esc_html($quote->vehicle_year) . '</div>';
        $html .= '<div><strong>Estado:</strong> ' . ($quote->vehicle_inoperable ? '❌ No Operativo' : '✅ Operativo') . '</div>';
        $html .= '<div><strong>Elictrico:</strong> ' . ($quote->vehicle_electric ? 'Si' : 'No') . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Pricing Info
        $html .= '<div style="margin-bottom: 25px;">';
        $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">💰 Información de Precios</h3>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
        
        if ($quote->api_price > 0) {
            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
            $html .= '<div><strong>Precio Base API:</strong> $' . number_format($quote->api_price, 2) . '</div>';
            $html .= '<div><strong>Confianza API:</strong> ' . number_format($quote->api_confidence, 1) . '%</div>';
            $html .= '<div><strong>Precio por Milla:</strong> $' . number_format($quote->api_price_per_mile, 4) . '</div>';
            $html .= '<div><strong>Precio Final:</strong> <span style="color: #0073aa; font-weight: bold; font-size: 18px;">$' . number_format($quote->final_price, 2) . '</span></div>';
            $html .= '</div>';
            
            // Breakdown
            $html .= '<div style="background: white; padding: 15px; border-radius: 4px; border-left: 4px solid #0073aa;">';
            $html .= '<h4 style="margin: 0 0 10px 0; color: #23282d;">Desglose de Costos</h4>';
            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px;">';
            $html .= '<div>Precio Base API: <strong>$' . number_format($quote->api_price, 2) . '</strong></div>';
            
            if ($quote->confidence_adjustment > 0) {
                $html .= '<div>Ajuste por Confianza: <strong>+$' . number_format($quote->confidence_adjustment, 2) . '</strong></div>';
            }
            
            $html .= '<div>Ganancia Empresa: <strong>+$' . number_format($quote->company_profit, 2) . '</strong></div>';
            
            if ($quote->maritime_involved && $quote->maritime_cost > 0) {
                $html .= '<div>Costo Marítimo: <strong>+$' . number_format($quote->maritime_cost, 2) . '</strong></div>';
            }
            
            $html .= '<div style="grid-column: 1 / -1; padding-top: 10px; border-top: 1px solid #ddd; font-weight: bold; font-size: 16px; color: #0073aa;">Total Final: $' . number_format($quote->final_price, 2) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div style="color: #d63638; font-weight: bold;">❌ Error en la cotización</div>';
            if ($quote->error_message) {
                $html .= '<div style="margin-top: 10px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">' . esc_html($quote->error_message) . '</div>';
            }
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        // Maritime Info (if applicable)
        if ($quote->maritime_involved) {
            $html .= '<div style="margin-bottom: 25px;">';
            $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">🚢 Información Marítima</h3>';
            $html .= '<div style="background: #e7f3ff; padding: 15px; border-radius: 6px; border-left: 4px solid #0073aa;">';
            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">';
            $html .= '<div><strong>Puerto USA:</strong> ' . esc_html($quote->us_port_name) . '</div>';
            $html .= '<div><strong>ZIP Puerto:</strong> ' . esc_html($quote->us_port_zip) . '</div>';
            $html .= '<div><strong>Costo Marítimo:</strong> $' . number_format($quote->maritime_cost, 2) . '</div>';
            $html .= '<div><strong>Costo Terrestre:</strong> $' . number_format($quote->total_terrestrial_cost, 2) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // API Response (if available)
        if ($api_response && is_array($api_response)) {
            $html .= '<div style="margin-bottom: 25px;">';
            $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">🔌 Respuesta de la API</h3>';
            $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
            $html .= '<pre style="background: white; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; margin: 0;">' . esc_html(json_encode($api_response, JSON_PRETTY_PRINT)) . '</pre>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // Price Breakdown HTML (if available)
        if ($quote->price_breakdown) {
            $html .= '<div style="margin-bottom: 25px;">';
            $html .= '<h3 style="color: #0073aa; margin-bottom: 15px; font-size: 16px;">📋 Desglose Visual</h3>';
            $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">';
            $html .= $quote->price_breakdown;
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
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
        $ref = new ReflectionClass($form);
        $m = $ref->getMethod('send_to_zapier');
        $m->setAccessible(true);

        foreach ($ids as $id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
            if (!$row) { $failed++; continue; }
            $api_response = json_decode($row->api_response, true);
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
            if (empty($shipping_details['saved_at'])) {
                $shipping_details['saved_at'] = $row->status_updated_at;
            }

            $maritime_details = array();
            if (!empty($row->maritime_details)) {
                $decoded_maritime = json_decode($row->maritime_details, true);
                if (is_array($decoded_maritime)) {
                    $maritime_details = $decoded_maritime;
                }
            }

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

            try {
                $m->invoke($form,
                    $row->pickup_zip,
                    $row->delivery_zip,
                    $row->trailer_type,
                    $row->vehicle_type,
                    intval($row->vehicle_inoperable) === 1,
                    intval($row->vehicle_electric) === 1,
                    $row->vehicle_make,
                    $row->vehicle_model,
                    $row->vehicle_year,
                    $final,
                    intval($row->maritime_involved) === 1,
                    array(
                        'shipping' => $shipping_details,
                        'maritime_details' => $maritime_details,
                        'client' => array(
                            'name' => $row->client_name,
                            'email' => $row->client_email,
                            'phone' => $row->client_phone,
                            'captured_at' => $row->client_info_captured_at
                        ),
                        'transport_type' => intval($row->maritime_involved) === 1 ? 'maritime' : 'terrestrial',
                        'session_id' => $row->session_id
                    )
                );
                // mark as sent
                $wpdb->update($this->table_name, array(
                    'zapier_status' => 'sent',
                    'zapier_last_sent_at' => current_time('mysql')
                ), array('id' => $id), array('%s','%s'), array('%d'));
                $sent++;
            } catch (Exception $e) {
                $wpdb->update($this->table_name, array(
                    'zapier_status' => 'error'
                ), array('id' => $id), array('%s'), array('%d'));
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
            'inoperable_fee' => "ALTER TABLE {$this->table_name} ADD COLUMN inoperable_fee DECIMAL(10,2) DEFAULT 0 AFTER total_maritime_cost"
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
}

