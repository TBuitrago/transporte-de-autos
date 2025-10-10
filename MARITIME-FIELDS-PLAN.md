# Plan de Implementación: Campos Adicionales para Transporte Marítimo

## Requerimientos

### Cuando el transporte es MARÍTIMO, agregar los siguientes campos:

#### 1. Shipper Information (Información del Embarcador)
- S. Name
- S. Street
- S. State
- S. Country
- S. Zip Code
- S. City
- S. Phone 1
- S. Phone 2

#### 2. Consignee Information (Información del Consignatario)
- C. Name
- C. Street
- C. State
- C. Country
- C. Zip Code
- C. City
- C. Phone 1
- C. Phone 2

#### 3. Pick Up Information (Información de Recogida)
- P. Name
- P. Street
- P. City
- P. State
- P. Country
- P. Zip Code
- P. Phone 1
- P. Phone 2

#### 4. Drop Off Information (Información de Entrega)
- D. Name
- D. Street
- D. City
- D. State
- D. Country
- D. Zip Code
- D. Phone 1
- D. Phone 2

#### 5. Vehicle Information (Información del Vehículo)
- Year (no editable - traído del paso anterior)
- Make (no editable - traído del paso anterior)
- Model (no editable - traído del paso anterior)
- Car Type (no editable - traído del paso anterior)
- Car Conditions
- Fuel type
- Unit Value
- Color
- Dimensions LxWxH (Opcional)

#### 6. Others (Otros)
- Title (Opcional)
- Registration (Opcional)
- ID (Opcional)

## Lógica de Visualización

### Si es desde USA a PR:
- Se pide la información de recogida (Pick Up)
- Se pide la información del overseas (Shipper/Consignee)

### Si es desde PR a USA:
- Se pide la información de entrega (Drop Off)
- Se pide la información del overseas (Shipper/Consignee)

## Campos Pre-llenados (No editables)
- Vehicle Information (Year, Make, Model, Car Type) - vienen del paso anterior
- Ciudades y ZIP codes cuando corresponda

## Implementación

### Archivos a modificar:
1. `includes/class-sdpi-form.php` - Agregar lógica para renderizar campos marítimos
2. `assets/form-script.js` - Manejar la lógica de mostrar/ocultar campos según el tipo de transporte
3. `assets/form-styles.css` - Estilos para los nuevos campos
4. `includes/class-sdpi-history.php` - Actualizar para guardar los nuevos campos

### Flujo:
1. Usuario obtiene cotización
2. Si es marítimo, al hacer clic en "Continuar", se muestran campos adicionales específicos
3. Los campos del vehículo vienen pre-llenados del paso anterior
4. Se determina automáticamente qué campos mostrar según la dirección (USA->PR o PR->USA)
5. Se guardan todos los datos adicionales antes de proceder al pago
