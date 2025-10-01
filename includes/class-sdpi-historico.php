<?php
/**
 * Class SDPI_Historico
 */
class SDPI_Historico {

    /**
     * Create initial record
     *
     * @param string $session_id
     * @param array $data
     */
    public function create_initial_record($session_id, $data) {
        error_log("create_initial_record session_id: " . $session_id); // Log para depuración

        global $wpdb;
        $table = $wpdb->prefix . 'sdpi_historico';

        // Verificar si ya existe el registro
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id));
        if ($existing) {
            // Si existe, no crear duplicado
            error_log("Registro ya existe para session_id: " . $session_id);
            return;
        }

        $wpdb->insert(
            $table,
            array(
                'session_id' => $session_id,
                'data' => serialize($data),
            )
        );
    }

    /**
     * Update to cotizador
     *
     * @param string $session_id
     * @param array $data
     */
    public function update_to_cotizador($session_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdpi_historico';

        error_log("update_to_cotizador session_id: " . $session_id);

        // Buscar registro existente por session_id
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id));

        error_log("Existing record found: " . ($existing ? 'yes' : 'no') . " for session_id: " . $session_id);

        if ($existing) {
            // Actualizar registro existente
            $wpdb->update(
                $table,
                array(
                    'data' => serialize($data),
                    'estado' => 'Cotizador'
                ),
                array('session_id' => $session_id)
            );
            error_log("Registro actualizado para session_id: " . $session_id);
        } else {
            // Si no existe, log error en lugar de crear duplicado
            error_log("ERROR: No se encontró registro existente para session_id: " . $session_id . " en update_to_cotizador. No se crea duplicado.");
            // No insertar para evitar duplicados
        }
    }

    /**
     * Obtener registro por session_id
     */
    public function get_by_session_id($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdpi_historico';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id));
        if (!$row) return null;
        $row->data = unserialize($row->data);
        return $row;
    }
}