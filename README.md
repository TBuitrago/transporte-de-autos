# Super Dispatch Pricing Insights Plugin

## Descripción
Plugin de WordPress que permite a los clientes cotizar en tiempo real el precio de envío de vehículos terrestres en EE.UU. usando la API de Pricing Insights de Super Dispatch.

## Características
- ✅ Formulario personalizado con búsqueda de ciudades
- ✅ Integración con API de Super Dispatch
- ✅ Sistema de precios con ganancia de empresa y ajustes por confianza
- ✅ Búsqueda de ciudades con autocompletado
- ✅ Interfaz responsive y moderna
- ✅ Validación en tiempo real

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

## Sistema de Precios
El plugin aplica la siguiente lógica de precios:
1. **Precio base**: Obtenido de la API de Super Dispatch
2. **Ganancia fija**: +$200 USD
3. **Ajuste por confianza**:
   - 60-100%: Suma el porcentaje restante para llegar a 100%
   - 30-59%: Suma $150 USD fijos
   - 0-29%: Suma $200 USD fijos

## Base de Datos
El plugin crea automáticamente la tabla `wp_sdpi_cities` para almacenar datos de ciudades de EE.UU.

## Archivos Principales
- `super-dispatch-pricing-insights.php` - Archivo principal del plugin
- `includes/class-sdpi-settings.php` - Configuración del plugin
- `includes/class-sdpi-api.php` - Comunicación con la API
- `includes/class-sdpi-form.php` - Formulario personalizado
- `includes/class-sdpi-cities.php` - Gestión de ciudades
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
