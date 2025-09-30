# Sistema de Estados del Flujo de Cotizaciones

## Descripción General

El sistema de estados del flujo permite rastrear el progreso de cada usuario a través del proceso de cotización, desde el momento en que ingresa sus datos de contacto hasta la finalización del pago.

## Estados del Flujo

### 1. **Inicial** (`inicial`)
- **Cuándo se activa**: Cuando el usuario completa sus datos de contacto (nombre, correo y teléfono) y presiona "Continuar"
- **Datos capturados**: Información básica del cliente
- **Color**: Azul (#0073aa)

### 2. **Cotizador** (`cotizador`)
- **Cuándo se activa**: Cuando el usuario solicita una cotización a la API
- **Datos capturados**: 
  - Información del vehículo
  - Rutas de origen y destino
  - Respuesta de la API
  - Precio final calculado
- **Color**: Verde (#00a32a)

### 3. **Checkout** (`checkout`)
- **Cuándo se activa**: Cuando el usuario inicia el proceso de pago
- **Datos capturados**: Información de pago y checkout
- **Color**: Amarillo (#dba617)

### 4. **Completado** (`completado`)
- **Cuándo se activa**: Cuando el pago se completa exitosamente
- **Datos capturados**: Confirmación de pago y datos finales
- **Color**: Verde (#00a32a)

## Implementación Técnica

### Base de Datos

La tabla `wp_sdpi_history` incluye los siguientes campos nuevos:

```sql
session_id varchar(64) NOT NULL,
flow_status varchar(20) NOT NULL DEFAULT 'inicial',
status_updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### Clases Modificadas

#### SDPI_History
- `create_initial_record()`: Crea registro inicial con estado "inicial"
- `update_to_cotizador()`: Actualiza a estado "cotizador" con datos de cotización
- `update_to_checkout()`: Actualiza a estado "checkout"
- `update_to_completado()`: Actualiza a estado "completado"
- `get_by_session_id()`: Obtiene registro por session_id

#### SDPI_Form
- `ajax_save_client_info()`: Crea registro inicial
- `ajax_get_quote()`: Actualiza a estado "cotizador"
- `ajax_initiate_payment()`: Actualiza a estado "checkout"
- `handle_payment_complete()`: Actualiza a estado "completado"

#### SDPI_Session
- Mantiene la funcionalidad existente
- Integrado con el sistema de estados

### Interfaz de Administración

#### Filtros
- Filtro por estado del flujo
- Filtro por fechas
- Filtro por tipo de transporte

#### Tabla de Historial
- Nueva columna "Estado" con badges de colores
- Timestamp de última actualización de estado
- Información visual del progreso del usuario

#### Exportación CSV
- Incluye estado del flujo
- Incluye session_id
- Incluye fecha de actualización de estado

## Flujo de Datos

```
Usuario ingresa datos → Estado: INICIAL
         ↓
Usuario solicita cotización → Estado: COTIZADOR
         ↓
Usuario inicia pago → Estado: CHECKOUT
         ↓
Pago completado → Estado: COMPLETADO
```

## Beneficios

1. **Trazabilidad completa**: Rastreo del progreso de cada usuario
2. **Análisis de conversión**: Identificar dónde se pierden usuarios
3. **Optimización del flujo**: Mejorar puntos de fricción
4. **Reportes detallados**: Estadísticas por estado
5. **Seguimiento de sesiones**: Identificador único por sesión

## Migración

Para instalaciones existentes, ejecutar el script de migración:

```bash
php migration-add-flow-status.php
```

Este script:
- Agrega las columnas necesarias
- Actualiza registros existentes con estados apropiados
- Crea índices para optimización

## Monitoreo

### Métricas Clave
- Tasa de conversión por estado
- Tiempo promedio en cada estado
- Puntos de abandono más comunes
- Efectividad del flujo completo

### Alertas
- Usuarios atascados en un estado por mucho tiempo
- Errores en transiciones de estado
- Fallos en la integración con WooCommerce

## Mantenimiento

### Limpieza de Datos
- Eliminar registros antiguos en estado "inicial"
- Archivar registros completados
- Optimizar consultas por estado

### Monitoreo de Rendimiento
- Índices en campos de estado
- Consultas optimizadas para filtros
- Cache de estadísticas frecuentes
