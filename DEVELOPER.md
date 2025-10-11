# Super Dispatch Pricing Insights - Documentación Técnica

## Desarrollador
**Tomas Buitrago**  
Empresa: TBA Digitals  
Contacto: [sdpi@tbadigitals.com](mailto:sdpi@tbadigitals.com)

---

## 🏗️ Arquitectura del Sistema

### Flujo de Datos
```
Usuario → Formulario → AJAX → SDPI_Form → SDPI_API → Super Dispatch API
                ↓
        SDPI_Maritime (si aplica) → Cálculo Final → Respuesta
```

### Diagrama de Clases
```
SDPI_Settings
    ├── Configuración del plugin
    ├── Interfaz de administración
    └── Validación de API key

SDPI_API
    ├── Comunicación con Super Dispatch
    ├── Manejo de errores
    └── Caché de respuestas

SDPI_Form
    ├── Renderizado del formulario
    ├── Procesamiento AJAX
    ├── Cálculo de precios
    └── Gestión de sesión consolidada (SDPI_Session)

SDPI_Cities
    ├── Gestión de base de datos
    ├── Búsqueda de ciudades
    └── Autocompletado

SDPI_Maritime
SDPI_Session
    ├── Tabla wp_sdpi_quote_sessions
    ├── start_session()/update_data()/set_status()
    └── get(session_id)

Relaciones clave
- SDPI_Form → SDPI_Session: persiste datos parciales por `session_id`
- SDPI_History: muestra estado Zapier, envíos en lote y borrado en lote
- Hook WC `order_status_completed`: dispara envío consolidado a Zapier
    ├── Detección de transporte marítimo
    ├── Selección de puertos
    └── Cálculo de tarifas
```

## 🔧 Clases Detalladas

### SDPI_Settings

#### Propósito
Gestiona la configuración del plugin y proporciona la interfaz de administración.

#### Métodos Principales
```php
public function __construct()
    // Inicializa hooks de administración

public function add_admin_menu()
    // Agrega menú de administración

public function settings_page()
    // Renderiza página de configuración

public function handle_settings_save()
    // Procesa guardado de configuración

public function test_api_connection()
    // Prueba conexión con API (AJAX)
```

#### Hooks Utilizados
- `admin_menu` - Agregar menú de administración
- `admin_init` - Inicializar configuración
- `wp_ajax_sdpi_test_api` - AJAX para probar API

### SDPI_API

#### Propósito
Maneja toda la comunicación con la API de Super Dispatch.

#### Métodos Principales
```php
public function get_pricing_quote($form_data)
    // Obtiene cotización de la API
    // Parámetros: array con datos del formulario
    // Retorna: array con respuesta o WP_Error

private function make_api_request($endpoint, $data)
    // Realiza petición HTTP a la API
    // Maneja autenticación y headers

private function parse_api_response($response)
    // Parsea respuesta de la API
    // Busca precio y confianza en diferentes campos
```

#### Estructura de Respuesta
```php
[
    'recommended_price' => float,
    'confidence' => float,
    'price_per_mile' => float
]
```

### SDPI_Form

#### Propósito
Renderiza el formulario de cotización y procesa los envíos AJAX.

#### Métodos Principales
```php
public function render_form()
    // Renderiza el formulario HTML
    // Incluye JavaScript inline

public function ajax_get_quote()
    // Procesa envío AJAX del formulario
    // Valida datos y llama a la API

private function calculate_final_price($api_response, $pickup_zip, $delivery_zip)
    // Calcula precio final con ganancia y ajustes
    // Integra lógica marítima si aplica

private function calculate_maritime_only_quote($pickup_zip, $delivery_zip, ...)
    // Calcula cotización solo marítima (San Juan → San Juan)
```

