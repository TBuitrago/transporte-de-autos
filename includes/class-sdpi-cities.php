<?php
/**
 * Cities database handler for Transporte de Autos
 */

class SDPI_Cities {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sdpi_cities';
        $this->init_hooks();
    }

    public function init_hooks() {
        // Add AJAX handlers for city search
        add_action('wp_ajax_sdpi_search_cities', array($this, 'ajax_search_cities'));
        add_action('wp_ajax_nopriv_sdpi_search_cities', array($this, 'ajax_search_cities'));
        
        // Add admin menu for cities management
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Create cities table
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            city varchar(100) NOT NULL,
            state_id varchar(2) NOT NULL,
            state_name varchar(50) NOT NULL,
            county_name varchar(100) NOT NULL,
            lat decimal(10,6) NOT NULL,
            lng decimal(10,6) NOT NULL,
            population int(11) DEFAULT NULL,
            age_median decimal(4,1) DEFAULT NULL,
            income int(11) DEFAULT NULL,
            zips text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY city (city),
            KEY state_id (state_id),
            KEY state_name (state_name),
            KEY lat (lat),
            KEY lng (lng)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }


    /**
     * AJAX handler for city search
     */
    public function ajax_search_cities() {
        // Check nonce for security
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'sdpi_nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }

        $query = sanitize_text_field($_POST['query'] ?? $_GET['query'] ?? '');
        $limit = intval($_POST['limit'] ?? $_GET['limit'] ?? 10);

        if (strlen($query) < 2) {
            wp_send_json_success(array());
            exit;
        }

        $cities = $this->search_cities($query, $limit);
        wp_send_json_success($cities);
        exit;
    }

    /**
     * Search cities in database (by city name or ZIP code)
     */
    public function search_cities($query, $limit = 10) {
        global $wpdb;

        // Clean the query
        $search_query = '%' . $wpdb->esc_like($query) . '%';
        
        // Check if query is numeric (ZIP code search)
        $is_zip_search = is_numeric($query);
        
        if ($is_zip_search) {
            // Search by ZIP code
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT city, state_id, zips 
                 FROM {$this->table_name} 
                 WHERE zips LIKE %s 
                 ORDER BY city ASC
                 LIMIT %d",
                $search_query,
                $limit
            ));
        } else {
            // Search by city name
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT city, state_id, zips 
                 FROM {$this->table_name} 
                 WHERE city LIKE %s 
                 ORDER BY city ASC
                 LIMIT %d",
                $search_query,
                $limit
            ));
        }

        $cities = array();
        foreach ($results as $result) {
            // For ZIP searches, show the ZIP code in the display text
            if ($is_zip_search) {
                $zip_codes = explode(' ', $result->zips);
                $matching_zips = array_filter($zip_codes, function($zip) use ($query) {
                    return strpos($zip, $query) !== false;
                });
                $display_text = $result->city . ', ' . $result->state_id . ' (' . implode(', ', $matching_zips) . ')';
            } else {
                $display_text = $result->city . ', ' . $result->state_id;
            }
            
            $cities[] = array(
                'id' => $result->city . ', ' . $result->state_id,
                'text' => $display_text,
                'city' => $result->city,
                'state_id' => $result->state_id,
                'zips' => $result->zips,
                'search_type' => $is_zip_search ? 'zip' : 'city'
            );
        }

        return $cities;
    }

    /**
     * Get city by name and state
     */
    public function get_city($city, $state_id) {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE city = %s AND state_id = %s 
             LIMIT 1",
            $city,
            $state_id
        ));

        return $result;
    }

    /**
     * Get ZIP codes for a city
     */
    public function get_city_zips($city, $state_id) {
        $city_data = $this->get_city($city, $state_id);
        
        if (!$city_data || empty($city_data->zips)) {
            return array();
        }

        return array_map('trim', explode(' ', $city_data->zips));
    }

    /**
     * Add admin menu for cities management
     */
    public function add_admin_menu() {
        add_submenu_page(
            'sdpi-settings',
            'Cities Management',
            'Cities',
            'manage_options',
            'sdpi-cities',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page for cities management
     */
    public function admin_page() {
        global $wpdb;

        // Handle form submissions
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'import_cities' && isset($_FILES['cities_file'])) {
                $this->handle_import();
            } elseif ($_POST['action'] === 'clear_cities') {
                $this->clear_cities();
            }
        }

        // Get cities count
        $cities_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        ?>
        <div class="wrap">
            <h1>Cities Management</h1>
            
            <div class="card">
                <h2>Database Status</h2>
                <p><strong>Cities in database:</strong> <?php echo number_format($cities_count); ?></p>
                
                <?php if ($cities_count == 0): ?>
                    <div class="notice notice-warning">
                        <p>No cities found in database. Please import the cities data.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Import Cities Data</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_cities">
                    <?php wp_nonce_field('sdpi_import_cities', 'sdpi_import_nonce'); ?>
                    
                    <p>
                        <label for="cities_file">Select CSV file:</label><br>
                        <input type="file" id="cities_file" name="cities_file" accept=".csv" required>
                    </p>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Import Cities">
                    </p>
                </form>
                
                <p><strong>Instructions:</strong></p>
                <ol>
                    <li>Download the cities data from <a href="https://simplemaps.com/data/us-cities" target="_blank">simplemaps.com</a></li>
                    <li>Save as CSV file</li>
                    <li>Upload and import</li>
                </ol>
            </div>

            <?php if ($cities_count > 0): ?>
            <div class="card">
                <h2>Test City Search</h2>
                <input type="text" id="test-city-search" placeholder="Type city name..." style="width: 300px;">
                <div id="test-results" style="margin-top: 10px;"></div>
            </div>

            <div class="card">
                <h2>Database Management</h2>
                <form method="post" onsubmit="return confirm('Are you sure you want to clear all cities?');">
                    <input type="hidden" name="action" value="clear_cities">
                    <?php wp_nonce_field('sdpi_clear_cities', 'sdpi_clear_nonce'); ?>
                    <input type="submit" class="button button-secondary" value="Clear All Cities">
                </form>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#test-city-search').on('input', function() {
                var query = $(this).val();
                if (query.length < 2) {
                    $('#test-results').html('');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sdpi_search_cities',
                        nonce: '<?php echo wp_create_nonce('sdpi_nonce'); ?>',
                        query: query,
                        limit: 5
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<ul>';
                            response.data.forEach(function(city) {
                                html += '<li>' + city.text + ' (ZIPs: ' + city.zips + ')</li>';
                            });
                            html += '</ul>';
                            $('#test-results').html(html);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle CSV import
     */
    private function handle_import() {
        if (!wp_verify_nonce($_POST['sdpi_import_nonce'], 'sdpi_import_cities')) {
            wp_die('Security check failed');
        }

        if (!isset($_FILES['cities_file']) || $_FILES['cities_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('File upload failed');
        }

        $file = $_FILES['cities_file']['tmp_name'];
        $handle = fopen($file, 'r');
        
        if (!$handle) {
            wp_die('Could not open file');
        }

        // Skip header row
        $header = fgetcsv($handle);
        
        $imported = 0;
        $errors = 0;

        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < 10) {
                $errors++;
                continue;
            }

            $result = $this->insert_city($data);
            if ($result) {
                $imported++;
            } else {
                $errors++;
            }
        }

        fclose($handle);

        echo "<div class='notice notice-success'><p>Import completed. Imported: {$imported}, Errors: {$errors}</p></div>";
    }

    /**
     * Insert city data
     */
    private function insert_city($data) {
        global $wpdb;

        return $wpdb->insert(
            $this->table_name,
            array(
                'city' => sanitize_text_field($data[0]),
                'state_id' => sanitize_text_field($data[1]),
                'state_name' => sanitize_text_field($data[2]),
                'county_name' => sanitize_text_field($data[3]),
                'lat' => floatval($data[4]),
                'lng' => floatval($data[5]),
                'population' => intval($data[6]),
                'age_median' => floatval($data[7]),
                'income' => intval($data[8]),
                'zips' => sanitize_text_field($data[9])
            ),
            array('%s', '%s', '%s', '%s', '%f', '%f', '%d', '%f', '%d', '%s')
        );
    }

    /**
     * Clear all cities
     */
    private function clear_cities() {
        if (!wp_verify_nonce($_POST['sdpi_clear_nonce'], 'sdpi_clear_cities')) {
            wp_die('Security check failed');
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        echo "<div class='notice notice-success'><p>All cities cleared from database.</p></div>";
    }
}
