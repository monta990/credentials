# HANDOFF — Credentials 0.0.36 (PR #7 abierto; incorpora los trabajos de #6 y #7)

## Estado de implementación

`main` está en 0.0.34 e incluye los cambios del PR #5. El PR #6 se fusionó en su rama base `feat/configurable-lifecycle-reminders` después de que el PR #5 ya se hubiera integrado; por eso sus diagnósticos de notificación 0.0.35 todavía no están en `main`. Para evitar crear otro PR, el único PR abierto #7, en `feat/category-editing`, conserva ahora esos cambios del #6 junto con la edición de categorías de 0.0.36 y se basa directamente en `main`. La integración final debe esperar el CI y la revisión Codex del #7; después queda pendiente la prueba visual y funcional en una instancia GLPI 12 real.

La lógica de recordatorios conserva el programador, el sistema de notificaciones y la cola nativos de GLPI. El valor predeterminado sigue siendo 30 días y los recordatorios vienen activados en instalaciones existentes para mantener el comportamiento anterior. No implementa un servidor SMTP propio ni modifica/rota contraseñas automáticamente.

## Funcionalidad incorporada

- Página independiente registrada mediante Hooks::CONFIG_PAGE, con ruta Symfony /config.
- Comprobador de versiones estables de GitHub en src/Service/UpdateChecker.php, con caché de seis horas y estados diferenciados para actualización disponible, versión actual, ausencia de releases y error de conexión.
- Catálogo de categorías por entidad en glpi_plugin_credentials_categories, con creación, edición de nombre/descripción y eliminación segura solo cuando no existan credenciales asociadas.
- La edición conserva el ID, la entidad y las asociaciones existentes; bloquea nombres vacíos, duplicados en la misma entidad y cambios fuera del alcance de acceso del administrador.
- Las escrituras de categorías (crear, editar, find-or-create y eliminar) se serializan con una transacción y un bloqueo `SELECT ... FOR UPDATE` sobre la fila correspondiente de `glpi_entities`, antes de buscar nombres; evita que dos administradores registren el mismo nombre activo en una entidad sin exigir un índice único retroactivo.
- Campos adicionales en la tabla de credenciales: plugin_credentials_categories_id, date_expires, date_last_review, date_next_review y reviewer_users_id.
- Indicadores de vigencia y revisión: vencida, por vencer, revisión atrasada, revisión próxima y vigente.
- Panel administrativo con conteos, elementos que requieren atención, categorías, auditoría, archivados, conservación y estado de la clave GLPI.
- Restauración de credenciales archivadas mediante asociación explícita con un elemento disponible de la misma entidad.
- Eliminación definitiva manual de credenciales archivadas que superen el periodo de retención y no tengan relaciones remanentes.
- Derecho adicional plugin_credentials_backup, independiente de los derechos de lectura, edición y revelación de contraseñas.
- Exportación protegida con contraseña mediante Argon2id y XChaCha20-Poly1305 autenticado; importación valida entidades y asociaciones y vuelve a cifrar secretos con GLPIKey de destino.
- Importar crea IDs nuevos; no sobrescribe credenciales existentes. Las asociaciones se restablecen cuando coinciden tipo, ID, nombre y entidad. Si no hay una coincidencia, la credencial se importa archivada para permitir una vinculación manual posterior.
- Transferencias entre entidades conservan fechas y responsable de revisión; las categorías específicas de la entidad se recrean en el destino y las credenciales clonadas apuntan a la categoría de destino.
- Acción automática diaria registrada en GLPI (`CredentialLifecycleReminderService::cronSendReminders`) para revisar vencimientos y revisiones atrasadas o dentro del periodo configurable de 1 a 365 días (30 por defecto).
- Ajustes administrativos para activar/pausar los recordatorios y definir la anticipación; las instalaciones anteriores mantienen la configuración habilitada y el periodo original de 30 días.
- Diagnóstico en el panel de configuración del estado de las notificaciones globales de GLPI y de la tarea automática Credentials (no registrada, activa o deshabilitada/bloqueada).
- Diagnóstico por entidad de la notificación del evento `lifecycle_reminder`: existencia de notificación activa, plantilla por correo y destinatario `Review responsible`. El cron y el panel comparten la misma validación para evitar discrepancias. El chequeo consulta la relación de plantillas sin el filtro de canales globales de GLPI, diferencia una plantilla ausente de un canal `mailing` deshabilitado y utiliza el alcance recursivo nativo de entidades.
- Evento de notificación nativo `Expiration and review follow-up`, tipo de elemento `Credential`, con destinatario específico `Review responsible`; la acción automática se registra como `sendreminders` con frecuencia diaria. El administrador debe crear/activar la notificación y asignarle una plantilla de correo en GLPI.
- Solo se consumen recordatorios cuando las notificaciones globales están habilitadas, existe una notificación activa con plantilla y destinatario del responsable para la entidad, y el responsable está activo, tiene acceso a la entidad y tiene correo predeterminado.
- Tabla `glpi_plugin_credentials_reminders` con clave única por credencial, entidad, fecha de vencimiento/revisión y responsable. Evita correos repetidos, incluidos dos avisos por campos que vencen en la misma fecha y ejecuciones concurrentes del cron.
- El escaneo selecciona únicamente ID, nombre, entidad, responsable y fechas. El objeto mínimo enviado al sistema de notificaciones no contiene login, password, URL, notas ni ciphertext; las plantillas exponen ID, nombre, estado, fecha y acción.
- La purga definitiva de credenciales elimina sus marcadores de recordatorio dentro de la misma transacción; la desinstalación elimina la tabla y desregistra las acciones automáticas del plugin.
- Catálogos PO/MO actualizados para es_MX, es_ES y fr_FR.

