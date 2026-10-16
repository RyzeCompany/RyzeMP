# Dimensiones de RyzeMP

RyzeMP mantiene un mundo PocketMine independiente por dimensión. Al iniciar crea
`dimensions.yml` junto a `server.properties`. Los valores predeterminados siguen
usando `world_nether` y `world_the_end`; los mundos existentes no se mueven ni se
convierten. Reinicia el servidor después de cambiar esta configuración.

```yaml
nether:
  enabled: true
  world: world_nether
end:
  enabled: true
  world: world_the_end
  arrival:
    use-world-spawn: false
    x: 100
    y: 49
    z: 0
    create-platform: true
portal:
  load-timeout-ticks: 600
```

Los nombres identifican carpetas dentro de `worlds/`; no son rutas. Nether y End
deben usar carpetas distintas y ninguna puede ser el mundo principal. Una
dimensión desactivada no se carga ni se genera automáticamente. Desactivarla no
borra sus datos; tampoco impide que un plugin cargue su carpeta por separado.

## Llegada al End y mapas personalizados

Activa `end.arrival.use-world-spawn` para usar el spawn guardado en el `level.dat`
del End. En caso contrario se usan las coordenadas configuradas. La llegada busca
un suelo seguro hasta seis bloques horizontalmente y doce verticalmente alrededor
del punto preferido. No cambia bloques si encuentra una superficie segura.

Si solo hay vacío, `create-platform: true` permite colocar un suelo de obsidiana
de 5×5, únicamente cuando toda la zona está vacía. Nunca despeja construcciones
ni reemplaza bloques. Si no existe una llegada segura, cancela el viaje y registra
el motivo. Con `create-platform: false`, tampoco construye sobre el vacío.

Esto **no añade soporte de importación de dimensiones LevelDB nativas**: el lector
actual sigue esperando chunks en las claves de Overworld de cada carpeta. Cambiar
el nombre de una carpeta no convierte los chunks de un Nether o End original.

## Seguridad del viaje

Antes de viajar se puebla toda la zona utilizada para buscar o construir la
llegada. Una solicitud pendiente caduca después de `load-timeout-ticks` ticks del
servidor y se invalida al salir del portal, cambiar de mundo, morir, desconectarse
o recibir un teletransporte aceptado. Una respuesta antigua no puede completar ni
cancelar una solicitud nueva de la misma entidad.

Se conservan la espera de 80 ticks del Nether, la entrada inmediata en creativo,
la escala 8:1 y las reglas de salida del End. Esta revisión no cambia la secuencia
de paquetes del cliente, no añade inmunidad durante la pantalla de carga y no
implementa almacenamiento compartido de dimensiones.

## API de vinculación explícita de portales

Los plugins pueden obtener el registro mediante
`$server->getDimensionManager()->getPortalLinkManager()`. Solo se consulta una
vinculación cuando una entidad toca un bloque real del tipo de portal indicado.
Un portal sin enlace conserva el cálculo 8:1, la búsqueda y la creación actuales.
Nether conserva su espera de 80 ticks (inmediata en creativo); End es inmediato.

Cada `PortalLink` tiene un ID estable, un `PortalArea` y un `PortalDestination`.
El área incluye todos los bloques activos del portal, con esquinas mínimas y
máximas inclusivas. El destino contiene el nombre de carpeta del mundo,
coordenadas exactas de los pies, yaw y pitch. No aplica escala ni suma 0.5.
`PortalDestination::fromLocation()` permite capturar una ubicación existente.

Ejemplo para un plugin HCF; **las áreas son ilustrativas y deben sustituirse por
los extremos reales de cada portal**. Las llegadas pueden quedar delante del
portal, aunque también se permite llegar dentro de un portal con suelo seguro.

```php
use pocketmine\world\dimension\portal\PortalArea;
use pocketmine\world\dimension\portal\PortalDestination;
use pocketmine\world\dimension\portal\PortalLink;
use pocketmine\world\dimension\portal\PortalType;

$links = $this->getServer()->getDimensionManager()->getPortalLinkManager();
$registered = $links->registerBidirectional(
    "hcf:spawn-nether",
    new PortalArea("world", PortalType::NETHER, 10, 78, -90, 11, 80, -90),
    new PortalDestination("world", 10, 78, -90, 180, 0),
    new PortalArea("world_nether", PortalType::NETHER, 0, 46, 0, 1, 48, 0),
    new PortalDestination("world_nether", 0, 46, 0, 0, 0)
);

// IDs: hcf:spawn-nether:forward y hcf:spawn-nether:reverse.
$forward = $links->get("hcf:spawn-nether:forward");
$all = $links->getLinks();

// Al desactivar el plugin, elimina solo sus propios enlaces.
foreach ($registered as $link) {
    $links->unregister($link->id);
}
```

