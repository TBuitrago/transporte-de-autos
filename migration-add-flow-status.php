<?php
/**
 * Migration script to add flow_status column to existing history table
 * Run this script once to update existing installations
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // If running from command line or direct access, define ABSPATH
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__FILE__) . '/../../../../');
    }
}

// Load WordPress
require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-includes/wp-db.php');
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

global $wpdb;

// Get the table name
$table_name = $wpdb->prefix . 'sdpi_history';

// Check if flow_status column exists
$column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'flow_status'");

if (empty($column_exists)) {
    echo "Adding flow_status column to {$table_name}...\n";
    
    // Add flow_status column
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN flow_status varchar(20) NOT NULL DEFAULT 'inicial' AFTER session_id");
    
    // Add status_updated_at column
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN status_updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER zapier_last_sent_at");
    
    // Add indexes
    $wpdb->query("ALTER TABLE {$table_name} ADD INDEX flow_status (flow_status)");
    
    // Update existing records based on available data
    echo "Updating existing records...\n";
    
    // Records with client info but no quote data = 'inicial'
    $wpdb->query("UPDATE {$table_name} SET flow_status = 'inicial' WHERE client_name IS NOT NULL AND client_name != '' AND pickup_zip IS NULL");
    
    // Records with quote data but no final price = 'cotizador'
    $wpdb->query("UPDATE {$table_name} SET flow_status = 'cotizador' WHERE pickup_zip IS NOT NULL AND final_price IS NULL");
    
    // Records with final price = 'completado' (assuming they went through the full process)
    $wpdb->query("UPDATE {$table_name} SET flow_status = 'completado' WHERE final_price IS NOT NULL AND final_price > 0");
    
    // Update status_updated_at for all records
    $wpdb->query("UPDATE {$table_name} SET status_updated_at = created_at WHERE status_updated_at IS NULL");
    
    echo "Migration completed successfully!\n";
    echo "Updated " . $wpdb->rows_affected . " records.\n";
} else {
    echo "flow_status column already exists. Migration not needed.\n";
}

// Check if session_id column exists
$session_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'session_id'");

if (empty($session_column_exists)) {
    echo "Adding session_id column to {$table_name}...\n";
    
    // Add session_id column
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN session_id varchar(64) NOT NULL DEFAULT '' AFTER id");
    
    // Generate session IDs for existing records
    $records = $wpdb->get_results("SELECT id FROM {$table_name} WHERE session_id = ''");
    foreach ($records as $record) {
        $session_id = wp_generate_uuid4();
        $wpdb->update(
            $table_name,
            array('session_id' => $session_id),
            array('id' => $record->id),
            array('%s'),
            array('%d')
        );
    }
    
    // Add unique index for session_id
    $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY session_id (session_id)");
    
    echo "Session ID migration completed successfully!\n";
} else {
    echo "session_id column already exists. Migration not needed.\n";
}

echo "Migration script completed.\n";
?>
