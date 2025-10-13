# Resumen de Implementación – Flujo de Cotización SDPI

## Objetivo
Entregar un flujo de cotización multipaso que capture la información del vehículo y rutas antes de solicitar los datos de contacto, conserve todo dentro de una sesión consolidada y habilite pagos directos con Authorize.net.

## Panorama General del Flujo
1. **Ingreso de datos del envío**: el cliente completa origen, destino, vehículo, tipo de tráiler y opciones marítimas.
2. **Cotización interna**: se consulta la API de Super Dispatch o se calcula el tramo terrestre cuando hay transporte marítimo.
3. **Captura de contacto**: se solicita nombre, correo y teléfono antes de revelar la tarifa.
4. **Resumen y revisión**: se muestra el panel lateral con la tarifa final, tipo de transporte y acciones disponibles.
5. **Pago (opcional)**: Accept.js tokeniza la tarjeta; el backend procesa el cargo y actualiza el historial.
6. **Integraciones**: la sesión se actualiza en `wp_sdpi_quote_sessions`, el historial registra `flow_status` y se dispara el webhook de Zapier si está configurado.

## Cambios Clave en Backend (`includes/`)
- **`class-sdpi-form.php`**
  - Nueva organización del formulario con panel `sdpi-summary-panel` y secciones condicionales (contacto, revisión, pago).
  - Métodos AJAX ampliados: `ajax_save_client_info`, `ajax_get_quote`, `ajax_finalize_quote_with_contact`, `ajax_save_additional_info`, `ajax_save_maritime_info`, `ajax_initiate_payment`, `ajax_process_payment`.
  - Consolidación de sesiones con `SDPI_Session` para almacenar `quote`, `contact`, `shipping`, `maritime` y `payment`.
  - Integración con `SDPI_History` para controlar estados `inicial`, `cotizador`, `checkout` y `completado`.
  - Envío a Zapier con payload enriquecido (contacto, vehículo, recargos marítimos y desglose de tarifas).

- **`class-sdpi-history.php`**
  - Tabla administrativa con columnas de `flow_status`, `zapier_status` y acciones masivas.
  - Métodos `create_initial_record`, `update_to_cotizador`, `update_to_checkout`, `update_to_completado`, `mark_zapier_status`.
  - Vista "Enviar a Zapier" con reenvío manual y bulk (`sdpi_bulk_send_zapier`).

- **`class-sdpi-maritime.php`**
  - Utilidades para detectar ZIPs de San Juan (`009`), seleccionar puertos continentales y calcular tarifas híbridas.
  - Tarifas fijas (`MARITIME_RATES`) y helpers para recargos de vehículos eléctricos o inoperables.

- **`class-sdpi-session.php`**
  - Tabla `wp_sdpi_quote_sessions` con almacenamiento JSON incremental por `session_id`.
  - Métodos `start_session`, `update_data`, `set_status`, `get_by_session_id`.

- **`class-sdpi-settings.php`**
  - Campos de configuración para Super Dispatch, Authorize.net, URLs de redirección, webhook de Zapier y opciones de caché.
  - Botones de prueba de API y ayudas visuales en el panel de ajustes.

## Cambios Clave en Frontend (`assets/`)
- **`form-script.js`**
  - Controla el flujo paso a paso y la navegación entre secciones (cotizador, contacto, revisión y pago).
  - Renderiza el resumen lateral, mostrando precio, tipo de transporte, puertos seleccionados y disponibilidad de pago.
  - Eventos personalizados (`sdpi:quote:success`, `sdpi:payment:ready`, `sdpi:payment:complete`) para integraciones externas.
  - Gestión del formulario Accept.js: preparación del contexto, tokenización, envío del pago y manejo de errores.
  - Manejo de estados marítimos: bloquea campos irrelevantes, muestra puertos asignados y aplica recargos específicos.

- **`form-styles.css`**
  - Estilos para el layout en columnas, panel `sdpi-summary-panel`, estados de progreso y formularios de pago.
  - Indicadores visuales para los estados `pending`, `success`, `error` y mensajes de ayuda.

## Tablas y Migraciones
- `wp_sdpi_cities`: catálogo para autocompletado.
- `wp_sdpi_quote_sessions`: sesión consolidada con datos en JSON.
- `wp_sdpi_history`: historial con `flow_status`, `zapier_status`, `zapier_last_sent_at` y tracking de pagos.
- Script `migration-add-flow-status.php`: añade columnas de estado y marcas de tiempo para instalaciones previas.

## Integraciones y Seguridad
- **Super Dispatch API**: consultas autenticadas con caché y manejo de reintentos.
- **Authorize.net Accept.js**: tokenización client-side y creación de transacciones `authorizeCapture`.
- **Zapier**: webhook configurable desde ajustes; payload filtrable vía `sdpi_zapier_payload`.
- **Seguridad**: nonces en todos los endpoints, sanitización/escape de datos, verificación de capacidades en acciones administrativas.

## Observaciones Operativas
- Requiere HTTPS para habilitar Accept.js en producción.
- El botón "Probar Conexión" valida la API de Super Dispatch; se recomienda hacer smoke tests con sandbox Authorize.net.
- `flow_status` permite analizar conversiones y detectar abandonos (consultar `FLOW-STATES-SYSTEM.md`).
- El historial expone badges de Zapier (Pendiente/Enviado/Error) y permite reenvíos manuales.

## Documentación Relacionada
- `README.md`: descripción funcional y casos de uso.
- `INSTALLATION.md`: requisitos, pasos de instalación y troubleshooting.
- `DEVELOPER.md`: arquitectura detallada, hooks y pautas de extensión.
- `FLOW-STATES-SYSTEM.md`: detalles del sistema de estados y migraciones.

Este resumen sirve como referencia rápida para entender las piezas clave del flujo actual y cómo interactúan los componentes del plugin.