Para un único sentido, usa `register(new PortalLink($id, $area, $destination))`.
`find($worldName, $type, $x, $y, $z)` consulta un bloque del portal. El registro
rechaza IDs duplicados y áreas de origen superpuestas del mismo tipo y mundo.
El registro bidireccional es atómico: si hay un conflicto, no registra la mitad
del par. Para cambiar un enlace, elimínalo y registra una nueva instancia.

Los enlaces pertenecen al plugin: guarda sus datos en su configuración, regístralos
en `onEnable()` y elimínalos en `onDisable()`. El núcleo no duplica esa persistencia
ni necesita un mundo cargado para registrar los nombres y coordenadas. Los mundos
de destino sí deben estar cargados al viajar; la API no los genera ni los carga
automáticamente. Esto permite referencias estables después de descargar y volver
a cargar un mundo con el mismo nombre.

Una vinculación existente tiene prioridad incluso si su destino falla. Si no
está cargado, queda fuera de los límites de la dimensión, tiene bloques alrededor
del cuerpo o no tiene suelo seguro bajo toda la entidad, cancela el viaje y
registra el motivo. No busca otro destino, no construye portales y no cambia
bloques. Asegura un suelo continuo debajo de coordenadas enteras como `0,46,0`,
porque la entidad ocupa espacio a ambos lados del centro. Se conservan los eventos
de teletransporte existentes; un plugin que cambie el destino en esos eventos es
responsable de validar su nuevo destino.

Eliminar o sustituir un enlace invalida las cargas pendientes de esa instancia.
Añadir una vinculación mientras se prepara un viaje automático también cancela
el destino automático antiguo. Después de un viaje vinculado, la entidad debe
permanecer fuera de todos los bloques de portal durante dos ticks antes de activar
otro portal, también cuando los enlaces conectan tipos de portal distintos.

## Referencia y siguientes mejoras

El diseño toma como referencia las validaciones de llegada de `EndArrivalHelper`
y los controles de reentrada de `NetherPortalReentryGuard` de
[RyzeMCBE/Allay](https://github.com/RyzeMCBE/Allay/tree/41074058df2563c2ca1a332f93aa8dda8b417697).
La implementación usa las entidades, eventos, promesas y tareas de generación de
RyzeMP/PocketMine. No incorpora el tick de dimensiones en paralelo de Allay.

Quedan para cambios independientes: protección y confirmación de carga por
cliente Bedrock, lectura de dimensiones nativas en LevelDB, asociación entre
varios Overworlds y sus dimensiones, y búsqueda de portales sin recorrer hasta
289 posiciones de chunks de forma síncrona.

## Pruebas en el servidor

1. Arrancar sin `dimensions.yml` y comprobar que carga los mundos existentes.
2. Entrar y regresar por Nether en supervivencia y creativo; permanecer dentro
   del portal de llegada para comprobar que no devuelve inmediatamente.
3. Entrar por primera vez en una zona sin generar, salir del portal antes de que
   termine la preparación y comprobar que no transporta después.
4. Repetir la carga pendiente usando `/spawn`, muriendo o desconectándose.
5. Entrar al End con una superficie existente y comprobar que no borra bloques.
6. Probar un End vacío con la plataforma activada y desactivada.
7. Configurar un spawn propio del End, activar `use-world-spawn` y comprobar la
   llegada y el regreso al Overworld.
8. Desactivar una dimensión y comprobar que no genera una carpeta nueva ni viaja
   hacia ella; reactivarla sin borrar sus datos.
9. Registrar un enlace del spawn HCF y verificar ambas llegadas exactas y la
   orientación. Un portal no registrado debe conservar el cálculo automático.
10. Bloquear el destino o descargar su mundo: el portal vinculado debe cancelar,
    sin enviar al jugador a otro lugar ni modificar bloques.
11. Eliminar o sustituir el enlace durante una carga pendiente: la solicitud
    anterior no debe transportar después. Al reiniciar, el plugin debe restaurar
    sus enlaces desde su propia configuración.
