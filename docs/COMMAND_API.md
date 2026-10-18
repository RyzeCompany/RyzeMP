# API pública de comandos de RyzeMP

RyzeMP 1.0.0-beta.2 suministra Commando 3.3.0, basado en
`CortexPE/Commando@62db2be0ef4b5d509ee2832de876fdb4da401b6e`, como parte del
servidor. La revisión y los cambios locales figuran en
`lib/commando/RYZEMP-NOTICE.md`; no se descarga nada al arrancar.

Los plugins usan `CortexPE\Commando\BaseCommand`, `BaseSubCommand` y sus
argumentos. No deben empaquetar otra copia de esas clases. `PacketHooker` se
mantiene como entrada compatible, pero no instala un interceptor: los metadatos
salen de `NetworkSession::syncAvailableCommands()` y los tipos ordinarios se
convierten para el protocolo de cada sesión, sin mutar la definición compartida.
No es necesario SimplePacketHandler para esta integración.

La API puede detectarse mediante
`pocketmine\command\CommandMetadata::API_VERSION >= 1`. El número de API PM5
permanece en 5.44.2; la versión de RyzeMP es independiente.

## Ejemplo de plugin

```php
use CortexPE\Commando\BaseCommand;
use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\args\RawStringArgument;
use pocketmine\command\CommandSender;

final class ExampleCommand extends BaseCommand{
    protected function prepare() : void{
        // Declarar este permiso en plugin.yml antes de registrar el comando.
        $this->setPermission("example.command.use");
        $this->registerArgument(0, new RawStringArgument("player"));
        $this->registerArgument(1, new IntegerArgument("amount"));
    }
    public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void{
        // amount ya es int; aplicar aquí las reglas y permisos de la operación.
        $sender->sendMessage($args["player"] . ": " . $args["amount"]);
    }
}

// onEnable(), usando el mapa nativo. Conservar la instancia para retirarla.
$this->command = new ExampleCommand($this, "example", "Example command");
$this->getServer()->getCommandMap()->register("exampleplugin", $this->command);

// onDisable():
$this->getServer()->getCommandMap()->unregister($this->command);
```

`BaseCommand::getRawArguments()` conserva los tokens originales, incluido el
subcomando, para adaptar manejadores existentes sin reconstruir nombres o texto.

Los subcomandos se registran desde `prepare()` con `registerSubCommand()`. Sus
permisos y constraints filtran las sugerencias y se revalidan al ejecutar. Las
restricciones del comando padre se aplican también a los subcomandos. El propietario
deshabilitado no ejecuta ni anuncia comandos. Los comandos tradicionales y su
registro por plugin.yml conservan su funcionamiento y argumento libre opcional.

## Sugerencias dinámicas y extensiones

`BaseArgument::getNetworkParameterDataFor(CommandSender $sender)` permite generar
un `CommandParameter::softEnum()` con los nombres visibles para cada jugador.
El valor puede seguir validándose en `canParse()` y `parse()`, independientemente
de las sugerencias enviadas al cliente. No confiar en el autocompletado como control
de permisos. Evitar nombres de enum compartidos entre listas distintas.

Después de crear/eliminar nombres o cambiar visibilidad, el plugin puede llamar
`CommandMetadata::refresh($server)` desde el hilo principal. Los permisos del propio
jugador siguen disparando la sincronización nativa habitual. Los enums referenciados
por parámetros se incluyen al montar el paquete; no se adjuntan listas globales no
referenciadas de otros comandos.

Una clase `Command` propia también puede implementar `CommandOverloadProvider`
y devolver sus overloads, sin heredar de Commando. Usar constantes del protocolo
actual en la definición; RyzeMP convierte los tipos primitivos por sesión. Una
lista vacía oculta ese comando para el emisor indicado.

Las pruebas automatizadas cubren generación nativa, aliases, restricciones,
parsing y la conversión antigua/moderna. Las sugerencias visuales y la interacción
de clientes Bedrock de diferentes versiones requieren la prueba en juego.