## Seguridad y límites deliberados

- Las credenciales siguen utilizando GLPIKey para username, password, URL y notas.
- Los archivos de respaldo no contienen contraseñas legibles. La contraseña del respaldo es distinta de glpicrypt.key.
- La importación exige permiso de configuración, permiso dedicado de respaldo y derechos de lectura y creación de credenciales.
- La exportación exige permiso de configuración, permiso dedicado de respaldo y derecho de lectura de credenciales.
- La purga definitiva exige además derechos de lectura y eliminación de Credentials; tener acceso a la configuración no basta.
- Los reportes de actividad no muestran contraseñas ni valores protegidos y deben limitarse a entidades y elementos legibles.
- Las restauraciones y purgas siguen siendo acciones administrativas explícitas; el cron nuevo solo genera notificaciones y nunca elimina credenciales.
- La política de retención con valor 0 conserva indefinidamente los registros archivados y desactiva la purga manual.
- El comprobador de GitHub nunca instala actualizaciones automáticamente.
- Las fechas de vencimiento y revisión pueden generar correos de recordatorio, pero no activan un mecanismo automático de rotación de contraseñas.
- La importación tiene límite de 20 MB, 2,000 entradas y 250 relaciones por credencial. La exportación también valida que el archivo final no supere el límite de importación.

## Validación pendiente

- Ejecutar CI completo en PHP 8.3, 8.4, 8.5 y 8.6 y revisar la construcción del ZIP instalable.
- Probar instalación limpia y actualización desde 0.0.31 en una copia de GLPI 12.0.x.
- Verificar migración aditiva de campos y creación de la tabla de categorías sin perder datos previos.
- Probar perfiles sin permisos, con permisos parciales y con alcance de entidades múltiples.
- Probar exportación e importación con contraseña correcta/incorrecta, respaldo alterado, elementos inexistentes, IDs coincidentes con nombres diferentes, entidades no accesibles, archivados y archivos grandes.
- Probar migración entre dos instancias GLPI usando claves GLPIKey diferentes y confirmar que los secretos importados se pueden revelar en el destino.
- Probar restauración y purga con registros archivados con/sin enlaces y fechas de modificación anteriores/posteriores al periodo de retención.
- Probar los ajustes de recordatorios con la función habilitada/deshabilitada y anticipaciones mínima, predeterminada y máxima; confirmar que los vencimientos pasados siguen generando avisos.
- Probar los diagnósticos de notificación en entidades con evento ausente, evento sin plantilla, plantilla sin destinatario Review responsible y configuración completa; comprobar la herencia de notificaciones en entidades hijas en GLPI 12.
- Probar edición de categorías locales y globales, nombres duplicados dentro de una misma entidad, permisos de acceso, límites de longitud y preservación de asociaciones de credenciales.
- Verificar con dos sesiones concurrentes que el bloqueo de entidad evita crear o renombrar categorías al mismo nombre en la misma entidad; comprobar también la creación automática de categorías durante transferencias.
- Configurar en GLPI una notificación para el tipo Credential, evento Expiration and review follow-up, destinatario Review responsible y una plantilla de correo; ejecutar la acción automática manualmente en staging.
- Probar responsables sin correo, inactivos, con fechas de vigencia de cuenta, sin perfil en la entidad y con acceso recursivo; confirmar que no se consume el marcador si el destinatario no es elegible.
- Verificar correos vencidos y próximos, deduplicación por credencial/entidad/fecha/responsable, fechas coincidentes y ejecuciones de cron concurrentes.
- Revisar el panel administrativo con administrador y perfiles restringidos.
- Ejecutar PHPCS GLPI Standard y aceptación manual en navegador. CI cubre pruebas CLI y contratos estáticos/simulados; no sustituye una instancia GLPI viva.

## Notas de despliegue

- Versión propuesta por PR #5: 0.0.34.
- Versión propuesta por PR #6 apilado: 0.0.35.
- Versión propuesta por PR #7 apilado: 0.0.36.
- `main` no incluye todavía estos cambios; no lanzar una versión desde el branch apilado antes de fusionar en orden.
- GLPI: 12.0.x.
- PHP mínimo declarado: 8.3.
- La extensión PHP Sodium es necesaria para exportar/importar respaldos protegidos. Si no está disponible, las demás funciones siguen utilizables.
- El manifiesto de Marketplace permanece en estado development hasta completar URLs públicas, aceptación real y evidencia de release.

