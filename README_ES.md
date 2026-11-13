<p align="center">
  <img src="https://raw.githubusercontent.com/monta990/credentials/main/logo.png" alt="Logotipo de Credentials" width="96">
</p>
<h1 align="center">Credentials</h1>
<p align="center">
  <strong>Plugin para GLPI — Mantén organizadas las credenciales de acceso de tu organización y disponibles donde tu equipo las necesita</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-12.0%2B-blue" alt="Compatibilidad con GLPI"></a>
  <a href="https://www.php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-8.3%2B-purple" alt="Requisito de PHP"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/Licencia-GPL%20v3%2B-green" alt="Licencia"></a>
  <a href="https://github.com/monta990/credentials/releases" target="_blank"><img src="https://img.shields.io/github/downloads/monta990/credentials/total" alt="Descargas de GitHub"></a>
</p>

---

## Descripción general

**Credentials** ayuda al equipo de TI a mantener organizada la información de acceso dentro de GLPI. Permite guardar usuarios, contraseñas, direcciones web y notas para sistemas, servicios y equipos de la organización, y relacionarlos con los usuarios o activos correspondientes.

En lugar de guardar la misma información en distintos lugares, el equipo puede consultarla desde el registro de GLPI relacionado. También es posible compartir una credencial con otros elementos autorizados sin duplicar su información.

## Características principales

- 🔐 **Información protegida:** Guarda usuarios, contraseñas, direcciones web y notas de manera segura.
- 👤 **Relación con usuarios:** Consulta las credenciales asociadas a un usuario de GLPI.
- 🖥️ **Relación con activos:** Mantén la información de acceso junto al equipo o activo correspondiente.
- 🎫 **Consulta desde tickets:** Encuentra las credenciales relacionadas con los solicitantes y los activos de un ticket.
- 🔗 **Reutilización:** Relaciona una credencial existente con otro usuario o activo sin crear duplicados.
- 🔢 **Identificación sencilla:** Cada credencial cuenta con un ID propio para localizarla y compartirla.
- 👁️ **Visualización controlada:** Solo el personal autorizado puede mostrar las contraseñas guardadas.
- 📋 **Copia rápida:** Copia una contraseña cuando sea necesario.
- 🧾 **Historial de actividad:** Conserva un registro de las acciones importantes sin mostrar contraseñas.
- 🏷️ **Categorías:** Organiza las credenciales por sistemas, servicios, redes, servidores u otros grupos definidos por tu equipo.
- 📅 **Vigencia y revisiones:** Registra vencimientos, última y próxima revisión, y la persona responsable.
- 🧭 **Página de administración:** Consulta actualizaciones, actividad, revisiones pendientes y registros archivados.
- 📦 **Respaldos protegidos:** Exporta e importa credenciales mediante un archivo protegido con contraseña.
- 🌍 **Idiomas:** Inglés, español y francés.

## Requisitos

| Componente | Versión |
|-----------|---------|
| GLPI | 12.0.x |
| PHP | 8.3 o superior |

## Instalación

1. Descarga el ZIP de la versión disponible.
2. Extrae el archivo dentro del directorio de plugins de GLPI. La carpeta debe llamarse **credentials**.
3. En GLPI, abre **Configuración → Plugins**.
4. Busca **Credentials**, selecciona **Instalar** y después **Habilitar**.
5. Configura los permisos de los perfiles que utilizarán el plugin.

## Cómo utilizarlo

### Administrar credenciales desde usuarios y activos

Abre un usuario o activo compatible de GLPI y selecciona la pestaña **Credentials**.

Desde ahí, el personal autorizado puede agregar una credencial, consultar sus datos, abrir sus detalles o relacionar una credencial que ya exista.

Cada registro incluye nombre, usuario, contraseña, dirección web y notas. La contraseña es obligatoria al crear una credencial. Al editarla, deja el campo de contraseña vacío para conservar la contraseña actual.

### Consultar credenciales desde un ticket

Abre un ticket y selecciona la pestaña **Credentials**.

El plugin reúne las credenciales relacionadas con los usuarios solicitantes y los activos vinculados al ticket. Así, el personal de soporte puede localizar más fácilmente la información de acceso relacionada con el problema.

Una misma credencial solo se muestra una vez, aunque esté relacionada con más de un elemento del ticket.

### Mostrar y copiar contraseñas

El personal autorizado puede mostrar la contraseña desde los detalles de la credencial. También puede copiarla cuando la necesite y ocultarla nuevamente al terminar.

El permiso para mostrar contraseñas es independiente del permiso general para consultar y administrar credenciales. Esto permite que el administrador decida qué perfiles pueden ver las contraseñas guardadas.

### Relacionar y quitar credenciales

Una credencial existente puede relacionarse con varios usuarios o activos compatibles. Quitar una relación solo desconecta el elemento seleccionado; no elimina la credencial ni sus demás relaciones.

Para evitar registros activos sin elementos relacionados, no se permite quitar la última relación. Cuando una credencial ya no se necesite, debe eliminarse la credencial completa.

## Administración

