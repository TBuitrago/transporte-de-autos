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

        // Buscar registro existente por session_id
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id));

        if ($existing) {
            // Actualizar registro existente
            $wpdb->update(
                $table,
                array(
                    'data' => serialize($data),
                ),
                array('session_id' => $session_id)
            );
            error_log("Registro actualizado para session_id: " . $session_id);
        } else {
            // Si no existe, crea uno nuevo
            $wpdb->insert(
                $table,
                array_merge(
                    array('session_id' => $session_id, 'estado' => 'Cotizador'),
                    $data
                )
            );
            error_log("Registro creado para session_id (no debería duplicarse): " . $session_id);
        }
    }
}