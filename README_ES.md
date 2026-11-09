# Credentials para GLPI 12

<p align="center"><img src="logo.png" alt="Logotipo de Credentials" width="160"></p>

**Versión:** `0.0.28` (compilación de desarrollo)  
**Clave/directorio:** `credentials`  
**Idioma fuente:** inglés  
**Catálogos:** `es_ES`, `es_MX`, `fr_FR` (no se generan archivos PO/MO en inglés)  
**Autor:** Edwin Elias Alvarez  
**Licencia:** GPL-3.0-or-later  
**Requisitos:** GLPI `12.0.x`, PHP `8.3+`

Credentials permite almacenar información de acceso protegida y asociarla con usuarios y activos compatibles de GLPI. La pestaña de tickets reúne las credenciales asociadas a sus usuarios solicitantes y elementos vinculados, sin duplicar registros.

Cada registro tiene un ID visible para facilitar su vinculación desde otros elementos, un nombre descriptivo, usuario, contraseña, URL y notas. La credencial debe permanecer vinculada al menos a un elemento: no se permite desvincular la última relación; para retirarla por completo se debe eliminar la credencial. La contraseña es obligatoria al crear; al editar, déjala vacía para conservar la actual. El usuario, la contraseña, la URL y las notas se cifran en reposo mediante `GLPIKey` nativo de GLPI. El nombre se conserva intencionalmente como etiqueta no secreta para identificar y buscar el registro.

## Modelo de seguridad

- Las lecturas y modificaciones sensibles se gestionan mediante controladores Symfony de GLPI 12 ubicados en `src/Controller/`.
- Las páginas y acciones protegidas exigen la reautenticación nativa de GLPI.
- El plugin utiliza dos permisos de perfil: `plugin_credentials` para las operaciones y `plugin_credentials_reveal` para revelar contraseñas.
- Los cuatro campos cifrados están registrados en `Hooks::SECURED_FIELDS`, de modo que GLPI pueda volver a cifrarlos durante la rotación de la clave.
- Las listas y contadores consultan únicamente IDs, nombres y fechas de modificación; nunca seleccionan ni descifran campos secretos.
- Cada solicitud vuelve a comprobar los permisos sobre la credencial, el usuario/activo asociado, el límite de entidad y, cuando se proporciona, la relación exacta con el ticket de origen.
- Revelar una contraseña exige un permiso independiente y registra primero un evento sin secretos en el historial nativo del elemento GLPI. La revelación utiliza un POST normal dentro del iframe para que GLPI 12 pueda mostrar y reproducir su reautenticación nativa; la respuesta HTML no se almacena en caché. Ocultar borra el secreto del DOM y copiar informa si se completó o falló.
- Las respuestas y redirecciones que pueden contener secretos utilizan `Cache-Control: no-store` y `Referrer-Policy: no-referrer`; el campo de contraseña nunca se repuebla después de un envío fallido. Las acciones de desvincular/eliminar requieren confirmación explícita del navegador. La última asociación no se puede quitar; el sistema avisa y exige eliminar la credencial completa para no dejar registros huérfanos.
- El acceso a datos en tiempo de ejecución utiliza el constructor de consultas de GLPI. Las sentencias estáticas `CREATE TABLE` se ejecutan únicamente en el hook de instalación mediante el patrón documentado `$DB->doQuery()` de GLPI; los permisos de perfil y futuras migraciones incrementales utilizan `Migration::executeMigration()`. La desinstalación elimina únicamente las tablas propias del plugin.
- Las vistas utilizan autoescape de Twig. Solo se trata como HTML confiable el marcado generado por el helper nativo de modales de GLPI.
- La protección CSRF de los POST depende del mecanismo Fetch Metadata / `CheckCsrfListener` de GLPI 12. El plugin no introduce una implementación CSRF paralela.

Respalda juntos la base de datos y el archivo `glpicrypt.key`. Una copia de la base sin la clave correspondiente no podrá descifrar los valores. Mantén el plugin habilitado durante `security:change_key` para que GLPI procese `SECURED_FIELDS`.

## Instalación y actualización