La página de administración de **Credentials** se encuentra en el área de configuración de plugins de GLPI. Es independiente de las pestañas de usuarios, activos y tickets, por lo que no afecta el trabajo cotidiano del personal de soporte.

Desde esa página, los administradores pueden:

- Consultar si existe una versión estable más reciente en GitHub.
- Crear y administrar categorías.
- Revisar credenciales vencidas o próximas a revisión.
- Consultar la actividad reciente registrada en el historial de GLPI.
- Revisar credenciales archivadas y restaurarlas al vincularlas con un elemento disponible.
- Definir durante cuánto tiempo se conservan los registros archivados antes de que puedan eliminarse definitivamente.
- Consultar si la clave de cifrado de GLPI está disponible.

Las fechas de vencimiento y revisión son recordatorios administrativos. Credentials no cambia ni rota contraseñas automáticamente.

### Recordatorios automáticos por correo

Credentials registra una tarea automática diaria de GLPI para avisar al responsable de revisión sobre vencimientos y revisiones atrasadas o próximas. En **Configuración > Plugins > Credentials > Configuración**, el administrador puede activar o pausar los avisos y elegir una anticipación de 1 a 365 días (30 por defecto). Las fechas atrasadas siempre se incluyen.

Para enviar los correos, también se debe habilitar el sistema global de notificaciones de GLPI y configurar una notificación para el tipo **Credential**, evento **Expiration and review follow-up**, destinatario **Review responsible** y una plantilla por correo. La pantalla de configuración muestra si las notificaciones globales y la tarea automática de Credentials están activas. El responsable asignado debe tener una cuenta activa, acceso a la entidad y un correo predeterminado.

La deduplicación evita repetir diariamente el mismo aviso para la misma credencial, entidad, fecha y responsable. Si el vencimiento y la revisión coinciden, se genera un único aviso. El mensaje no incluye contraseñas ni otros valores protegidos.

## Exportar e importar credenciales

Credentials permite generar un **respaldo protegido con contraseña** que contiene las credenciales y su información relacionada. El archivo se cifra y no expone las contraseñas como texto legible.

Al exportar, elige una contraseña de respaldo de al menos 12 caracteres y guárdala en un lugar seguro. Necesitarás esa misma contraseña para importar el archivo; no es posible recuperarla si la pierdes.

La importación crea registros nuevos y no sobrescribe credenciales existentes. Los usuarios y activos se vuelven a relacionar cuando la instancia GLPI de destino contiene un elemento con el mismo tipo, ID, nombre y entidad. Si no es posible encontrar una coincidencia, la credencial se importa en la papelera de GLPI para no perder su información protegida; después, un administrador puede restaurarla y vincularla con un elemento disponible. Los registros inválidos se omiten. Las contraseñas y los datos protegidos se vuelven a cifrar con la clave de seguridad de la instancia GLPI de destino.

El respaldo puede incluir credenciales archivadas. Después de importarlas, estas permanecen archivadas hasta que un administrador las restaure vinculándolas con un elemento disponible.

Conserva siempre juntos el respaldo de la base de datos de GLPI y el archivo glpicrypt.key correspondiente. La contraseña del archivo de respaldo y la clave de cifrado de GLPI cumplen funciones diferentes; ambas deben protegerse.

## Credenciales archivadas

Cuando un usuario o activo relacionado se elimina permanentemente de GLPI, una credencial que ya no tenga otros elementos relacionados se mueve a la papelera de GLPI. No se elimina inmediatamente.

Los administradores pueden consultar los registros archivados, restaurarlos vinculándolos con un elemento disponible de la misma entidad o eliminar definitivamente los que hayan cumplido el periodo de conservación configurado. La eliminación definitiva no se puede deshacer.

## Permisos

Los administradores pueden controlar el acceso desde los perfiles de GLPI.

El plugin ofrece permisos independientes para:

- **Administrar credenciales:** consultar y administrar registros según los permisos asignados al perfil.
- **Mostrar contraseñas:** revelar las contraseñas guardadas.
- **Exportar e importar:** crear respaldos protegidos y restaurar credenciales desde ellos.

Concede el permiso para mostrar contraseñas y el de respaldos únicamente al personal que realmente los necesite.

## Información importante

- Limita el acceso a Credentials al personal autorizado.
- Conserva un respaldo de la base de datos de GLPI junto con el archivo glpicrypt.key correspondiente. Ambos son necesarios para recuperar la información protegida.
- Una credencial archivada conserva sus datos protegidos hasta que se elimine definitivamente.
- Para actualizar el plugin, utiliza la acción **Actualizar** desde GLPI. No lo desinstales para realizar una actualización, porque la desinstalación elimina los datos del plugin.

## Historial de cambios

Consulta [CHANGELOG.md](CHANGELOG.md) para revisar el historial de versiones.

---

## Autor

Edwin Elias Alvarez — [GitHub](https://github.com/monta990)

## Licencia

GPL v3+ — consulta [LICENSE](LICENSE).

## Soporte

Reporta errores o solicita nuevas funciones en el [rastreador de incidencias de GitHub](https://github.com/monta990/credentials/issues).

---

<p align="center">
  <sub>Credentials — Plugin para GLPI</sub>
</p>