#### Estructura del Formulario
```html
<form id="sdpi-pricing-form">
    <!-- Información de Envío -->
    <input name="pickup_zip" type="hidden">
    <input name="delivery_zip" type="hidden">
    <select name="trailer_type">
    
    <!-- Información del Vehículo -->
    <select name="vehicle_type">
    <input name="vehicle_inoperable" type="checkbox">
    <input name="vehicle_make">
    <input name="vehicle_model">
    <input name="vehicle_year">
</form>
```

### SDPI_Cities

#### Propósito
Gestiona la base de datos de ciudades y proporciona funcionalidad de búsqueda.

#### Métodos Principales
```php
public static function create_table()
    // Crea tabla wp_sdpi_cities
    // Se ejecuta en activación del plugin

public function ajax_search_cities()
    // Maneja búsqueda AJAX de ciudades
    // Valida nonce y parámetros

public static function search_cities($query, $limit = 10)
    // Busca ciudades en la base de datos
    // Detecta si es búsqueda por ciudad o ZIP
```

#### Estructura de la Tabla
```sql
CREATE TABLE wp_sdpi_cities (
    id int(11) NOT NULL AUTO_INCREMENT,
    city varchar(100) NOT NULL,
    state_id varchar(2) NOT NULL,
    zips text NOT NULL,
    PRIMARY KEY (id),
    KEY city (city),
    KEY state_id (state_id)
);
```

### SDPI_Maritime

#### Propósito
Maneja toda la lógica relacionada con transporte marítimo a Puerto Rico.

#### Métodos Principales
```php
public static function is_san_juan_zip($zip)
    // Detecta si un ZIP es de San Juan, PR
    // Cualquier ZIP que empiece con "009"

public static function involves_maritime($pickup_zip, $delivery_zip)
    // Determina si la ruta involucra transporte marítimo

public static function get_us_port($continental_zip)
    // Selecciona puerto USA basado en ZIP continental
    // Noreste: Eddystone, PA
    // Sureste: Jacksonville, FL

public static function get_maritime_rate($from_zip, $to_zip)
    // Calcula tarifa marítima basada en ruta

public static function calculate_maritime_cost($pickup_zip, $delivery_zip, $terrestrial_cost, $confidence)
    // Calcula costo total con transporte marítimo
```

#### Constantes de Tarifas
```php
const MARITIME_RATES = [
    'san_juan_to_jacksonville' => 895.00,
    'san_juan_to_penn_terminals' => 1350.00,
    'jacksonville_to_san_juan' => 1150.00,
    'penn_terminals_to_san_juan' => 1675.00
];
```

## 🔄 Flujo de Procesamiento

### 1. Captura de Contacto (inicia sesión)
```php
// SDPI_Form::ajax_save_client_info()
// 1) Guarda datos en $_SESSION
// 2) Inicia SDPI_Session y devuelve session_id
```

### 2. Envío del Cotizador
```javascript
// Frontend (form-script.js)
$('#sdpi-pricing-form').on('submit', function(e) {
    e.preventDefault();
    
    // Validar campos requeridos
    // Enviar AJAX a wp-admin/admin-ajax.php
    // Mostrar loading
    // Procesar respuesta
});

// Nota 2025-10-11:
// El desglose detallado ya no se muestra en el front-end; solo permanece disponible en el historial administrativo.
```

### 3. Procesamiento Backend
```php
// SDPI_Form::ajax_get_quote()
1. Verificar nonce
2. Sanitizar datos del formulario
3. Detectar si involucra transporte marítimo
4. Si es marítimo:
   - Determinar puerto USA
   - Llamar API con ZIPs continentales
   - Calcular tarifa marítima
   - Aplicar lógica de precios
5. Si es terrestre:
   - Llamar API directamente
   - Aplicar lógica de precios
6. Persistir en SDPI_Session → `quote.{pickup,delivery,vehicle,api,final}`
7. Retornar respuesta JSON
```