## Historial anterior

Consulta CHANGELOG.md para el historial completo.

---

## Historial técnico de versiones anteriores

Los siguientes apuntes preservan decisiones y comprobaciones de versiones previas; no sustituyen el estado 0.0.35 descrito al inicio.

La versión `0.0.31` conserva los flujos funcionales de la versión `0.0.28`: la contraseña revelada se muestra en un `<input type="text" readonly>` seleccionable; copiar y ocultar leen y limpian `input.value`. Después de guardar desde el modal, `public/js/credential-frame.js` solicita la navegación completa del padre mediante `postMessage` same-origin validado. Cancelar sigue siendo independiente y no envía el formulario.

El bloqueo principal por credencial sigue usando un `UPDATE` con token aleatorio a través de la API de base de datos GLPI. Si ese `UPDATE` no afecta una fila activa, la transferencia consulta el estado de la credencial y el vínculo exacto de origen con lecturas `FOR UPDATE`: una credencial archivada, movida de entidad o desvinculada mientras esperaba se considera entrada obsoleta y se omite sin abortar GLPI. Si sigue activa y vinculada, el fallo de bloqueo se conserva como error explícito. La transferencia vuelve a leer la fila de credencial con `SELECT ... FOR UPDATE` después de obtener el bloqueo, para copiar el último nombre y el último ciphertext comprometido aunque haya ocurrido una edición concurrente durante la espera. La consulta selecciona columnas fijas del plugin y limita por el ID de la credencial. Además, las decisiones de transferencia y purga usan una lectura bloqueante actual de las relaciones (`SELECT ... FOR UPDATE`) después de obtener ese bloqueo. Esto evita usar una vista consistente anterior de InnoDB `REPEATABLE READ` después de esperar por una operación concurrente. La consulta se ejecuta mediante `DBmysql::doQuery()` y cita tabla, columnas y valores con `DBmysql::quoteName()`/`quoteValue()`; no acepta fragmentos SQL del usuario.

En este entorno se ejecutó la batería PHP en PHP 8.4.24, incluida la prueba nueva del bloqueo y la regresión de transacciones/savepoints; también pasaron todos los tests JavaScript, `node --check` sobre los scripts públicos, la comprobación PO/MO y `tools/validate-package.php`. `credentials.xml` se validó como XML bien formado mediante Python ElementTree porque el PHP local no tiene habilitada la extensión DOM (CI sí la instala). No se dispone de Composer/PHPCS ni de una instancia GLPI real conectada al entorno, así que el estándar oficial y las pruebas dinámicas de actualización, concurrencia, cifrado y navegador siguen requiriendo CI/staging. El manifiesto permanece en `development`: las URLs públicas y capturas requeridas no se deben inventar.

## Cambios de 0.0.31

- Si el bloqueo condicional no afecta filas, la transferencia consulta la fila y el vínculo exacto de origen con lecturas actuales bloqueantes. Credenciales archivadas, movidas de entidad o desvinculadas durante la espera se omiten sin abortar el movimiento o clonación de GLPI.
- El cambio cubre las rutas de movimiento y clonación; una credencial todavía activa y vinculada mantiene el error ante un fallo real de bloqueo.
- Se añadieron regresiones para archivado concurrente en ambas rutas y bloqueo fallido con relación todavía activa.
- Se aclaró que `getCredentialForUpdate()` también adquiere el bloqueo de fila mediante su propia lectura `FOR UPDATE`.

## Cambios de 0.0.30

- La transferencia vuelve a leer la fila de credencial con una lectura actual bloqueante después de obtener el bloqueo. Las copias entre entidades utilizan los campos cifrados más recientes y no una versión retenida por el snapshot anterior de InnoDB.
- El validador comprueba los marcadores de regresión sin depender de mayúsculas/minúsculas e incluye el contrato de lectura actual de los datos cifrados.
- Se agregó una regresión de edición concurrente que cambia nombre, login, contraseña, URL y notas durante la espera del bloqueo y verifica que la copia use los últimos valores cifrados.
- La prueba de API DBmysql valida la consulta actual sobre la fila de credencial, además de la lectura bloqueante de relaciones.

## Cambios de 0.0.29

- La transferencia con ID conservado vuelve a consultar los vínculos mediante una lectura actual bloqueante después de adquirir el bloqueo de la credencial. Si el vínculo de origen se eliminó mientras esperaba, se omite sin abortar la transferencia de GLPI.
- La clonación valida el vínculo exacto mediante la lectura actual y no restablece una relación a partir de una lista obsoleta.
- La purga consulta los vínculos sobrevivientes con una lectura actual bloqueante antes de archivar. Un vínculo agregado durante la espera impide marcar erróneamente la credencial como huérfana.
- La lectura especial usa `DBmysql::doQuery()` con identificadores/valores citados por GLPI. El token `link_lock_token` continúa siendo el mecanismo principal para serializar las mutaciones de una credencial.
- Las regresiones simulan una vista consistente anterior y cambios confirmados durante la espera del bloqueo.

