<?php
declare(strict_types=1);

// Run the generated plugin only in a disposable test server: it replaces chunk (0, 0).
$output = $argv[1] ?? null;
if($output === null){
	fwrite(STDERR, "Usage: php -d phar.readonly=0 tests/integration/build-redstone-smoke.php /tmp/test-server/plugins/NativeRedstoneSmoke.phar\n");
	exit(64);
}
$p = new Phar($output);
$p->startBuffering();
$p->setStub('<?php __HALT_COMPILER();');
$p['plugin.yml'] = "name: NativeRedstoneSmoke\nversion: 1.0.0\napi: 5.0.0\nmain: smoke\\NativeRedstoneSmoke\nload: POSTWORLD\n";
$p['src/smoke/NativeRedstoneSmoke.php'] = <<<'PHP'
<?php
namespace smoke;
use pocketmine\plugin\PluginBase;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
final class NativeRedstoneSmoke extends PluginBase{
 protected function onEnable() : void{
  $world = $this->getServer()->getWorldManager()->getDefaultWorld();
  if(!$world instanceof World){ throw new \RuntimeException('No default world'); }
  $world->setChunk(0, 0, new Chunk([], true));
  for($x = 0; $x <= 4; ++$x){ $world->setBlockAt($x, 63, 0, VanillaBlocks::STONE()); }
  $world->setBlockAt(0, 64, 0, VanillaBlocks::LEVER()->setActivated(true));
  for($x = 1; $x <= 3; ++$x){ $world->setBlockAt($x, 64, 0, VanillaBlocks::REDSTONE_WIRE()); }
  $world->setBlockAt(4, 64, 0, VanillaBlocks::REDSTONE_LAMP());
  $tick = $this->getServer()->getTick();
  $world->doTick($tick + 1);
  for($x = 1; $x <= 3; ++$x){
   if($world->getBlockAt($x, 64, 0)->getOutputSignalStrength() !== 16 - $x){ throw new \RuntimeException('Wire activation failed at ' . $x); }
  }
  if(!$world->getBlockAt(4, 64, 0)->isLit()){ throw new \RuntimeException('Lamp did not activate'); }
  $world->setBlockAt(0, 64, 0, VanillaBlocks::AIR());
  $world->doTick($tick + 2);
  for($x = 1; $x <= 3; ++$x){
   if($world->getBlockAt($x, 64, 0)->getOutputSignalStrength() !== 0){ throw new \RuntimeException('Wire remained powered at ' . $x); }
  }
  $world->doTick($tick + 6);
  if($world->getBlockAt(4, 64, 0)->isLit()){ throw new \RuntimeException('Lamp remained powered'); }
  $this->getLogger()->info('NATIVE_REDSTONE_WORLD_SMOKE_OK wire=15,14,13 off=0 lamp=on,off');
  $this->getServer()->shutdown();
 }
}
PHP;
$p->stopBuffering();
