# Transporte de Autos - Documentación Técnica

## Perfil del Desarrollador
**Tomas Buitrago**  
TBA Digitals  
Contacto: [sdpi@tbadigitals.com](mailto:sdpi@tbadigitals.com)  
Website: [https://tbadigitals.com](https://tbadigitals.com)

---

## Arquitectura General

```text
Cliente → Formulario SDPI → AJAX → SDPI_Form →
    ├── SDPI_Session (persistencia)
    ├── SDPI_API (Super Dispatch)
    ├── SDPI_Maritime (lógica PR)
    └── SDPI_History (tracking + Zapier)
                            ↓
                     Authorize.net (Accept.js)
```

- **Front-end**: `assets/form-script.js` controla autocompletado, resumen lateral, formularios intermedios, pagos y envío a Zapier.
- **Back-end**: las clases en `includes/` encapsulan API, sesiones, tarifas marítimas, historial administrativo y ajustes.
- **Persistencia**: todas las transacciones se vinculan a un `session_id` y se guardan tanto en `wp_sdpi_quote_sessions` como en `wp_sdpi_history`.

### Flujo de Estados
Cada cotización avanza por los estados `inicial → cotizador → checkout → completado`. El estado vive en `wp_sdpi_history.flow_status` y se actualiza desde `SDPI_Form` a través de métodos de `SDPI_History`.

---

## Clases Principales

### `SDPI_Settings`
- Renderiza la pantalla de ajustes (`Settings → Transporte de Autos`).
- Registra opciones de API key, caché, credenciales de Authorize.net, URLs de éxito/error y webhook de Zapier.
- Expone botones de prueba para la API de Super Dispatch.

### `SDPI_API`
- Encapsula las peticiones a `https://api.superdispatch.com/v1/recommended-price`.
- Maneja autenticación por API key, timeouts y reintentos.
- Normaliza respuestas con `recommended_price`, `confidence` y `price_per_mile`.

### `SDPI_Form`
Responsable del flujo completo del formulario, AJAX y pagos:
- `render_form()` imprime el HTML y el panel de resumen.
- `ajax_save_client_info()` inicia la sesión y registra estado `inicial`.
- `ajax_get_quote()` sanitiza, detecta tramos marítimos, llama a la API y devuelve `needs_contact_info`.
- `ajax_finalize_quote_with_contact()` asocia contacto, guarda histórico y expone el precio final.
- `ajax_save_additional_info()` y `ajax_save_maritime_info()` guardan datos complementarios.
- `ajax_initiate_payment()` prepara el payload para Accept.js y cambia el estado a `checkout`.
- `ajax_process_payment()` valida el nonce de Accept.js, envía el cargo a Authorize.net y marca el estado `completado`.
- `send_to_zapier()` arma el payload del webhook (opcional) con datos de contacto, vehículo, tarifas y estado del flujo.

### `TDA_GitHub_Updater`
- Consulta periódicamente las releases públicas del repositorio `tbadigitals/transporte-de-autos`.
- Añade los datos de actualización al transient `update_plugins` y responde a la ventana de detalles (`plugins_api`).
- Expone nombre, versión, enlace al repositorio y notas del release directamente en el administrador de WordPress.

### `SDPI_Maritime`
- Utilidades para identificar si un ZIP pertenece a San Juan (`009`).
- Selecciona puerto continental según el estado (`northeast`/`southeast`).
- Expone tarifas fijas (`MARITIME_RATES`) y cálculos híbridos (terrestre + marítimo).

### `SDPI_Cities`
- Crea y mantiene `wp_sdpi_cities`.
- Implementa búsqueda AJAX (`sdpi_search_cities`) con detección de ciudad o ZIP.

### `SDPI_Session`
- Almacena un registro JSON por `session_id` con la evolución completa de la cotización.
- Métodos clave: `start_session()`, `update_data()`, `set_status()`, `get_by_session_id()`.

### `SDPI_History`
- Gestiona `wp_sdpi_history`, acciones masivas y la pantalla de historial.
- Métodos utilitarios: `create_initial_record()`, `update_to_cotizador()`, `update_to_checkout()`, `update_to_completado()` y `mark_zapier_status()`.
- Ofrece endpoints AJAX para filtros, reenvío a Zapier (`sdpi_bulk_send_zapier`) y eliminación de registros.

---

## AJAX y Endpoints Públicos

| Acción | Método | Descripción |
|-------|--------|-------------|
| `sdpi_save_client_info` | Privado/Público | Guarda datos de contacto iniciales y crea sesión. |
| `sdpi_get_quote` | Privado/Público | Procesa la cotización y retorna si requiere contacto adicional. |
| `sdpi_finalize_quote_with_contact` | Privado/Público | Confirma contacto, actualiza historial y entrega el precio final. |
| `sdpi_save_additional_info` | Privado/Público | Persiste datos de recogida/entrega adicionales. |
| `sdpi_save_maritime_info` | Privado/Público | Guarda información específica de envíos marítimos. |
| `sdpi_initiate_payment` | Privado/Público | Construye contexto de pago para Accept.js. |
| `sdpi_process_payment` | Privado/Público | Procesa token de Accept.js y captura el pago. |
| `sdpi_search_cities` | Privado/Público | Autocompletado de ciudades y ZIPs. |
| `sdpi_test_api` | Solo admins | Prueba conexión a la API de Super Dispatch desde el panel. |
| `sdpi_bulk_send_zapier` | Solo admins | Envío masivo de cotizaciones al webhook configurado. |

Todas las peticiones usan `check_ajax_referer('sdpi_nonce', 'nonce')` y sanitización estricta antes de interactuar con la API o la base de datos.

---

## Flujo de Pago con Authorize.net (Accept.js)
1. **Inicialización**: `ajax_initiate_payment()` valida que el sitio esté bajo HTTPS, verifica credenciales y arma la descripción legible del pago.
2. **Tokenización**: el front-end usa Accept.js para generar `dataValue` y `dataDescriptor` sin exponer datos sensibles al servidor.
3. **Cargo**: `ajax_process_payment()` crea la transacción con `AnetAPI\CreateTransactionRequest`, define monto y descripción y maneja respuestas/errores.
4. **Post-proceso**: al cobrar, se actualiza `wp_sdpi_history` y se dispara `send_to_zapier()` si hay webhook activo.

Errores de pago se reportan al usuario y se registran en el historial con `zapier_status = error` cuando aplica.

---

## Base de Datos

### `wp_sdpi_cities`
Catálogo de ciudades y códigos ZIP con índices en `city` y `state_id`.

### `wp_sdpi_quote_sessions`
```sql
CREATE TABLE wp_sdpi_quote_sessions (
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
    UNIQUE KEY session_id (session_id)
);
```

### `wp_sdpi_history`
Contiene información consolidada (contacto, cotización, estado del flujo, tarifas, `zapier_status`, `zapier_last_sent_at`). El script `migration-add-flow-status.php` agrega columnas de estado y marcas de tiempo si se requiere en instalaciones previas.

---

## Hooks Disponibles

### Acciones
```php
do_action('sdpi_before_form_render');
do_action('sdpi_after_form_render');
do_action('sdpi_before_api_call', $form_data);
do_action('sdpi_after_api_call', $api_response);
do_action('sdpi_before_payment_request', $payload, $session_id);
do_action('sdpi_after_payment_request', $result, $session_id);
```

### Filtros
```php
$form_data = apply_filters('sdpi_form_data', $form_data);
$api_response = apply_filters('sdpi_api_response', $api_response);
$final_price = apply_filters('sdpi_final_price', $final_price, $form_data);
$maritime_rates = apply_filters('sdpi_maritime_rates', $maritime_rates);
$zapier_payload = apply_filters('sdpi_zapier_payload', $payload, $session_id);
```

Usa estos hooks para personalizar tarifas, alterar la lógica de precios o integrar sistemas de logging adicionales.

---

## Personalización de Front-end
- **CSS**: agrega reglas sobre `.sdpi-summary-panel`, `.sdpi-pricing-form` y `.sdpi-maritime-breakdown` para personalizar estilos.
- **JS**: escucha eventos personalizados (`sdpi:quote:success`, `sdpi:payment:ready`, `sdpi:payment:complete`) definidos en `assets/form-script.js`.
- **Plantillas**: el HTML del formulario se construye en `render_form()`; puedes usar `ob_start()` y hooks para inyectar secciones adicionales.

---

## Debugging y Observabilidad
- Habilita `WP_DEBUG_LOG` y busca prefijos `SDPI`, `SDPI MARITIME` o `SDPI PAYMENT`.
- Ajusta `SDPI_Form::$debug` (si se habilita) para volcar información detallada.
- El panel de historial muestra errores de Zapier y pagos directamente en la interfaz.

### Métricas recomendadas
- Tiempo de respuesta de la API de Super Dispatch.
- Tasa de conversión por estado del flujo.
- Porcentaje de cotizaciones marítimas vs. terrestres.
- Errores de tokenización o cargos rechazados.

---

## Optimización y Buenas Prácticas
- Usa caché (`set_transient`) para respuestas de API repetidas (ya implementado en `SDPI_API`).
- Carga condicional de assets: los scripts se registran sólo en páginas con el shortcode.
- Mantén la tabla de ciudades indexada y realiza mantenimiento periódico (`OPTIMIZE TABLE`).
- Configura HTTPS obligatorio para habilitar pagos.

---

## Testing
- **Unit Tests**: valida utilidades (ej. `SDPI_Maritime::is_san_juan_zip`) con WP-CLI y PHPUnit.
- **Integración**: prueba flujos completos con `wp server` o entornos locales. Escenarios clave:
  - Cotización terrestre con pago exitoso.
  - Cotización marítima con selección automática de puerto.
  - Reintentos de Zapier y acciones masivas.
- **Smoke Tests**: utiliza el botón "Probar Conexión" y transacciones de `sandbox` para confirmar integraciones.

---

Esta documentación está pensada para desarrolladores que requieran extender, depurar o integrar el plugin en soluciones personalizadas. Para instrucciones de instalación o uso final, consulta `README.md` e `INSTALLATION.md`.