## Cambios de 0.0.27

- Reemplazado el bloqueo de fila `SELECT ... FOR UPDATE` en ejecución por una escritura del token `link_lock_token` usando `$DB->update()` y `$DB->getAffectedRows()` de GLPI. El token aleatorio garantiza que una fila existente realmente cambie y permanezca bloqueada por InnoDB hasta cerrar la transacción.
- Añadida la columna mediante `Migration::addField()` para instalaciones existentes; las tablas nuevas la incluyen desde el DDL inicial. La actualización no modifica los campos cifrados, credenciales ni relaciones existentes.
- Las rutas de vincular, desvincular y eliminar incorporan validación de entidad/estado activo en el propio `UPDATE` de bloqueo y revalidan estado, entidad, permisos y relación dentro de la transacción.
- El bloqueo de varias credenciales durante la purga adquiere IDs en orden numérico para reducir interbloqueos. Cuando una purga deja una credencial sin vínculos, se archiva y se registra `HISTORY_ORPHANED` en el historial de la credencial, sin incluir plaintext ni ciphertext.
- Se documenta que el archivado es lógico: los campos cifrados permanecen en la tabla mientras exista el registro; el plugin no define un plazo de retención ni implementa borrado criptográfico automático.
- Añadida `tests/php/credential_lock_test.php`; la regresión de savepoints ahora verifica el uso de la API de GLPI, el archivado huérfano y su evento de historial.
- El manifiesto sigue en `development`; faltan URLs públicas reales, una descarga inmutable y capturas. PHPCS y las pruebas con GLPI real no se han podido ejecutar en este entorno.


## Cambios de 0.0.26

- La contraseña revelada ahora se renderiza en un `input` de solo lectura y seleccionable, con `color` y `-webkit-text-fill-color` forzados para que Chromium no deje el texto invisible por reglas de tema. Copiar y ocultar operan sobre `input.value`; el fallback de copia selecciona el campo.
- Tras Guardar en alta/edición, el iframe carga el helper externo y solicita mediante `postMessage` que la página principal navegue a la ficha de usuario/activo/ticket. El listener valida el origen, el iframe emisor y el destino same-origin. Esto evita depender de script inline o de navegación top-level bloqueada por el sandbox del iframe. Cancelar sigue cerrando o regresando a detalles, sin POST.
- Se añadieron pruebas de valor visible del input, CSS `-webkit-text-fill-color`, copia/ocultación del campo, navegación de guardado same-origin y rechazo de destinos externos; el validador y CI exigen estas pruebas.



## Cambios de 0.0.24

1. `CredentialController` ahora solicita navegación de la ventana principal a la ficha del activo/ticket después de un guardado correcto. Esto realiza una recarga completa del contexto para reflejar cambios y contadores; no se ejecuta al cancelar.
2. El formulario distingue cancelar de guardar: cancelar alta cierra el diálogo nativo; cancelar edición iniciada desde detalles vuelve a los detalles dentro del mismo iframe; cancelar desde edición independiente cierra el diálogo sin POST.
3. `credential-frame.js` conecta con el modal anfitrión mediante mensajes `postMessage` same-origin. El listener padre valida origen, ventana emisora y pertenencia a un iframe de modal antes de cambiar título/icono o cerrar.
4. Los títulos de agregar, editar, detalles y elementos vinculados incorporan iconos Tabler creados mediante nodos DOM (sin interpolación HTML).
5. `credential-details.js` realiza la petición de revelar en modo same-origin, sin caché, con cabeceras JSON y diagnóstico visible para respuestas HTTP, HTML inesperado y reautenticación. Se quitó `redirect: error`, que rechazaba la respuesta antes de que la interfaz pudiera explicar el problema.
6. La acción de eliminación de detalles usa `btn-outline-danger` y CSS con borde visible.
7. Se añadieron `tests/js/modal_parent_message_test.js` y `tests/php/form_navigation_contract_test.php`; se ajustaron los tests de contraseña y modal para ejecutar y comprobar los contratos reales de los scripts y plantillas. CI y el validador del paquete exigen estas regresiones.
8. PHP 8.6 permanece como matriz obligatoria de CLI/CI junto con PHP 8.3, 8.4 y 8.5.

## Validación de 0.0.29

