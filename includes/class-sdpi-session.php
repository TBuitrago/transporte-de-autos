<?php
/**
 * Quote session manager for consolidating partial data before external sends
 */

if (!defined('ABSPATH')) {
	exit;
}

class SDPI_Session {

	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'sdpi_quote_sessions';
	}

	/**
	 * Create sessions table
	 */
	public function create_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'started',
			client_name varchar(100) DEFAULT NULL,
			client_email varchar(100) DEFAULT NULL,
			client_phone varchar(30) DEFAULT NULL,
			data longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY status (status),
			KEY updated_at (updated_at)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	/**
	 * Start or return a session id and persist initial client info
	 */
	public function start_session($client_name, $client_email, $client_phone) {
		$session_id = $this->get_session_id();
		if (!$session_id) {
			$session_id = wp_generate_uuid4();
			$this->set_session_id($session_id);
		}

		$this->upsert($session_id, array(
			'client_name' => $client_name,
			'client_email' => $client_email,
			'client_phone' => $client_phone,
			'__meta' => array('started_at' => current_time('mysql'))
		));

		return $session_id;
	}

	/**
	 * Update arbitrary data for the session by deep-merging JSON
	 */
	public function update_data($session_id, $fields) {
		$this->upsert($session_id, $fields);
	}

	/**
	 * Mark session status
	 */
	public function set_status($session_id, $status) {
		global $wpdb;
		$wpdb->update(
			$this->table_name,
			array('status' => sanitize_text_field($status)),
			array('session_id' => sanitize_text_field($session_id)),
			array('%s'),
			array('%s')
		);
	}

	/**
	 * Fetch a session record with decoded data
	 */
	public function get($session_id) {
		global $wpdb;
		$session_id = sanitize_text_field($session_id);
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE session_id = %s", $session_id), ARRAY_A);
		if (!$row) { return null; }
		$row['data'] = !empty($row['data']) ? json_decode($row['data'], true) : array();
		return $row;
	}

	/**
	 * Get current session id from PHP session
	 */
	public function get_session_id() {
		if (!session_id()) {
			session_start();
		}
		$session_id = isset($_SESSION['sdpi_quote_session_id']) ? sanitize_text_field($_SESSION['sdpi_quote_session_id']) : '';
		error_log("get_session_id: " . $session_id); // Log para depuración
		return $session_id;
	}

	/**
	 * Store session id in PHP session
	 */
        private function set_session_id($session_id) {
                if (!session_id()) {
                        session_start();
                }
                $_SESSION['sdpi_quote_session_id'] = $session_id;
        }

        /**
         * Clear the active session identifier and related cached data
         */
        public function clear_session($session_id = '') {
                if (!session_id()) {
                        session_start();
                }

                if (empty($session_id)) {
                        $session_id = isset($_SESSION['sdpi_quote_session_id'])
                                ? sanitize_text_field($_SESSION['sdpi_quote_session_id'])
                                : '';
                }

                if (!empty($_SESSION['sdpi_quote_session_id']) && (empty($session_id) || $_SESSION['sdpi_quote_session_id'] === $session_id)) {
                        unset($_SESSION['sdpi_quote_session_id']);
                }

                unset(
                        $_SESSION['sdpi_client_info'],
                        $_SESSION['sdpi_additional_info'],
                        $_SESSION['sdpi_maritime_info']
                );

                if (!empty($session_id)) {
                        $this->delete_session_record($session_id);
                }

                return $session_id;
        }

        /**
         * Remove the persisted session row once the Zapier push is complete
         */
        private function delete_session_record($session_id) {
                global $wpdb;

                $session_id = sanitize_text_field($session_id);
                if (empty($session_id)) {
                        return;
                }

                $wpdb->delete(
                        $this->table_name,
                        array('session_id' => $session_id),
                        array('%s')
                );
        }

        /**
         * Helper para obtener el session_id actual para histórico
         */
        public function get_current_session_id() {
                return $this->get_session_id();
	}

	/**
	 * Internal upsert logic: merges JSON data
	 */
	private function upsert($session_id, $fields) {
		global $wpdb;
		$session_id = sanitize_text_field($session_id);

		$current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE session_id = %s", $session_id));
		$current_data = array();
		if ($current && !empty($current->data)) {
			$decoded = json_decode($current->data, true);
			if (is_array($decoded)) {
				$current_data = $decoded;
			}
		}

		$merged = $this->deep_merge($current_data, $fields);
		$json = wp_json_encode($merged);

		if ($current) {
			$wpdb->update(
				$this->table_name,
				array(
					'client_name' => isset($fields['client_name']) ? sanitize_text_field($fields['client_name']) : $current->client_name,
					'client_email' => isset($fields['client_email']) ? sanitize_email($fields['client_email']) : $current->client_email,
					'client_phone' => isset($fields['client_phone']) ? sanitize_text_field($fields['client_phone']) : $current->client_phone,
					'data' => $json
				),
				array('session_id' => $session_id),
				array('%s','%s','%s','%s'),
				array('%s')
			);
		} else {
			$wpdb->insert(
				$this->table_name,
				array(
					'session_id' => $session_id,
					'client_name' => isset($fields['client_name']) ? sanitize_text_field($fields['client_name']) : null,
					'client_email' => isset($fields['client_email']) ? sanitize_email($fields['client_email']) : null,
					'client_phone' => isset($fields['client_phone']) ? sanitize_text_field($fields['client_phone']) : null,
					'data' => $json,
					'status' => 'started'
				),
				array('%s','%s','%s','%s','%s','%s')
			);
		}
	}

	/**
	 * Deep merge helper for associative arrays
	 */
	private function is_sequential_array($value) {
		if (!is_array($value)) {
			return false;
		}
		return array_keys($value) === range(0, count($value) - 1);
	}

	private function deep_merge($base, $insert) {
		foreach ($insert as $key => $value) {
			if ($key === 'documentation_files') {
				$base[$key] = is_array($value) ? $value : array();
				continue;
			}

			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				if ($this->is_sequential_array($value) || $this->is_sequential_array($base[$key])) {
					$base[$key] = $value;
				} else {
					$base[$key] = $this->deep_merge($base[$key], $value);
				}
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}
}


