# Super Dispatch Pricing Insights Plugin

## Descripción General
Super Dispatch Pricing Insights (SDPI) es un plugin de WordPress que permite cotizar envíos de vehículos en tiempo real aprovechando la API de Super Dispatch. El formulario guía al cliente a través de un flujo de varias etapas, captura datos completos antes de revelar el precio y consolida todo el proceso en un historial administrativo listo para enviar a Zapier u otros sistemas CRM.

## Características Principales
### Cotizador Inteligente
- Formulario propio optimizado para transporte terrestre y marítimo.
- Búsqueda de ciudades y códigos postales con autocompletado y detección por prefijo.
- Guardado incremental en `wp_sdpi_quote_sessions` para retomar o auditar el proceso.
- Panel lateral con resumen dinámico de origen, destino, vehículo y precio estimado.

### Transporte Marítimo a Puerto Rico
- Detección automática de ZIP codes que inician con `009`.
- Selección inteligente de puertos (Eddystone, PA o Jacksonville, FL) según el estado continental.
- Tarifas marítimas preconfiguradas con opción a filtros (`sdpi_maritime_rates`).
- Recargos automáticos para vehículos eléctricos o inoperables en rutas marítimas.

### Pagos y Checkout
- Pagos directos con Authorize.net mediante Accept.js (sin dependencia de WooCommerce).
- Captura segura de tarjeta dentro del sitio y tokenización client-side.
- Pantalla de revisión previa al pago con resumen de datos y tarifa final.
- Estados del flujo (`inicial`, `cotizador`, `checkout`, `completado`) para medir conversiones.

### Gestión, Integraciones y Soporte Comercial
- Historial administrativo con filtros, acciones en lote y exportación CSV.
- Envío automático, manual o masivo a Zapier utilizando un webhook configurable.
- Indicadores de estado de Zapier y fecha del último intento por registro.
- Herramientas de debugging, logging selectivo y caché de respuestas de la API.

## Flujo de Cotización
1. **Captura inicial:** el cliente completa datos de origen, destino y vehículo.
2. **Cálculo interno:** SDPI consulta la API de Super Dispatch (o calcula tramo terrestre cuando hay transporte marítimo) y guarda la respuesta en la sesión.
3. **Captura de contacto:** se solicita nombre, teléfono y correo antes de revelar el precio.
4. **Revisión:** se muestra el resumen con tarifa final, desgloses y disponibilidad de pago.
5. **Checkout:** si Authorize.net está configurado y el sitio usa HTTPS, el cliente puede pagar sin abandonar la página.
6. **Finalización:** los datos se consolidan en el historial, se marca el estado correspondiente y se dispara el webhook de Zapier (si está activo).

## Uso y Shortcodes
Inserta el formulario en cualquier página o entrada con:

```
[super_dispatch_pricing_form]
```

El formulario carga sus assets solamente cuando detecta el shortcode, evitando afectar otras páginas del sitio.

## Integraciones Externas
- **Super Dispatch Pricing Insights API:** obtiene recomendaciones de precio y nivel de confianza.
- **Authorize.net Accept.js:** tokeniza la tarjeta y procesa cargos directos.
- **Zapier / CRM:** envía los datos consolidados vía webhook, incluyendo detalles de contacto, vehículo y tarifas marítimas.

## Configuración Esencial
1. **API de Super Dispatch:** agrega la API key en `Ajustes → Super Dispatch Pricing` y prueba la conexión.
2. **Base de datos de ciudades:** importa el dataset (automático o manual) para habilitar el autocompletado.
3. **Authorize.net:** define entorno (sandbox/producción), API Login ID, Transaction Key y Public Client Key.
4. **URLs de redirección:** configura páginas de éxito y error para el flujo de pago.
5. **Webhook de Zapier:** pega la URL del hook si deseas enviar las cotizaciones automáticamente.

Consulta `INSTALLATION.md` para instrucciones detalladas, migraciones y solución de problemas.

## Requisitos
- WordPress 5.0 o superior (recomendado 6.0+).
- PHP 7.4 o superior (recomendado 8.0+).
- Extensiones PHP: cURL, JSON y OpenSSL activadas.
- Cuenta activa de Super Dispatch con acceso a Pricing Insights API.
- Cuenta de Authorize.net con Accept.js habilitado (solo si se procesarán pagos en línea).

## Base de Datos y Persistencia
- `wp_sdpi_cities`: catálogo de ciudades y códigos postales para autocompletado.
- `wp_sdpi_quote_sessions`: sesión consolidada de cada proceso de cotización.
- `wp_sdpi_history`: historial para reportes, Zapier y seguimiento de estados del flujo.

## Archivos Clave
- `super-dispatch-pricing-insights.php` – bootstrap del plugin y hooks globales.
- `includes/class-sdpi-settings.php` – configuración, campos de Authorize.net y Zapier.
- `includes/class-sdpi-form.php` – renderizado, AJAX, pagos y envío a Zapier.
- `includes/class-sdpi-api.php` – comunicación con la API de Super Dispatch.
- `includes/class-sdpi-maritime.php` – utilidades y tarifas para transporte marítimo.
- `includes/class-sdpi-history.php` – tabla administrativa, estados del flujo y acciones masivas.
- `includes/class-sdpi-session.php` – persistencia incremental por `session_id`.
- `assets/form-script.js` y `assets/form-styles.css` – experiencia del front-end.

## Soporte y Contacto
**Tomas Buitrago**  
TBA Digitals  
Website: [https://tbadigitals.com](https://tbadigitals.com)  
Email: [sdpi@tbadigitals.com](mailto:sdpi@tbadigitals.com)

Para asistencia adicional o propuestas de personalización, visita [https://tbadigitals.com](https://tbadigitals.com).
