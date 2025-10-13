# Changelog - Super Dispatch Pricing Insights

## [1.3.1] - 2025-10-11

### Cambios
- Se retiró el bloque de desglose detallado del panel frontal de la cotización por políticas de confidencialidad; el detalle completo queda restringido al historial administrativo.

### Documentación
- README y guías internas actualizadas para reflejar que el desglose sólo está disponible en backend.

## [1.3.0] - 2025-09-29

### ✨ Nuevas Características
- **Pantalla adicional previa al checkout**: Captura de datos de recogida/entrega con campos de ciudad/ZIP en solo lectura.
- **Sesiones de cotización consolidadas**: Registro único por `session_id` que se enriquece a lo largo del flujo.
- **Envío a Zapier al finalizar**: Integración con WooCommerce (`order_status_completed`) para enviar solo una vez finalizado.
- **Envío manual y en lote**: Nuevo submenú “Enviar a Zapier”, y casillas/botón en Historial para enviar múltiples registros.
- **Estado Zapier en historial**: Columna con “Pendiente/Enviado/Error” y fecha del último envío.

### 🔧 Mejoras
- Persistencia incremental de datos (contacto, cotizador, adicionales) en `wp_sdpi_quote_sessions`.
- Marcado de estado Zapier tras envíos automáticos o manuales.

### 🐛 Correcciones
- Registro de handler AJAX faltante para borrado masivo en historial.

---

## [1.2.0] - 2025-01-10

### ✨ Nuevas Características
- **Transporte Marítimo a Puerto Rico**: Soporte completo para envíos a San Juan, PR
- **Selección Automática de Puertos**: Sistema inteligente que selecciona el puerto USA más cercano
- **Tarifas Marítimas Fijas**: Tarifas predefinidas para rutas marítimas
- **Cálculo Híbrido**: Integración de costos terrestres + marítimos
- **Desglose Detallado**: Visualización completa de todos los costos
- **Sistema de Historial**: Dashboard completo de administración
- **Registro Automático**: Todas las cotizaciones se guardan automáticamente
- **Estadísticas Avanzadas**: Métricas de negocio y análisis de datos
- **Modal de Detalles**: Vista completa de cada cotización
- **Exportación CSV**: Descarga de datos para análisis externo

### 🔧 Mejoras
- **Detección Inteligente**: Cualquier ZIP que empiece con "009" se detecta como San Juan, PR
- **Puertos Optimizados**: 
  - Noreste: Eddystone, PA (19022)
  - Sureste: Jacksonville, FL (32226)
- **Ajuste por Confianza en Marítimo**: Aplicación de lógica de confianza al tramo terrestre
- **Interfaz Mejorada**: Desglose visual mejorado para transporte marítimo

### 🐛 Correcciones
- **Error 400 de API**: Solucionado problema con ZIPs de Puerto Rico
- **Búsqueda de Ciudades**: Mejorada detección de códigos postales de San Juan
- **Cálculo de Precios**: Corregida aplicación de ganancia solo al tramo terrestre

### 📊 Datos
- **Base de Datos**: Actualizada con todos los ZIPs de San Juan (00901-00999)
- **Tarifas Marítimas**:
  - San Juan → Jacksonville: $895.00
  - San Juan → Eddystone: $1,350.00
  - Jacksonville → San Juan: $1,150.00
  - Eddystone → San Juan: $1,675.00

### 🔒 Seguridad
- **Validación Mejorada**: Mejor validación de datos de entrada
- **Sanitización**: Sanitización completa de todos los inputs
- **Nonces**: Verificación de nonces en todas las operaciones AJAX

---

## [1.1.0] - 2024-12-15

### ✨ Nuevas Características
- **Búsqueda Inteligente de Ciudades**: Sistema de autocompletado con sugerencias
- **Búsqueda por ZIP**: Capacidad de buscar ciudades por código postal
- **Base de Datos de Ciudades**: Integración con datos de simplemaps.com
- **Interfaz Mejorada**: Diseño más moderno y responsive

### 🔧 Mejoras
- **Performance**: Optimización de consultas de base de datos
- **UX**: Mejor experiencia de usuario en búsqueda de ciudades
- **Validación**: Validación en tiempo real de campos del formulario

### 🐛 Correcciones
- **Compatibilidad**: Mejor compatibilidad con diferentes temas de WordPress
- **JavaScript**: Corregidos conflictos con otros plugins
- **CSS**: Mejorada consistencia visual