- `tests/php/credential_lock_test.php` verifica la lectura actual `FOR UPDATE`, la deduplicación y el uso de identificadores/valores citados por GLPI DBmysql.
- `tests/php/credential_transfer_test.php` simula una vista consistente antigua y comprueba que vínculos retirados no se restauran ni abortan una transferencia con el mismo ID.
- `tests/php/transaction_savepoint_test.php` simula que `link_existing` confirma un vínculo durante la espera de la purga; esta debe conservar activa la credencial cuando todavía tiene relaciones.
- El validador exige el uso de la lectura actual en las rutas de transferencia y purga.
- Las pruebas contra GLPI real/staging, PHPCS y la aceptación Marketplace siguen pendientes; no se declaran como ejecutadas.

## Validación de 0.0.27

- `bash tools/run-cli-tests.sh 8.4` con PHP 8.4.24: sintaxis y todas las regresiones PHP; incluye `credential_lock_test.php` y savepoints de purga.
- Prueba específica del bloqueo: token de 32 caracteres hexadecimales, criterios ID/entidad/activo, rechazo cuando `getAffectedRows()` no es 1 y ausencia de SQL crudo en `CredentialItemLink`.
- La regresión de savepoints comprueba que la purga utiliza la API de actualización de GLPI, archiva la credencial huérfana, registra `HISTORY_ORPHANED` y no cierra la transacción exterior.
- Todos los archivos `tests/js/*.js` pasaron con Node; `node --check` pasó para los scripts bajo `public/js/`.
- `python3 tools/compile_locales.py --check` y `php tools/validate-package.php`: correctos. `credentials.xml` pasó parseo con Python ElementTree; el PHP local carece de la extensión DOM.
- PHPCS/estándar oficial, PHP 8.3/8.5/8.6 en CI y pruebas dinámicas en instancia real no se ejecutaron localmente. No se declara aceptación de Marketplace ni estabilidad.

## Validación de 0.0.26

- `bash tools/run-cli-tests.sh 8.4` con PHP 8.4.24: sintaxis PHP y todas las regresiones PHP.
- Node ejecutó los scripts reales de iframe/página: `password_ui_test.js`, `modal_parent_message_test.js`, `save_navigation_frame_test.js`, confirmaciones destructivas, cancelar modal y actualización de pestañas/contadores.
- Se verificó que `details.html.twig` emite un input readonly con `value` escapado, y que CSS fuerza `-webkit-text-fill-color` y visibilidad; copy/hide limpian/leen la propiedad `value`.
- Se verificó que el guardado genera una respuesta con el helper externo `credential-frame.js`, y que el padre solo acepta navegación desde un iframe del modal, mismo origen y destino same-origin.
- `python tools/compile_locales.py --check`, `php tools/validate-package.php`, parseo XML, `node --check` de JavaScript e integridad ZIP/SHA-256.
- PHP 8.6 permanece en la matriz CI obligatoria, pero no fue ejecutado localmente porque el runtime disponible es PHP 8.4.24.
- No se ejecutó una instancia GLPI real ni un navegador contra el servidor del usuario; se requiere staging para validar visualmente la visibilidad del secreto y la recarga de la ficha después del guardado.
## Validación de 0.0.24

- `bash tools/run-cli-tests.sh 8.4` se ejecutó con PHP 8.4.24: sintaxis de todos los archivos PHP y pruebas PHP de navegación, política de vínculos, agregación de tickets, simulación GLPI 12 y savepoints.
- Todas las pruebas `tests/js/*.js` se ejecutaron con Node: confirmación destructiva, confirmación desde iframe, modal, puente de mensajes, revelar/copiar/ocultar y actualización de pestaña/contador.
- `node --check` en los scripts JavaScript de producción.
- `python tools/compile_locales.py --check` para verificar PO/MO sincronizados.
- `php tools/validate-package.php` y parseo XML de `credentials.xml`.
- PHP 8.6 está configurado como job obligatorio en CI, pero no está instalado localmente en este entorno.
- No se ejecutó una instancia GLPI real ni una prueba interactiva contra el servidor del usuario; la navegación principal, los títulos y las respuestas de reautenticación deben confirmarse en staging.

## Cambios de 0.0.23

- Se corrigió el atributo de confirmación de los formularios de desvincular y eliminar en `details.html.twig`: utilizaban `data-credentials-confirm-inline`, que excluía el formulario del listener delegado de la página padre y tampoco coincidía con el selector de `credential-details.js`. Ahora ambos usan `data-credentials-confirm`, que procesa el script cargado dentro del iframe antes del POST.
- Se añadió `tests/js/detail_confirmation_test.js`, que carga el JavaScript real del iframe y comprueba cancelar/confirmar en desvincular y eliminar, además de validar los atributos reales de Twig. CI lo ejecuta y `tools/validate-package.php` exige el contrato.
- Incrementada la versión del plugin y los metadatos PO/MO a `0.0.23`.

## Cambios de 0.0.22

