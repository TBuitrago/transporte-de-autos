# TODO: Inversión del flujo de cotización SDPI

## Tareas completadas:
- [x] Modificar render_form() para no verificar datos de contacto al inicio
- [x] Modificar has_client_info() para siempre retornar true (bypass temporal)
- [x] Agregar nuevo handler ajax_finalize_quote_with_contact()
- [x] Modificar ajax_get_quote() para retornar needs_contact_info en lugar del precio

## Tareas pendientes:
- [ ] Actualizar form-script.js para manejar el nuevo flujo
  - [ ] Interceptar respuesta de ajax_get_quote
  - [ ] Mostrar formulario de contacto cuando needs_contact_info = true
  - [ ] Enviar datos de contacto junto con quote_data a ajax_finalize_quote_with_contact
  - [ ] Mostrar precio final después de capturar contacto
- [ ] Probar el flujo completo
- [ ] Verificar que el histórico se crea correctamente después de capturar contacto
