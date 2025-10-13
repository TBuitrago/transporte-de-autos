# Guía de Instalación - Super Dispatch Pricing Insights

## 📋 Requisitos del Sistema

### Requisitos Mínimos
- **WordPress**: 5.0 o superior
- **PHP**: 7.4 o superior
- **MySQL**: 5.7 o superior
- **Memoria PHP**: 128MB mínimo
- **Tiempo de ejecución**: 30 segundos mínimo

### Requisitos Recomendados
- **WordPress**: 6.0 o superior
- **PHP**: 8.0 o superior
- **MySQL**: 8.0 o superior
- **Memoria PHP**: 256MB
- **Tiempo de ejecución**: 60 segundos

## 🚀 Instalación Paso a Paso

### Paso 1: Descargar el Plugin
1. Descargar el archivo ZIP del plugin
2. Extraer el contenido en una carpeta temporal

### Paso 2: Subir al Servidor
```bash
# Opción 1: Via FTP/SFTP
# Subir la carpeta 'super-dispatch-pricing-insights' a:
/wp-content/plugins/

# Opción 2: Via WP-CLI
wp plugin install /path/to/super-dispatch-pricing-insights.zip --activate
```

### Paso 3: Activar el Plugin
1. Ir al panel de administración de WordPress
2. Navegar a `Plugins > Plugins Instalados`
3. Buscar "Super Dispatch Pricing Insights"
4. Hacer clic en "Activar"

### Paso 4: Verificar Activación
- El plugin debería aparecer en la lista de plugins activos
- Debería aparecer un menú "Super Dispatch Pricing" en el admin

## ⚙️ Configuración Inicial

### Paso 1: Configurar API Key
1. Ir a `Configuración > Super Dispatch Pricing`
2. Ingresar la API key proporcionada por Super Dispatch
3. Hacer clic en "Guardar Configuración"
4. Probar la conexión con el botón "Probar Conexión"

### Paso 2: Configurar Base de Datos de Ciudades