---

## [1.0.0] - 2024-11-01

### ✨ Características Iniciales
- **Integración con Super Dispatch API**: Conexión completa con la API de cotizaciones
- **Formulario de Cotización**: Formulario completo para solicitar cotizaciones
- **Cálculo de Precios**: Lógica de negocio para cálculo de precios finales
- **Ganancia de Empresa**: Aplicación automática de $200.00 USD de ganancia
- **Ajuste por Confianza**: Sistema de ajuste basado en nivel de confianza de la API

### 🔧 Funcionalidades
- **Tipos de Vehículo**: Soporte para sedan, SUV, van, pickup, etc.
- **Tipos de Tráiler**: Abierto y cerrado
- **Información del Vehículo**: Marca, modelo, año, operabilidad
- **Validación**: Validación completa de campos requeridos
- **AJAX**: Procesamiento asíncrono de formularios

### 📊 Lógica de Precios
- **Ganancia Fija**: $200.00 USD en todos los envíos
- **Ajuste por Confianza**:
  - 60-100%: Añade porcentaje restante para llegar a 100%
  - 30-59%: Añade $150.00 USD fijos
  - 0-29%: Añade $200.00 USD fijos

### 🎨 Interfaz
- **Diseño Responsive**: Compatible con dispositivos móviles
- **Estilos Personalizados**: CSS optimizado para el plugin
- **Formulario Intuitivo**: Interfaz fácil de usar
- **Mensajes de Error**: Mensajes claros y útiles

### 🔒 Seguridad
- **Sanitización**: Sanitización de todos los inputs
- **Validación**: Validación de datos del lado del servidor
- **Nonces**: Protección CSRF en formularios
- **Escape**: Escape de todos los outputs

---

## [0.9.0] - 2024-10-15 (Beta)

### 🧪 Versión Beta
- **Desarrollo Inicial**: Primera versión de desarrollo
- **Integración API**: Conexión básica con Super Dispatch
- **Formulario Básico**: Formulario simple de cotización
- **Testing**: Pruebas internas y feedback

### 🔧 Características Beta
- **API Integration**: Integración básica con la API
- **Form Validation**: Validación básica de formularios
- **Price Calculation**: Cálculo básico de precios
- **Admin Interface**: Interfaz básica de administración

---

## Notas de Versión

### Convenciones de Versionado
- **Major (X.0.0)**: Cambios incompatibles o nuevas características principales
- **Minor (X.Y.0)**: Nuevas características compatibles hacia atrás
- **Patch (X.Y.Z)**: Correcciones de bugs y mejoras menores

### Compatibilidad
- **WordPress**: 5.0+ (recomendado 6.0+)
- **PHP**: 7.4+ (recomendado 8.0+)
- **MySQL**: 5.7+ (recomendado 8.0+)

### Migración
- **1.1.0 → 1.2.0**: Migración automática, sin cambios requeridos
- **1.0.0 → 1.1.0**: Requiere importación de base de datos de ciudades
- **0.9.0 → 1.0.0**: Migración manual requerida

### Deprecaciones
- **Ninguna en 1.2.0**: No hay funcionalidades deprecadas

### Breaking Changes
- **Ninguna en 1.2.0**: No hay cambios incompatibles

---

## Roadmap Futuro

### Versión 1.3.0 (Planeada)
- **Integración con Gravity Forms**: Soporte nativo para Gravity Forms
- **Múltiples Vehículos**: Soporte para envíos de múltiples vehículos
- **Historial de Cotizaciones**: Sistema de historial para usuarios
- **Exportación de Datos**: Exportación de cotizaciones a CSV/PDF

### Versión 1.4.0 (Planeada)
- **Integración de Pagos**: Integración con Authorize.Net
- **Sistema de Reservas**: Reserva de envíos directamente desde el plugin
- **Notificaciones**: Sistema de notificaciones por email
- **Dashboard Avanzado**: Dashboard con métricas y estadísticas

### Versión 2.0.0 (Planeada)
- **API REST**: API REST completa para integraciones externas
- **Webhooks**: Sistema de webhooks para notificaciones
- **Multi-idioma**: Soporte para múltiples idiomas
- **Temas Personalizables**: Sistema de temas para el formulario

---

**Nota**: Este changelog sigue las convenciones de [Keep a Changelog](https://keepachangelog.com/).