### 4. Cálculo de Precios
```php
// Lógica de precios
$base_price = $api_response['recommended_price'];
$confidence = $api_response['confidence'];

// Ganancia fija
$company_profit = 200.00;

// Ajuste por confianza
if ($confidence >= 60 && $confidence <= 100) {
    $remaining = 100 - $confidence;
    $confidence_adjustment = $base_price * ($remaining / 100);
} elseif ($confidence >= 30 && $confidence <= 59) {
    $confidence_adjustment = 150.00;
} elseif ($confidence >= 0 && $confidence <= 29) {
    $confidence_adjustment = 200.00;
}

$final_price = $base_price + $company_profit + $confidence_adjustment;
```

## 🗄️ Base de Datos

### Tabla wp_sdpi_cities
### Tabla wp_sdpi_quote_sessions
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

### Tabla wp_sdpi_history (extensiones)
Campos añadidos:
- `zapier_status` (pending|sent|error)
- `zapier_last_sent_at` (datetime)
```sql
-- Estructura
CREATE TABLE wp_sdpi_cities (
    id int(11) NOT NULL AUTO_INCREMENT,
    city varchar(100) NOT NULL,
    state_id varchar(2) NOT NULL,
    zips text NOT NULL,
    PRIMARY KEY (id),
    KEY city (city),
    KEY state_id (state_id)
);

-- Datos de ejemplo
INSERT INTO wp_sdpi_cities VALUES
(1, 'Miami', 'FL', '33101 33102 33103'),
(2, 'San Juan', 'PR', '00901 00902 00903'),
(3, 'New York', 'NY', '10001 10002 10003');
```

### Consultas Optimizadas
```sql
-- Búsqueda por ciudad
SELECT * FROM wp_sdpi_cities 
WHERE city LIKE '%miami%' 
LIMIT 10;

-- Búsqueda por ZIP
SELECT * FROM wp_sdpi_cities 
WHERE zips LIKE '%33101%' 
LIMIT 10;
```

## 🔌 Hooks y Filtros

### Acciones Disponibles
// Envío consolidado al completar pedido
add_action('woocommerce_order_status_completed', ...);
```php
// Antes de renderizar formulario
do_action('sdpi_before_form_render');

// Después de renderizar formulario
do_action('sdpi_after_form_render');

// Antes de llamar a la API
do_action('sdpi_before_api_call', $form_data);

// Después de llamar a la API
do_action('sdpi_after_api_call', $api_response);
```

### Filtros Disponibles
```php
// Modificar datos del formulario
$form_data = apply_filters('sdpi_form_data', $form_data);

// Modificar respuesta de la API
$api_response = apply_filters('sdpi_api_response', $api_response);

// Modificar precio final
$final_price = apply_filters('sdpi_final_price', $final_price, $form_data);

// Modificar tarifas marítimas
$maritime_rates = apply_filters('sdpi_maritime_rates', $maritime_rates);
```

### Ejemplos de Uso
```php
// Cambiar tarifas marítimas
add_filter('sdpi_maritime_rates', function($rates) {
    $rates['san_juan_to_jacksonville'] = 950.00;
    return $rates;
});

// Agregar logging personalizado
add_action('sdpi_after_api_call', function($response) {
    error_log('API Response: ' . json_encode($response));
});

// Modificar precio final
add_filter('sdpi_final_price', function($price, $form_data) {
    // Aplicar descuento del 10% para clientes VIP
    if (is_vip_customer()) {
        return $price * 0.9;
    }
    return $price;
}, 10, 2);
```

## 🎨 Personalización

### CSS Personalizado
```css
/* Personalizar formulario */
.sdpi-pricing-form {
    background: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
}

/* Personalizar desglose marítimo */
.sdpi-maritime-breakdown {
    border: 2px solid #007cba;
    background: #f8f9fa;
}

/* Personalizar elementos de precio */
.sdpi-price-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
}
```