1. Respalda la base de datos y el directorio de configuración de GLPI, incluido `glpicrypt.key`.
2. Instala el directorio `credentials` en la carpeta de plugins de GLPI, o mediante Marketplace cuando exista una publicación pública.
3. Confirma GLPI `12.0.x`, PHP `8.3+` y que el proceso PHP pueda leer un `glpicrypt.key` válido.
4. Instala o actualiza el plugin desde **Configuración → Complementos** y habilítalo.
5. Revisa los permisos `plugin_credentials` y `plugin_credentials_reveal` en los perfiles correspondientes. Concede revelación de contraseñas solo a quienes la necesiten.
6. Prueba con datos de ejemplo no productivos en un usuario, activo compatible y ticket con varios solicitantes/elementos.

Los recursos CSS y JavaScript del plugin se distribuyen bajo `public/css/` y `public/js/`, como espera el cargador de recursos de GLPI 12. No muevas esos archivos a carpetas raíz `css/` o `js/`: los hooks `add_css` y `add_javascript` resuelven sus rutas relativas desde `public/`.

Para actualizar, sustituye los archivos del plugin y ejecuta la acción **Actualizar**. No lo desinstales para actualizar: la desinstalación elimina las tablas propias del plugin.

## Pruebas CLI y compatibilidad de PHP

El flujo de CI ejecuta la revisión de sintaxis PHP y todas las pruebas de regresión mediante CLI con PHP 8.3, 8.4, 8.5 y 8.6. PHP 8.6 es una ejecución obligatoria de compatibilidad y realiza las mismas pruebas CLI no opcionales que las demás versiones de la matriz; esto no cambia la versión mínima declarada para producción ni constituye una certificación de GLPI 12. Para ejecutar localmente la misma batería CLI, coloca primero en `PATH` el binario PHP deseado y ejecuta `bash tools/run-cli-tests.sh 8.6` (o la versión minor correspondiente).

## Estado de desarrollo y publicación

`0.0.27` es una compilación de desarrollo/remediación. Cancelar alta/edición cierra el modal actual (o vuelve a los detalles cuando se edita desde esa vista), mientras que Guardar solicita al padre GLPI una navegación same-origin validada hacia la ficha principal para realizar una actualización completa. Los títulos de los modales reciben iconos Tabler validados mediante un puente same-origin (llave para detalles, más para alta, lápiz para edición y enlace para elementos asociados). Revelar contraseña utiliza un POST HTML interactivo dentro del iframe para que GLPI 12 pueda mostrar y reproducir su reautenticación nativa; la contraseña solo se renderiza después de validar permisos y registrar el acceso, en una respuesta no-cache, dentro de un campo de texto de solo lectura y seleccionable con contraste forzado para temas GLPI. Copiar/ocultar leen y limpian el valor del campo dentro del iframe de detalles. Las operaciones de vincular, desvincular, eliminar y purgar se serializan mediante la API de actualización de base de datos de GLPI; después del bloqueo se vuelve a validar el estado activo, la entidad y los permisos, y el archivado huérfano queda registrado en el historial de la credencial. CI sigue exigiendo PHP 8.3, 8.4, 8.5 y 8.6. Los archivos PHP antiguos de `front/` permanecen únicamente como redirecciones de compatibilidad.

Este paquete no afirma tener aprobación de Marketplace y conserva `<state>development</state>`. Antes de completar el manifiesto público se deben proporcionar URLs reales del repositorio, incidencias, documentación y descarga inmutable de la versión. No se inventan esas direcciones.

Consulta [`HANDOFF.md`](HANDOFF.md) para conocer la arquitectura, las validaciones y las pruebas reales pendientes. La matriz de aceptación previa a una publicación estable está en [`tests/SECURITY_REGRESSION.md`](tests/SECURITY_REGRESSION.md).

## Retención de credenciales archivadas

Cuando GLPI purga permanentemente un usuario o activo vinculado, Credentials elimina esa relación. Si no queda otro elemento asociado, la credencial se mueve a la papelera de GLPI (`is_deleted = 1`) y se registra un evento `HISTORY_ORPHANED` sin secretos en el historial propio de la credencial. Es un **archivo lógico**: los campos cifrados permanecen en la base de datos para permitir recuperación y no se borran criptográficamente de forma automática. El plugin no define un plazo de retención ni ejecuta un borrado criptográfico automático. El administrador debe incluir estos registros y el `glpicrypt.key` correspondiente en la política de retención, respaldo y restauración de la organización.