1. Los recursos de interfaz se distribuyen bajo `public/css/` y `public/js/`, que es la ubicación que GLPI 12 resuelve para los hooks `add_css` y `add_javascript`. El detalle carga `public/js/credential-details.js` mediante la URL pública `/plugins/credentials/js/credential-details.js`; Revelar envía un POST HTML normal dentro del mismo iframe para que el núcleo pueda presentar y reproducir su reautenticación interactiva. La respuesta con contraseña lleva `no-store` y nunca se cachea.
2. Copiar intenta clipboard del host y del iframe, usa fallback `execCommand` con textarea temporal y muestra estado accesible; Ocultar borra texto plano y restablece el botón Revelar. `pagehide` limpia la contraseña.
3. `public/js/credential-frame.js` actualiza título/icono del modal GLPI y cierra Cancelar en cualquier formulario modal sin enviar el formulario. Guardar en alta/edición directa cierra con marca de cambios; guardar desde Detalles conserva el contexto y vuelve a Detalles actualizados, con alerta de éxito. Por eso Cancelar y Guardar tienen resultados distintos.
4. El contador de elementos vinculados desde Detalles navega el mismo iframe del modal (sin abrir otro modal anidado). Desde la pestaña principal, el contador sigue abriendo el diálogo nativo GLPI. La lista identifica tipo, ID y nombre de cada elemento legible, muestra la relación con el elemento actual, permite volver a Detalles y avisa si hay relaciones ocultas por permisos.
5. Se añadió `/ajax/Credential/TabCount`, con respuesta JSON no-store y autorización de objeto; el cierre de diálogos modificados recarga solo la pestaña activa y recalcula su badge. Se propaga URL de contador desde botones en pestañas de activos/usuarios y tickets.
6. `CredentialTicketTab` acumula correctamente IDs protegidos en múltiples orígenes. La simulación GLPI 12 ejecuta la clase real y cubre ticket solicitante + activo, desvinculación, revinculación, ausencia de vínculos y IDs protegidos.
7. Se sustituyó el test JS que extraía script inline por una prueba que ejecuta el JS real del iframe y se agregaron pruebas de cancelar modal, recarga de pestaña y actualización de badge. La nueva `tests/php/linked_items_contract_test.php` simula asociaciones `itemtype/ID`, deduplicación y navegación de modal con contexto. PHP 8.6 sigue como matrix CLI obligatorio.

- **Plugin:** Credentials
- **Clave / carpeta:** `credentials`
- **Versión:** `0.0.31` (compilación de remediación; no estable)
- **GLPI soportado:** `12.0.x` (`~12.0.0` en el manifiesto; `12.0.0`–`12.0.99` en `setup.php`)
- **PHP mínimo declarado:** `8.3`
- **Idioma fuente:** inglés
- **Catálogos compilados:** `es_ES`, `es_MX`, `fr_FR`; no se generan catálogos PO/MO en inglés
- **Licencia:** GPL-3.0-or-later
- **Cifrado:** campos `login`, `password`, `url` y `notes` con `GLPIKey`, registrados en `Hooks::SECURED_FIELDS`
- **Metadatos Marketplace:** se mantiene `<state>development</state>` intencionalmente. No se proporcionaron repositorio público, URLs de incidencias/documentación ni URL inmutable de descarga; no inventar esos valores ni afirmar aprobación de Teclib.

## Cambios de 0.0.21

1. Password reveal uses an interactive POST to the protected detail route so GLPI 12 can display/replay native re-authentication; the controller verifies item/credential/entity rights, records secret-free history before rendering plaintext, and sends no-store/no-cache HTML.
2. Reveal reloads only the credential iframe through a native POST; A visible, higher-contrast hint explains the hidden state; the revealed value uses larger typography and a theme-aware high-contrast surface. Hide clears plaintext from the DOM and restores the reveal action; Copy tries the same-origin clipboard fallback and displays accessible status feedback.
3. Add Modal Cancel closes the active GLPI dialog; Edit Cancel returns to details in the same iframe. Details/form titles receive Tabler icons using safe DOM node construction. Buttons and inputs use larger GLPI-native sizing and the password has improved contrast/readability.
4. Credential IDs and linked-item counts are visible in user/asset lists, ticket lists and details. The ID can be used with Link existing.
5. Explicit unlink is forbidden when it would remove the last association. The UI warns/disables the action, the controller and model enforce the invariant, and a `FOR UPDATE` lock serializes relationship mutations. Deleting the credential remains a separate intentional operation.
6. Added JS regressions for asynchronous reveal, local hide, copy feedback and modal cancellation, and a PHP regression for the last-link rule. CI continues to require PHP 8.3/8.4/8.5/8.6.

## Cambios de 0.0.20