#### Opción A: Importación Automática (Recomendada)
1. Descargar archivo de ciudades de [simplemaps.com](https://simplemaps.com/data/us-cities)
2. Ir a `Configuración > Super Dispatch Pricing`
3. Usar la herramienta de importación (si está disponible)

#### Opción B: Importación Manual
1. **Crear la tabla de ciudades:**
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

2. **Importar datos de ciudades:**
```sql
-- Ejemplo de datos (solo campos necesarios)
INSERT INTO wp_sdpi_cities (city, state_id, zips) VALUES
('Miami', 'FL', '33101 33102 33103 33104 33105'),
('New York', 'NY', '10001 10002 10003 10004 10005'),
('Los Angeles', 'CA', '90001 90002 90003 90004 90005');
```

3. **Agregar San Juan, PR:**
```sql
INSERT INTO wp_sdpi_cities (city, state_id, zips) VALUES
('San Juan', 'PR', '00901 00902 00903 00904 00905 00906 00907 00908 00909 00910 00911 00912 00913 00914 00915 00916 00917 00918 00919 00920 00921 00922 00923 00924 00925 00926 00927 00928 00929 00930 00931 00932 00933 00934 00935 00936 00937 00938 00939 00940 00941 00942 00943 00944 00945 00946 00947 00948 00949 00950 00951 00952 00953 00954 00955 00956 00957 00958 00959 00960 00961 00962 00963 00964 00965 00966 00967 00968 00969 00970 00971 00972 00973 00974 00975 00976 00977 00978 00979 00980 00981 00982 00983 00984 00985 00986 00987 00988 00989 00990 00991 00992 00993 00994 00995 00996 00997 00998 00999');
```

### Paso 3: Configurar Pagos (opcional)
1. Verifica que el sitio use **HTTPS** (Accept.js sólo funciona en conexiones seguras).
2. Obtén en Authorize.net los valores de **API Login ID**, **Transaction Key** y **Public Client Key**.
3. En `Configuración > Super Dispatch Pricing` selecciona el entorno (Sandbox/Producción) y pega las credenciales.
4. Define las URLs de redirección de **Éxito** y **Error**.
5. Utiliza el botón "Probar conexión" y realiza una transacción de prueba en sandbox antes de pasar a producción.

### Paso 4: Configurar Permisos
1. Verificar que el plugin tenga permisos de escritura
2. Configurar permisos de archivos si es necesario:
```bash
chmod 755 /wp-content/plugins/super-dispatch-pricing-insights/
chmod 644 /wp-content/plugins/super-dispatch-pricing-insights/*.php
```

## 🔧 Configuración Avanzada

### Configuración de PHP
```ini
; En php.ini o .htaccess
memory_limit = 256M
max_execution_time = 60
max_input_vars = 3000
post_max_size = 32M
upload_max_filesize = 32M
```

### Configuración de WordPress
```php
// En wp-config.php
define('WP_DEBUG', false); // En producción
define('WP_DEBUG_LOG', true); // Para debugging
define('WP_DEBUG_DISPLAY', false); // En producción

// Aumentar límite de memoria si es necesario
ini_set('memory_limit', '256M');
```

### Configuración de Base de Datos
```sql
-- Optimizar tabla de ciudades
ALTER TABLE wp_sdpi_cities ADD INDEX idx_city_state (city, state_id);
ALTER TABLE wp_sdpi_cities ADD INDEX idx_zips (zips(100));

-- Verificar índices
SHOW INDEX FROM wp_sdpi_cities;
```

## 🧪 Verificación de Instalación

### Test 1: Verificar Plugin Activo
1. Ir a `Plugins > Plugins Instalados`
2. Confirmar que "Super Dispatch Pricing Insights" esté activo
3. Verificar que no haya errores de activación

### Test 2: Verificar Configuración
1. Ir a `Configuración > Super Dispatch Pricing`
2. Verificar que la API key esté configurada
3. Probar la conexión con la API

### Test 3: Verificar Base de Datos
```sql
-- Verificar que la tabla existe
SHOW TABLES LIKE 'wp_sdpi_cities';

-- Verificar que tiene datos
SELECT COUNT(*) FROM wp_sdpi_cities;

-- Verificar San Juan, PR
SELECT * FROM wp_sdpi_cities WHERE city = 'San Juan' AND state_id = 'PR';
```

### Test 4: Verificar Funcionalidad
1. Crear una página de prueba
2. Agregar el shortcode `[sdpi_pricing_form]`
3. Probar el formulario con datos de prueba
4. Verificar que se genere la cotización

## 🐛 Solución de Problemas

### Error: "Plugin no se activa"
**Causa**: Conflicto de versiones o errores de PHP
**Solución**:
1. Verificar logs de error de WordPress
2. Verificar versión de PHP (mínimo 7.4)
3. Desactivar otros plugins temporalmente
4. Verificar permisos de archivos

### Error: "API Key inválida"
**Causa**: API key incorrecta o expirada
**Solución**:
1. Verificar la API key con Super Dispatch
2. Regenerar la API key si es necesario
3. Verificar conectividad de red

### Error: "No se encuentran ciudades"
**Causa**: Base de datos de ciudades no configurada
**Solución**:
1. Verificar que la tabla `wp_sdpi_cities` existe
2. Importar datos de ciudades
3. Verificar permisos de base de datos

### Error: "Formulario no se envía"
**Causa**: JavaScript deshabilitado o conflicto de scripts
**Solución**:
1. Verificar que JavaScript esté habilitado
2. Verificar consola del navegador para errores
3. Desactivar plugins de optimización temporalmente

### Error: "Accept.js requiere HTTPS"
**Causa**: El formulario de pago sólo puede tokenizar datos cuando se carga bajo HTTPS
**Solución**:
1. Asegurar un certificado SSL válido en el dominio
2. Forzar HTTPS en WordPress (`Settings > General` y `wp-config.php`)
3. Repetir la prueba del pago después de limpiar cachés del sitio/CDN

### Error: "Error 400 de API"
**Causa**: Datos inválidos enviados a la API
**Solución**:
1. Verificar que no se envíen ZIPs de Puerto Rico a la API
2. Verificar que los campos requeridos estén completos
3. Revisar logs de debug del plugin

## 📊 Monitoreo Post-Instalación

### Métricas a Monitorear
- Tiempo de respuesta de la API
- Tasa de éxito de cotizaciones
- Errores de validación
- Uso de memoria del servidor

### Logs a Revisar
```bash
# Logs de WordPress
tail -f /wp-content/debug.log | grep "SDPI"

# Logs del servidor web
tail -f /var/log/apache2/error.log
# o
tail -f /var/log/nginx/error.log
```

### Herramientas de Monitoreo
- **WordPress Health Check**: Verificar estado general del sitio
- **Query Monitor**: Monitorear consultas de base de datos
- **Debug Bar**: Ver información de debug en tiempo real

## 🔄 Actualizaciones

### Actualización Manual
1. Hacer backup de la base de datos
2. Desactivar el plugin
3. Reemplazar archivos del plugin
4. Reactivar el plugin
5. Verificar funcionalidad

### Actualización Automática
```bash
# Via WP-CLI
wp plugin update super-dispatch-pricing-insights

# Via WordPress Admin
# Ir a Plugins > Actualizaciones
```

### Verificación Post-Actualización
1. Probar formulario de cotización
2. Verificar configuración
3. Revisar logs de error
4. Probar funcionalidad marítima

## 🗑️ Desinstalación

### Desinstalación Completa
1. **Desactivar el plugin**
2. **Eliminar el plugin** desde el admin
3. **Eliminar datos de configuración** (opcional):
```sql
DELETE FROM wp_options WHERE option_name LIKE 'sdpi_%';
```
4. **Eliminar tabla de ciudades** (opcional):
```sql
DROP TABLE wp_sdpi_cities;
```

### Backup Antes de Desinstalar
```bash
# Backup de base de datos
mysqldump -u username -p database_name > backup.sql

# Backup de archivos
tar -czf plugin_backup.tar.gz /wp-content/plugins/super-dispatch-pricing-insights/
```

## 📞 Soporte

### Información de Debug
Al contactar soporte, incluir:
- Versión de WordPress
- Versión de PHP
- Versión del plugin
- Logs de error relevantes
- Descripción detallada del problema

### Contacto de Soporte
**Tomas Buitrago**
Empresa: TBA Digitals
Website: [https://tbadigitals.com](https://tbadigitals.com)
Email: [sdpi@tbadigitals.com](mailto:sdpi@tbadigitals.com)

### Recursos de Ayuda
- Documentación del plugin: `README.md`
- Documentación técnica: `DEVELOPER.md`
- Logs de debug: `/wp-content/debug.log`
- Foros de WordPress
- Documentación de Super Dispatch API

---

**Nota**: Esta guía asume un conocimiento básico de WordPress y administración de servidores. Para instalaciones complejas, consultar con un desarrollador experimentado.

