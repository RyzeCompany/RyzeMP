<?php

declare(strict_types=1);

$destination = $argv[1] ?? throw new InvalidArgumentException("Expected plugin PHAR path");
$phar = new Phar($destination);$phar->startBuffering();$phar->setStub("<?php __HALT_COMPILER();");
$phar->addFromString("plugin.yml", "name: NativeCommandSmoke\nmain: ryzemp\\integration\\NativeCommandSmoke\nversion: 1.0.0\napi: [5.0.0]\ndepend: [HardCoreFactions]\n");
$phar->addFromString("src/ryzemp/integration/NativeCommandSmoke.php", <<<'PLUGIN'
<?php

declare(strict_types=1);

namespace ryzemp\integration;

use CortexPE\Commando\BaseCommand;
use pocketmine\command\CommandMetadata;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use RyzeMCBE\HardCoreFactions\HardCoreFactions;

final class NativeCommandSmoke extends PluginBase{
    protected function onEnable() : void{
        $attempts = 0;$finished = false;
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use (&$attempts, &$finished) : void{
            if($finished){return;}
            try{
                $server=$this->getServer();$core=$server->getPluginManager()->getPlugin("HardCoreFactions");
                if(!$core instanceof HardCoreFactions){throw new \RuntimeException("HCF did not load");}
                if(!$core->isReady()){if(++$attempts>60){throw new \RuntimeException("HCF SQL readiness timed out");}return;}
                $map=$server->getCommandMap();$count=0;
                foreach($core->getDescription()->getCommands() as $name=>$metadata){
                    $command=$map->getCommand("hardcorefactions:".$name);
                    if(!$command instanceof BaseCommand || $command->getOwningPlugin()!==$core){throw new \RuntimeException("Missing native HCF route ".$name);}
                    foreach($metadata->getAliases() as $alias){if($map->getCommand("hardcorefactions:".$alias)!==$command){throw new \RuntimeException("Broken alias ".$alias);}}
                    ++$count;
                }
                if($count!==44 || CommandMetadata::API_VERSION!==1){throw new \RuntimeException("Unexpected command API/root count");}
                $file=(new \ReflectionClass(BaseCommand::class))->getFileName();
                if(!is_string($file)||!str_contains($file,"RyzeMP.phar/lib/commando/")){throw new \RuntimeException("Commando was not provided by the server PHAR");}
                if($map->getCommand("enchant") instanceof BaseCommand){throw new \RuntimeException("Native enchant command was replaced");}
                $sender=new ConsoleCommandSender($server,$server->getLanguage());
                foreach(["f list","rank","listranks"] as $input){if(!$server->dispatchCommand($sender,$input)){throw new \RuntimeException("Console routing failed: ".$input);}}
                $f=$map->getCommand("hardcorefactions:f");$old=CommandMetadata::forProtocol($f,$sender,589);$modern=CommandMetadata::forProtocol($f,$sender,PHP_INT_MAX);
                if($old===[]||$modern===[]){throw new \RuntimeException("Native metadata missing for console-visible subcommands");}
                $this->getLogger()->info("COMMAND_API_HCF_SMOKE_OK roots=".$count." api=".CommandMetadata::API_VERSION);
                $finished=true;$server->shutdown();
            }catch(\Throwable $error){
                $finished=true;$this->getLogger()->critical("COMMAND_API_HCF_SMOKE_FAILED: ".$error->getMessage());$this->getServer()->shutdown();
            }
        }),20);
    }
}
PLUGIN);
$phar->stopBuffering();echo $destination . PHP_EOL;
