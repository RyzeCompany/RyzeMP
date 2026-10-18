# Commando integrado en RyzeMP

Fuente: https://github.com/CortexPE/Commando
Revisión: `62db2be0ef4b5d509ee2832de876fdb4da401b6e` (3.3.0).
Licencia: LGPL-3.0; se conserva LICENSE y los avisos del código original.

RyzeMP suministra el namespace público CortexPE\Commando y declara el paquete
como reemplazado en Composer para no instalar una segunda copia.

Cambios locales: generación de metadatos en NetworkSession con conversión por
protocolo; PacketHooker como entrada compatible sin interceptor ni dependencia
SimplePacketHandler; argumentos de red por emisor; bloqueo de plugin deshabilitado;
constraints del padre también en subcomandos; alias de subcomandos sin distinguir
mayúsculas; permisos personalizados; enums literales y enteros sin overflow;
orden estable de alternativas, dejando TextArgument al final; acceso a los tokens
originales para adaptadores que conservan sus manejadores.

Los archivos originales README.md y docs/MIGRATION.md describen la biblioteca
upstream. Para RyzeMP se usa docs/COMMAND_API.md del repositorio del servidor.
