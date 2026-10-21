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
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Comparator;
use pocketmine\block\utils\LeverFacing;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
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
  for($x = 6; $x <= 9; ++$x){
   foreach([4, 8, 12] as $z){ $world->setBlockAt($x, 63, $z, VanillaBlocks::STONE()); }
  }
  $world->setBlockAt(7, 64, 4, VanillaBlocks::REDSTONE());
  $world->setBlockAt(8, 64, 4, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST)->setDelay(3));
  $world->setBlockAt(9, 64, 4, VanillaBlocks::REDSTONE_LAMP());
  $world->setBlockAt(7, 63, 8, VanillaBlocks::LEVER()->setFacing(LeverFacing::WEST)->setActivated(true));
  $world->setBlockAt(8, 64, 8, VanillaBlocks::REDSTONE_TORCH());
  $world->setBlockAt(6, 64, 12, VanillaBlocks::CHEST());
  $world->setBlockAt(7, 64, 12, VanillaBlocks::STONE());
  $world->setBlockAt(8, 64, 12, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
  $chest = $world->getTileAt(6, 64, 12);
  if(!$chest instanceof Chest){ throw new \RuntimeException('Chest tile missing'); }
  $chest->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(64));
  $world->doTick($tick + 7);
  $world->doTick($tick + 8);
  if(!$world->getBlockAt(8, 64, 8)->isLit()){ throw new \RuntimeException('Torch changed before its deadline'); }
  $world->doTick($tick + 9);
  if($world->getBlockAt(8, 64, 8)->isLit()){ throw new \RuntimeException('Torch inversion failed'); }
  if($world->getBlockAt(8, 64, 12)->getOutputSignalStrength() !== 1){ throw new \RuntimeException('Container readout failed'); }
  $world->doTick($tick + 12);
  if($world->getBlockAt(8, 64, 4)->isPowered()){ throw new \RuntimeException('Repeater changed before its deadline'); }
  $world->doTick($tick + 13);
  if(!$world->getBlockAt(8, 64, 4)->isPowered() || !$world->getBlockAt(9, 64, 4)->isLit()){ throw new \RuntimeException('Delayed repeater output failed'); }
  $chest->getInventory()->setContents(array_fill(0, 27, VanillaItems::DIAMOND()->setCount(64)));
  $world->doTick($tick + 14);
  $world->doTick($tick + 16);
  $comparator = $world->getTileAt(8, 64, 12);
  if(!$comparator instanceof Comparator || $comparator->getSignalStrength() !== 15 || $world->getBlockAt(8, 64, 12)->getOutputSignalStrength() !== 15){ throw new \RuntimeException('Positive-to-positive analogue change was not persisted'); }
  $world->setBlockAt(6, 64, 12, VanillaBlocks::AIR());
  $world->setBlockAt(7, 63, 8, VanillaBlocks::AIR());
  $world->setBlockAt(7, 64, 4, VanillaBlocks::AIR());
  $world->doTick($tick + 17);
  $world->doTick($tick + 19);
  if($world->getBlockAt(8, 64, 12)->getOutputSignalStrength() !== 0){ throw new \RuntimeException('Removed container left stale comparator output'); }
  if(!$world->getBlockAt(8, 64, 8)->isLit()){ throw new \RuntimeException('Torch failed to relight'); }
  $world->doTick($tick + 23);
  if($world->getBlockAt(8, 64, 4)->isPowered()){ throw new \RuntimeException('Repeater did not deactivate after its delay'); }
  $world->doTick($tick + 27);
  if($world->getBlockAt(9, 64, 4)->isLit()){ throw new \RuntimeException('Repeater-driven lamp remained lit'); }
  $this->getLogger()->info('NATIVE_REDSTONE_TIMING_SMOKE_OK repeater=6gt torch=2gt comparator=1,15,0 tile=15');
  $this->getServer()->shutdown();
 }
}
PHP;
$p->stopBuffering();