### JavaScript Personalizado
```javascript
// Interceptar envío del formulario
jQuery(document).on('submit', '#sdpi-pricing-form', function(e) {
    // Validación personalizada
    if (!validateCustomFields()) {
        e.preventDefault();
        return false;
    }
});

// Modificar respuesta antes de mostrar
jQuery(document).on('sdpi:quote:success', function(event, response) {
    // Agregar información adicional
    response.data.custom_message = 'Gracias por usar nuestro servicio';
});
```

## 🐛 Debugging

### Habilitar Logs
```php
// En wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Logs del Plugin
```php
// Buscar en /wp-content/debug.log
error_log("SDPI DEBUG - Mensaje de debug");
error_log("SDPI MARITIME DEBUG - Debug marítimo");
```

### Información de Debug
- Detección de transporte marítimo
- Selección de puertos USA
- Datos enviados a la API
- Respuesta de la API
- Cálculos de precios
- Errores de validación

## 🚀 Optimización

### Caché
```php
// Caché de respuestas de API
$cache_key = 'sdpi_quote_' . md5(serialize($form_data));
$cached_response = get_transient($cache_key);

if ($cached_response === false) {
    $api_response = $this->api->get_pricing_quote($form_data);
    set_transient($cache_key, $api_response, 300); // 5 minutos
}
```

### Consultas SQL Optimizadas
```php
// Usar índices
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sdpi_cities 
     WHERE city LIKE %s 
     ORDER BY city 
     LIMIT %d",
    '%' . $wpdb->esc_like($query) . '%',
    $limit
));
```

### Carga Condicional
```php
// Solo cargar scripts en páginas que los necesiten
if (is_page('cotizacion') || has_shortcode(get_post()->post_content, 'sdpi_pricing_form')) {
    wp_enqueue_script('sdpi-form-script');
}
```

## 🔒 Seguridad

### Validación de Datos
```php
// Sanitizar inputs
$pickup_zip = sanitize_text_field($_POST['pickup_zip']);
$delivery_zip = sanitize_text_field($_POST['delivery_zip']);

// Validar nonce
check_ajax_referer('sdpi_nonce', 'nonce');

// Validar permisos
if (!current_user_can('manage_options')) {
    wp_die('No tienes permisos para esta acción');
}
```

### Escape de Outputs
```php
// Escapar HTML
echo esc_html($user_input);

// Escapar atributos
echo esc_attr($attribute_value);

// Escapar URLs
echo esc_url($url);
```

## 📊 Monitoreo

### Métricas Importantes
- Tiempo de respuesta de la API
- Tasa de éxito de cotizaciones
- Uso de transporte marítimo
- Errores de validación

### Logs de Monitoreo
```php
// Log de métricas
error_log("SDPI METRICS - API Response Time: " . $response_time . "ms");
error_log("SDPI METRICS - Quote Success Rate: " . $success_rate . "%");
```

## 🧪 Testing

### Tests Unitarios
```php
// Ejemplo de test
class Test_SDPI_Maritime extends WP_UnitTestCase {
    public function test_is_san_juan_zip() {
        $this->assertTrue(SDPI_Maritime::is_san_juan_zip('00901'));
        $this->assertTrue(SDPI_Maritime::is_san_juan_zip('00918'));
        $this->assertFalse(SDPI_Maritime::is_san_juan_zip('78701'));
    }
}
```

### Tests de Integración
```php
// Test de flujo completo
public function test_maritime_quote_flow() {
    $form_data = [
        'pickup_zip' => '00901',
        'delivery_zip' => '78701',
        'trailer_type' => 'open',
        'vehicles' => [/* ... */]
    ];
    
    $response = $this->form->ajax_get_quote();
    $this->assertTrue($response['success']);
    $this->assertArrayHasKey('maritime_involved', $response['data']);
}
```

---

**Nota**: Esta documentación está diseñada para desarrolladores que necesiten entender, modificar o extender el plugin. Para usuarios finales, consultar el README.md principal.


