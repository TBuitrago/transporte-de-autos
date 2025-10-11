# Super Dispatch Pricing Insights Plugin

## Descripción
Plugin de WordPress que permite a los clientes cotizar en tiempo real el precio de envío de vehículos terrestres en EE.UU. usando la API de Pricing Insights de Super Dispatch.

## Características
- ✅ Formulario personalizado con búsqueda de ciudades
- ✅ Pantalla adicional previa al pago para datos de recogida/entrega
- ✅ Integración con API de Super Dispatch
- ✅ Sistema de precios con ganancia de empresa y ajustes por confianza
- ✅ Soporte marítimo PR con puertos USA automáticos
- ✅ Sesiones de cotización consolidadas (registro único por proceso)
- ✅ Envío a Zapier/CRM solo al finalizar (o manual desde admin)
- ✅ Historial con estado Zapier y acciones en lote (enviar/eliminar)
- ✅ Interfaz responsive y moderna
- ✅ Validación en tiempo real
- 🚫 Desglose detallado del precio disponible solo vía historial administrativo (no se muestra al usuario final)

## Instalación
1. Subir el plugin a `/wp-content/plugins/super-dispatch-pricing-insights/`
2. Activar el plugin desde el panel de administración
3. Configurar la API key en **Settings → Super Dispatch Pricing**
4. Importar datos de ciudades desde **Settings → Super Dispatch Pricing → Cities**

## Uso
### Shortcode
```
[super_dispatch_pricing_form]
```

### Campos del Formulario
- **Ciudad de Origen**: Búsqueda con autocompletado
- **Ciudad de Destino**: Búsqueda con autocompletado
- **Tipo de Tráiler**: Abierto/Cerrado
- **Tipo de Vehículo**: Sedan, SUV, Van, etc.
- **Estado del Vehículo**: Operativo/No operativo
- **Marca del Vehículo**: Texto libre
- **Modelo del Vehículo**: Texto libre
- **Año del Vehículo**: Texto libre

### Pantallas del Flujo
- **Información de Contacto**: Nombre, correo, teléfono (inicia sesión de cotización)
- **Cotizador**: Origen, destino, vehículo, obtiene precio
- **Información adicional**: Nombre de quien entrega/recibe, direcciones, ciudades/ZIP (ciudades/ZIP en solo lectura), tipo de recogida

### Integración con WooCommerce
- Botón “Continuar” abre pantalla adicional y, tras guardar, lleva al checkout nativo de WooCommerce.

### Envío a Zapier / CRM
- Automático: al completar el pedido (hook `woocommerce_order_status_completed`).
- Manual: desde WP Admin → SDPI → “Enviar a Zapier”, ingresando `Session ID`.
- Lote: WP Admin → SDPI → Historial, seleccionar varias filas y “Enviar seleccionados a Zapier”.

## Sistema de Precios
El plugin aplica la siguiente lógica de precios:
1. **Precio base**: Obtenido de la API de Super Dispatch
2. **Ganancia fija**: +$200 USD
3. **Ajuste por confianza**:
   - 60-100%: Suma el porcentaje restante para llegar a 100%
   - 30-59%: Suma $150 USD fijos
   - 0-29%: Suma $200 USD fijos
4. **Recargos marítimos**:
   - Vehículo eléctrico: +$600 USD
   - Vehículo inoperable (solo transporte marítimo): +$500 USD (visible en historial interno)

## Base de Datos
- `wp_sdpi_cities`: datos de ciudades/ZIP.
- `wp_sdpi_history`: historial de cotizaciones, incluye `zapier_status` y `zapier_last_sent_at`.
- `wp_sdpi_quote_sessions`: sesiones consolidadas con JSON incremental por `session_id`.

## Archivos Principales
- `super-dispatch-pricing-insights.php` - Archivo principal del plugin
- `includes/class-sdpi-settings.php` - Configuración del plugin
- `includes/class-sdpi-api.php` - Comunicación con la API
- `includes/class-sdpi-form.php` - Formulario personalizado
- `includes/class-sdpi-cities.php` - Gestión de ciudades
- `includes/class-sdpi-history.php` - Historial, estadísticas, envío/acciones en lote
- `includes/class-sdpi-session.php` - Gestión de sesiones de cotización
- `assets/form-script.js` - JavaScript del formulario
- `assets/form-styles.css` - Estilos del formulario

## Requisitos
- WordPress 5.0+
- PHP 7.4+
- API key de Super Dispatch

## Soporte y Contacto
Para soporte técnico, contactar a:

**Tomas Buitrago**  
Empresa: TBA Digitals  
Email: [sdpi@tbadigitals.com](mailto:sdpi@tbadigitals.com)
