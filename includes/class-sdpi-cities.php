<?php
/**
 * Cities database handler for Transporte de Autos
 */

class SDPI_Cities {

    private $table_name;
    private $packaged_csv;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sdpi_cities';
        $this->packaged_csv = trailingslashit(TDA_PLUGIN_DIR) . 'assets/data/wp_sdpi_cities.csv';
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
            zips text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY city (city),
            KEY state_id (state_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Import the packaged CSV on activation or when manually requested.
     *
     * @param bool $force Whether to truncate existing data before importing.
     * @return array|WP_Error Summary or error.
     */
    public function import_packaged_cities($force = false) {
        if (!file_exists($this->packaged_csv)) {
            return new WP_Error(
                'sdpi_cities_missing_csv',
                sprintf(
                    /* translators: %s: CSV path */
                    __('Could not locate the packaged cities CSV at %s.', 'transporte-de-autos'),
                    esc_html($this->packaged_csv)
                )
            );
        }

        return $this->import_from_csv($this->packaged_csv, $force);
    }

    /**
     * Import cities from any CSV file.
     *
     * @param string $file_path
     * @param bool   $truncate_existing
     * @return array|WP_Error Summary array with imported/skipped counts.
     */
    public function import_from_csv($file_path, $truncate_existing = false) {
        global $wpdb;

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new WP_Error(
                'sdpi_cities_unreadable_csv',
                __('The provided CSV file could not be read.', 'transporte-de-autos')
            );
        }

        if ($truncate_existing) {
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        } else {
            $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            if ($existing > 0) {
                return array(
                    'imported' => 0,
                    'skipped'  => 0,
                    'existing' => $existing,
                );
            }
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error(
                'sdpi_cities_open_failed',
                __('Unable to open the CSV file for reading.', 'transporte-de-autos')
            );
        }

        $imported = 0;
        $skipped  = 0;
        $batch    = array();
        $batch_size = 500;

        $first_row = fgetcsv($handle);
        if ($first_row !== false && !$this->is_header_row($first_row)) {
            $normalized = $this->normalize_row($first_row);
            if ($normalized) {
                $batch[] = $normalized;
            } else {
                $skipped++;
            }
        }

        while (($row = fgetcsv($handle)) !== false) {
            $normalized = $this->normalize_row($row);
            if (!$normalized) {
                $skipped++;
                continue;
            }

            $batch[] = $normalized;
            if (count($batch) >= $batch_size) {
                $imported += $this->bulk_insert($batch);
                $batch = array();
            }
        }

        if (!empty($batch)) {
            $imported += $this->bulk_insert($batch);
        }

        fclose($handle);

        return array(
            'imported' => $imported,
            'skipped'  => $skipped,
        );
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
            } elseif ($_POST['action'] === 'reimport_packaged') {
                $this->handle_packaged_reimport();
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

                <p class="description">Upload a CSV that follows the <code>id, city, state_id, zips</code> format to replace the existing dataset.</p>
            </div>

            <div class="card">
                <h2>Reimport Packaged Dataset</h2>
                <form method="post">
                    <input type="hidden" name="action" value="reimport_packaged">
                    <?php wp_nonce_field('sdpi_reimport_packaged', 'sdpi_reimport_nonce'); ?>
                    <p>The plugin ships with a pre-populated CSV. Use this action to restore it at any time.</p>
                    <p><input type="submit" class="button" value="Restore Packaged Cities"></p>
                </form>
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
        $result = $this->import_from_csv($file, true);

        if (is_wp_error($result)) {
            echo "<div class='notice notice-error'><p>" . esc_html($result->get_error_message()) . "</p></div>";
            return;
        }

        echo "<div class='notice notice-success'><p>Import completed. Imported: " . intval($result['imported']) . ", Skipped: " . intval($result['skipped']) . "</p></div>";
    }

    private function handle_packaged_reimport() {
        if (!wp_verify_nonce($_POST['sdpi_reimport_nonce'], 'sdpi_reimport_packaged')) {
            wp_die('Security check failed');
        }

        $result = $this->import_packaged_cities(true);

        if (is_wp_error($result)) {
            echo "<div class='notice notice-error'><p>" . esc_html($result->get_error_message()) . "</p></div>";
            return;
        }

        echo "<div class='notice notice-success'><p>Packaged dataset restored. Imported: " . intval($result['imported']) . ", Skipped: " . intval($result['skipped']) . "</p></div>";
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

    private function normalize_row($row) {
        if (!is_array($row) || count($row) < 3) {
            return null;
        }

        $id = isset($row[0]) ? intval(preg_replace('/[^0-9]/', '', $row[0])) : 0;
        $city = isset($row[1]) ? sanitize_text_field($row[1]) : '';
        $state_id = isset($row[2]) ? strtoupper(substr(sanitize_text_field($row[2]), 0, 2)) : '';
        $zips_raw = isset($row[3]) ? $row[3] : '';

        if ($id <= 0 || $city === '' || $state_id === '') {
            return null;
        }

        $zips = $this->sanitize_zip_list($zips_raw);

        return array(
            'id' => $id,
            'city' => $city,
            'state_id' => $state_id,
            'zips' => $zips,
        );
    }

    private function sanitize_zip_list($value) {
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/[^0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function is_header_row($row) {
        if (!is_array($row)) {
            return false;
        }

        $normalized = array_map('strtolower', $row);

        return in_array('city', $normalized, true) && in_array('state_id', $normalized, true);
    }

    private function bulk_insert($batch) {
        global $wpdb;

        if (empty($batch)) {
            return 0;
        }

        $placeholders = array();
        $values = array();

        foreach ($batch as $row) {
            if ($row['zips'] === null) {
                $placeholders[] = '(%d, %s, %s, NULL)';
                $values[] = $row['id'];
                $values[] = $row['city'];
                $values[] = $row['state_id'];
            } else {
                $placeholders[] = '(%d, %s, %s, %s)';
                $values[] = $row['id'];
                $values[] = $row['city'];
                $values[] = $row['state_id'];
                $values[] = $row['zips'];
            }
        }

        $sql = "INSERT INTO {$this->table_name} (id, city, state_id, zips) VALUES " . implode(', ', $placeholders) .
            " ON DUPLICATE KEY UPDATE city = VALUES(city), state_id = VALUES(state_id), zips = VALUES(zips)";

        $prepared = $wpdb->prepare($sql, $values);
        $wpdb->query($prepared);

        return count($batch);
    }
}