- CI incluye PHP 8.6 como matriz obligatoria de compatibilidad (`php-version: 8.6` en `shivammathur/setup-php@v2`, que instala la rama nightly PHP-8.6 antes de GA). La ejecución 8.6 corre las mismas pruebas CLI de sintaxis y regresión que 8.3/8.4/8.5 y no utiliza `continue-on-error`. La prueba local equivalente es `bash tools/run-cli-tests.sh 8.6`, ejecutada con PHP 8.6 primero en `PATH`.
- Se añadieron `tools/run-cli-tests.sh` y `tests/php/cli_runtime_test.php` para verificar SAPI CLI y la versión minor exacta antes de revisar sintaxis y ejecutar todas las pruebas PHP.
- El validador del paquete exige la entrada 8.6 en la matriz. `setup.php` conserva PHP 8.3 como mínimo de producción; probar PHP 8.6 nightly es cobertura de compatibilidad, no un requisito de producción ni una certificación de GLPI.

## Cambios de 0.0.19

1. Rutas de negocio movidas a controladores Symfony del plugin en `src/Controller/`; `front/*.php` se conserva solamente como redirección de compatibilidad para enlaces antiguos.
2. Vistas de detalles, formulario, pestañas y agregación de tickets migradas a Twig bajo `templates/credential/` y `templates/profile.html.twig`.
3. Se valida que `context_ticket_id` sea un ticket legible y que la relación con el elemento de origen sea real: usuario solicitante (`Ticket_User` + `REQUESTER`) o elemento asociado (`Item_Ticket`).
4. La eliminación global de una credencial elimina sus relaciones y registra eventos de historial sin secretos para cada elemento relacionado dentro de una sola transacción.
5. La purga de usuarios/activos elimina relaciones y archiva credenciales huérfanas. Se utiliza un nivel de transacción anidado, que GLPI implementa con savepoints; el plugin no consulta `DBmysql::isInTransaction()` (privado) ni trata `beginTransaction()`/`commit()` (void) como booleanos.
6. La creación de derechos por perfil comprueba errores de persistencia y falla de forma cerrada cuando no puede asegurar que las filas existan.
7. El hook instala tablas con DDL estático durante el ciclo de vida (patrón documentado de GLPI), ejecuta `Migration::executeMigration()` después de registrar los derechos y limpia cachés al finalizar instalación/actualización.
8. Se alinearon README y manifiesto con la versión/compatibilidad real; el manifiesto permanece en desarrollo hasta disponer de URLs públicas verificables y evidencia de aceptación real.
9. Se reforzaron validadores de paquete, compilación reproducible PO→MO, CI estático y la matriz de regresión de seguridad. CI valida PHP 8.3/8.4/8.5/8.6 mediante CLI (8.6 nightly obligatorio); incluye pruebas ejecutables para savepoints anidados y confirmación de operaciones destructivas.
10. Todas las respuestas/redirects sensibles fijan `no-store`, `no-referrer` y `nosniff`; los controladores Symfony no emiten headers PHP directamente. La disponibilidad de cifrado ahora exige que GLPIKey se lea y cargue correctamente. `Credential` se registra con GLPI para resolver el itemtype enlazado por el historial nativo y mostrar sus eventos traducidos.

## Cambios de UI y asociación en 0.0.21

- `src/Controller/CredentialController.php`: interactive POST reveal flow on `Credential/Details` (supports GLPI 12 native re-authentication).
- `src/CredentialLinkPolicy.php`: pure rule requiring more than one linked item before a relationship can be removed.
- `src/CredentialItemLink.php`: credential-row lock for link/unlink/delete/purge serialization; last-link unlink invariant enforced at the model boundary.
- `templates/credential/details.html.twig`: visible ID/link count, warning for a single relationship, interactive POST reveal controls, visible copy/hide feedback and icon title source.
- `templates/credential/form.html.twig`: larger controls, icon titles and functional modal Cancel.
- `templates/credential/item_tab.html.twig` and `ticket_tab.html.twig`: visible IDs and link counts for association workflows.
- `public/js/credentials.js`: local clear-on-hide, clipboard fallback, status messages and modal close action.
- `public/css/credentials.css`: scoped password readability and modal-control sizing.
- `tests/js/password_ui_test.js` and `tests/php/link_policy_test.php`: UI and last-link policy regressions.

## Arquitectura y archivos

- `setup.php`: metadatos, `SECURED_FIELDS`, registro de pestañas y hooks.
- `hook.php`: creación/eliminación de tablas, registro de derechos, limpieza de caché y hook de purga.
- `src/Controller/CredentialController.php`: endpoints Symfony para detalle, reveal, alta/edición, unlink y delete.
- `src/Controller/TicketCredentialsController.php`: vista agregada de credenciales de ticket.
- `src/Security.php`: autorización nativa, reautenticación, URLs y navegación segura.
- `src/Credential.php`: modelo CommonDBTM, cifrado GLPIKey, consultas de nombres/IDs no secretos y etiquetas de historial.
- `src/CredentialItem.php`: registro de pestañas para usuarios/activos compatibles y generación de modales GLPI.
- `src/CredentialTicketTab.php`: agregación de credenciales de usuarios solicitantes y activos asociados.
- `src/CredentialItemLink.php`: relaciones many-to-many, bloqueo de fila y limpieza al purgar elementos. `src/CredentialLinkPolicy.php` prohíbe desvincular la última relación.
- `src/CredentialHistory.php`: escritura en historial nativo, sin contraseña, ciphertext, nombre o URL.
- `src/Profile.php`, `templates/profile.html.twig`: derechos de perfil.
- `templates/credential/*.html.twig`: presentación; el autoescape de Twig está habilitado por GLPI.
- `front/*.php`: redirecciones de compatibilidad; sin consultas o lógica de negocio.
- `tools/compile_locales.py`: compilador GNU MO reproducible usando Python stdlib.
- `tools/validate-package.php`: verificaciones estructurales y de regresión del paquete.
- `.github/workflows/ci.yml`: lint PHP/JS, XML, PO/MO y validaciones del paquete.
- `tests/SECURITY_REGRESSION.md`: pruebas dinámicas requeridas en una instancia GLPI real.
- `tools/run-cli-tests.sh`: ejecuta sintaxis y regresiones bajo una versión PHP CLI esperada (8.3–8.6).
- `tests/php/cli_runtime_test.php`: comprueba que el intérprete CLI coincide exactamente con la versión de la matriz.
- `tests/php/transaction_savepoint_test.php`: prueba ejecutable del mantenimiento de transacciones anidadas con semántica equivalente a DBmysql 12.

## Persistencia

Tablas propias:

- `glpi_plugin_credentials_credentials`
- `glpi_plugin_credentials_items`

Campos cifrados registrados para la rotación nativa de GLPI:

- `glpi_plugin_credentials_credentials.login`
- `glpi_plugin_credentials_credentials.password`
- `glpi_plugin_credentials_credentials.url`
- `glpi_plugin_credentials_credentials.notes`

El nombre descriptivo permanece sin cifrar y se considera etiqueta no secreta. La base de datos y `glpicrypt.key` deben respaldarse/restaurarse juntos. No desinstalar para actualizar: el hook de desinstalación elimina las tablas del plugin.

## Validaciones realizadas en este entorno

- `php -l` en todos los archivos PHP del plugin y herramientas.
- `node --check public/js/credentials.js`.
- `node tests/js/confirmation_test.js` (declinar bloquea la operación destructiva; aceptar permite el envío).
- `python tools/compile_locales.py --check` para comprobar que MO coincide byte a byte con PO.
- `php tools/validate-package.php` (validación estructural).
- `php tests/php/transaction_savepoint_test.php` (regresión de savepoints anidados: commit/rollback del nivel del plugin sin cerrar la transacción externa).
- Parseo XML/YAML con herramientas disponibles, si están instaladas.
- `unzip -t` e `sha256sum` sobre el ZIP generado.
- Verificación estática de: atributos Symfony Route, redirecciones legacy, campos `SECURED_FIELDS`, reautenticación/derechos, transacciones anidadas y registro de historial sin secretos.

Estas comprobaciones son estáticas. No equivalen a pruebas de integración, penetración ni aceptación de Teclib.

## Pendiente antes de un Marketplace release estable

1. Proporcionar URLs públicas reales (repositorio, incidencias, documentación y descarga inmutable) y completar el XML conforme a las reglas vigentes del catálogo. No pasar `state` a `stable` antes de ello.
2. Instalar/actualizar desde una copia de GLPI `12.0.x` y ejecutar cada prueba de `tests/SECURITY_REGRESSION.md`.
3. Probar en navegador la revelación, reautenticación, modales, guardado, eliminación, refresh parcial de pestaña, ticket con varios requesters/items y custom assets.
4. Probar `security:change_key` y restauración de backup DB + `glpicrypt.key` en staging.
5. Ejecutar el coding standard de GLPI y resolver excepciones justificadas. PHPCS no se ha ejecutado en este entorno porque no se dispone de Composer/PHPCS ni de las dependencias del estándar.
6. Adjuntar CI verde, matriz GLPI/PHP, capturas saneadas sin secretos y SHA-256 del artefacto exacto.
7. Solicitar revisión técnica de Teclib; la aprobación no se puede afirmar antes de su respuesta.

## Procedimiento para continuar

- La siguiente modificación incrementa el plugin a `0.0.32` en `setup.php`, `credentials.xml`, encabezados PO, CHANGELOG, README/HANDOFF y validadores.
- No crear catálogos ingleses: inglés es idioma fuente.
- No cambiar el uso de GLPIKey ni quitar `Hooks::SECURED_FIELDS`.
- No migrar datos existentes ni eliminar tablas como parte de una actualización.
- No reintroducir lógica de negocio en `front/`; las rutas nuevas deben ser controladores Symfony y las vistas Twig.
- No llamar a `DBmysql::isInTransaction()` (privado) ni comprobar retornos booleanos de `beginTransaction()`/`commit()`; para operaciones anidadas, abrir/cerrar exclusivamente el nivel de savepoint del plugin.
- Mantener cada cambio transaccional, de forma que una escritura de historial fallida revierta la operación asociada.

