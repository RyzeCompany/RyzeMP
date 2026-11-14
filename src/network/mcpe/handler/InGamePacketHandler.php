<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\block\BaseSign;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\Lectern;
use pocketmine\block\Lodestone;
use pocketmine\block\tile\Crafter as TileCrafter;
use pocketmine\block\tile\Furnace;
use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Mob;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\vehicle\ChestBoat;
use pocketmine\entity\vehicle\MinecartCommandBlock;
use pocketmine\event\player\PlayerEditBookEvent;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionBuilder;
use pocketmine\inventory\transaction\TransactionCancelledException;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Chargeable;
use pocketmine\item\ConsumableItem;
use pocketmine\item\DriedKelp;
use pocketmine\item\HoneyBottle;
use pocketmine\item\VanillaItems;
use pocketmine\item\WritableBook;
use pocketmine\item\WritableBookPage;
use pocketmine\item\WrittenBook;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\FilterNoisyPacketException;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\ActorPickRequestPacket;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\BlockPickRequestPacket;
use pocketmine\network\mcpe\protocol\BookEditPacket;
use pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\CommandRequestPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\LecternUpdatePacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\PlayerHotbarPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\PlayerToggleCrafterSlotRequestPacket;
use pocketmine\network\mcpe\protocol\PositionTrackingDBClientRequestPacket;
use pocketmine\network\mcpe\protocol\PositionTrackingDBServerBroadcastPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\ServerboundLoadingScreenPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\ShowCreditsPacket;
use pocketmine\network\mcpe\protocol\SpawnExperienceOrbPacket;
use pocketmine\network\mcpe\protocol\StructureBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\StructureTemplateDataRequestPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\hud\LoadingScreenType;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\MismatchTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionStopBreak;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionWithBlockInfo;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Limits;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\world\dimension\PortalTravel;
use pocketmine\world\format\Chunk;
use pocketmine\world\map\MapManager;
use pocketmine\world\PositionTrackingDB;
use pocketmine\world\sound\ClickSound;
use pocketmine\world\sound\CrafterDisableSlotSound;
use function array_push;
use function count;
use function fmod;
use function get_debug_type;
use function implode;
use function in_array;
use function is_infinite;
use function is_nan;
use function json_decode;
use function max;
use function mb_strlen;
use function microtime;
use function min;
use function sprintf;
use function str_starts_with;
use function strlen;
use const JSON_THROW_ON_ERROR;

/**
 * This handler handles packets related to general gameplay.
 */
#[SilentDiscard(ActorEventPacket::class, comment: "Not needed")]
#[SilentDiscard(LevelSoundEventPacket::class, comment: "Sounds are always handled server side")]
#[SilentDiscard(MobArmorEquipmentPacket::class, comment: "Not needed")]
#[SilentDiscard(MovePlayerPacket::class, comment: "Not needed, noisy debug when landing on ground")]
#[SilentDiscard(NetworkStackLatencyPacket::class, comment: "Not used, noisy debug")]
#[SilentDiscard(PlayerHotbarPacket::class, comment: "Not needed")]
#[SilentDiscard(SetActorMotionPacket::class, comment: "Not needed, erroneously sent by client when in a vehicle")]
#[SilentDiscard(SpawnExperienceOrbPacket::class, comment: "XP drops should be server-calculated")]
class InGamePacketHandler extends PacketHandler{
	private const MAX_FORM_RESPONSE_SIZE = 10 * 1024; //10 KiB should be more than enough
	private const MAX_FORM_RESPONSE_DEPTH = 2; //modal/simple will be 1, custom forms 2 - they will never contain anything other than string|int|float|bool|null

	//TODO: The client-side per-page character limit is inconsistent for non-ASCII text,
	//allowing input beyond 256 chars. Use a slightly higher bounded soft limit to
	//prevent rejected edits while still mitigating book-bomb attacks
	private const PAGE_LENGTH_SOFT_LIMIT_CHARS = 512;

	protected float $lastRightClickTime = 0.0;
	/** Real time (microtime) the player started using the held item with a right click, null when not using one. */
	private ?float $usingItemSince = null;

	/** Vanilla eating/drinking time in ticks: 32 for most food and drinks. */
	private const CONSUME_TICKS_DEFAULT = 32;
	/**
	 * Share of the eating time that must have passed before the item is consumed: the start and the end of eating
	 * reach the server with some network jitter.
	 */
	private const CONSUME_TIME_TOLERANCE = 0.7;

	/**
	 * Vanilla eating/drinking time of the item in ticks (Bedrock use_duration: dried kelp 16, honey bottle 40).
	 */
	private static function getConsumeTicks(ConsumableItem $item) : int{
		return match(true){
			$item instanceof DriedKelp => 16,
			$item instanceof HoneyBottle => 40,
			default => self::CONSUME_TICKS_DEFAULT
		};
	}

	/**
	 * Whether the player has been eating/drinking the item for long enough to consume it. Real time is used, not
	 * server ticks: a lagging server must not reject players who ate for the full time.
	 */
	private function hasEatenLongEnough(ConsumableItem $item) : bool{
		if($this->usingItemSince === null){
			//started some other way (e.g. by a plugin): fall back to the server's own count
			return $this->player->getItemUseDuration() >= (int) (self::getConsumeTicks($item) * self::CONSUME_TIME_TOLERANCE);
		}
		return microtime(true) - $this->usingItemSince >= self::getConsumeTicks($item) / 20 * self::CONSUME_TIME_TOLERANCE;
	}
	protected ?UseItemTransactionData $lastRightClickData = null;

	protected ?Vector3 $lastPlayerAuthInputPosition = null;
	protected ?float $lastPlayerAuthInputYaw = null;
	protected ?float $lastPlayerAuthInputPitch = null;
	protected ?BitSet $lastPlayerAuthInputFlags = null;

	protected ?BlockPosition $lastBlockAttacked = null;

	public bool $forceMoveSync = false;

	protected ?string $lastRequestedFullSkinId = null;

	public function __construct(
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager
	){}

	public function handleText(TextPacket $packet) : bool{
		if($packet->type === TextPacket::TYPE_CHAT){
			return $this->player->chat($packet->message);
		}

		return false;
	}

	private function resolveOnOffInputFlags(BitSet $inputFlags, int $startFlag, int $stopFlag) : ?bool{
		$enabled = $inputFlags->get($startFlag);
		$disabled = $inputFlags->get($stopFlag);
		if($enabled !== $disabled){
			return $enabled;
		}
		//neither flag was set, or both were set
		return null;
	}

	public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{
		$this->session->setLastPlayerInputTick($packet->getTick());
		$rawPos = $packet->getPosition();
		$rawYaw = $packet->getYaw();
		$rawPitch = $packet->getPitch();
		foreach([$rawPos->x, $rawPos->y, $rawPos->z, $rawYaw, $packet->getHeadYaw(), $rawPitch] as $float){
			if(is_infinite($float) || is_nan($float)){
				$this->session->getLogger()->debug("Invalid movement received, contains NAN/INF components");
				return false;
			}
		}

		if($rawYaw !== $this->lastPlayerAuthInputYaw || $rawPitch !== $this->lastPlayerAuthInputPitch){
			$this->lastPlayerAuthInputYaw = $rawYaw;
			$this->lastPlayerAuthInputPitch = $rawPitch;

			$yaw = fmod($rawYaw, 360);
			$pitch = fmod($rawPitch, 360);
			if($yaw < 0){
				$yaw += 360;
			}

			$this->player->setRotation($yaw, $pitch);
		}

		$hasMoved = $this->lastPlayerAuthInputPosition === null || !$this->lastPlayerAuthInputPosition->equals($rawPos);
		$newPos = $rawPos->subtract(0, 1.62, 0)->round(4);

		if($this->forceMoveSync && $hasMoved){
			$curPos = $this->player->getLocation();

			if($newPos->distanceSquared($curPos) > 1){  //Tolerate up to 1 block to avoid problems with client-sided physics when spawning in blocks
				$this->session->getLogger()->debug("Got outdated pre-teleport movement, received " . $newPos . ", expected " . $curPos);
				//Still getting movements from before teleport, ignore them
				return true;
			}

			// Once we get a movement within a reasonable distance, treat it as a teleport ACK and remove position lock
			$this->forceMoveSync = false;
		}

		$inputFlags = $packet->getInputFlags();
		if($this->lastPlayerAuthInputFlags === null || !$inputFlags->equals($this->lastPlayerAuthInputFlags)){
			$this->lastPlayerAuthInputFlags = $inputFlags;

			$sneakPressed = $inputFlags->get(PlayerAuthInputFlags::SNEAKING);

			$sneaking = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SNEAKING, PlayerAuthInputFlags::STOP_SNEAKING);
			$sprinting = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SPRINTING, PlayerAuthInputFlags::STOP_SPRINTING);
			$swimming = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SWIMMING, PlayerAuthInputFlags::STOP_SWIMMING);
			$gliding = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_GLIDING, PlayerAuthInputFlags::STOP_GLIDING);
			$flying = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_FLYING, PlayerAuthInputFlags::STOP_FLYING);
			$crawling = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_CRAWLING, PlayerAuthInputFlags::STOP_CRAWLING);
			//the client already shows these states: BDS never echoes them back to it (only to the viewers)
			$player = $this->player;
			$flightRejected = false;
			$player->applyClientPredictedState(static function() use ($player, $sneaking, $sneakPressed, $sprinting, $swimming, $gliding, $flying, $crawling, &$flightRejected) : bool{
				$mismatch =
					(!$player->toggleSneak($sneaking ?? $player->isSneaking(), $sneakPressed)) |
					($sprinting !== null && !$player->toggleSprint($sprinting)) |
					($swimming !== null && !$player->toggleSwim($swimming)) |
					($gliding !== null && !$player->toggleGlide($gliding));
				$flightRejected = $flying !== null && !$player->toggleFlight($flying);
				$mismatch |= ($crawling !== null && !$player->toggleCrawl($crawling));
				return !(bool) $mismatch;
			});
			if($flightRejected){
				//flying is an ability, not metadata: a rejected STOP_FLYING (a spectator can't stop flying) or
				//START_FLYING is corrected with the abilities (BDS 1.26.52 ignores it from a spectator)
				$this->session->syncAbilities($player);
			}

			if(($sneaking === true || $inputFlags->get(PlayerAuthInputFlags::SNEAK_PRESSED_RAW)) && $this->player->getVehicle() !== null){
				//sneaking gets off the vehicle (the client may also send InteractPacket LEAVE_VEHICLE); on vehicles with
				//DOES_SERVER_AUTH_ONLY_DISMOUNT (happy ghast) the client stays on until the server unlinks it
				$this->player->dismount(false, true);
			}

			if($inputFlags->get(PlayerAuthInputFlags::START_JUMPING) && $this->player->getVehicle() === null){
				$this->player->jump();
			}
			if($inputFlags->get(PlayerAuthInputFlags::MISSED_SWING)){
				$this->player->missSwing();
			}
			//the spin attack itself starts when a Riptide trident is released (Trident::onReleaseUsing() validates
			//it); START_SPIN_ATTACK alone is not trusted, but the client ends its spin early when it hits something
			if($inputFlags->get(PlayerAuthInputFlags::STOP_SPIN_ATTACK)){
				$this->player->stopSpinAttack(true);
			}
		}

		$vehicle = $this->player->getVehicle();
		if($vehicle !== null){
			//a riding player is placed on its seat by the server
			$this->handleRidingInput($packet, $vehicle, $rawPos);
		}elseif(!$this->forceMoveSync && $hasMoved){
			$this->lastPlayerAuthInputPosition = $rawPos;
			//TODO: this packet has WAYYYYY more useful information that we're not using
			$this->player->handleMovement($newPos);
		}

		$packetHandled = true;

		$useItemTransaction = $packet->getItemInteractionData();
		$breakPredicted = false;
		if($useItemTransaction !== null){
			if(count($useItemTransaction->getTransactionData()->getActions()) > 100){
				throw new PacketHandlingException("Too many actions in item use transaction");
			}

			$this->inventoryManager->setCurrentItemStackRequestId($useItemTransaction->getRequestId());
			$this->inventoryManager->addRawPredictedSlotChanges($useItemTransaction->getTransactionData()->getActions());
			if($useItemTransaction->getTransactionData()->getActionType() === UseItemTransactionData::ACTION_BREAK_BLOCK){
				//the client adds this to the tick it predicts a block destroyed; the block actions below break it, so
				//its predictions (tool damage) are checked after them
				$breakPredicted = true;
			}elseif(!$this->handleUseItemTransaction($useItemTransaction->getTransactionData())){
				$packetHandled = false;
				$this->session->getLogger()->debug("Unhandled transaction in PlayerAuthInputPacket (type " . $useItemTransaction->getTransactionData()->getActionType() . ")");
			}else{
				$this->inventoryManager->syncMismatchedPredictedSlotChanges();
			}
			$this->inventoryManager->setCurrentItemStackRequestId(null);
		}

		$itemStackRequest = $packet->getItemStackRequest();
		$itemStackResponseBuilder = $itemStackRequest !== null ? $this->handleSingleItemStackRequest($itemStackRequest) : null;

		//itemstack request or transaction may set predictions for the outcome of these actions, so these need to be
		//processed last
		$blockActions = $packet->getBlockActions();
		if($blockActions !== null){
			if(count($blockActions) > 100){
				throw new PacketHandlingException("Too many block actions in PlayerAuthInputPacket");
			}
			foreach(Utils::promoteKeys($blockActions) as $k => $blockAction){
				$actionHandled = false;
				if($blockAction instanceof PlayerBlockActionStopBreak){
					$actionHandled = $this->handlePlayerActionFromData($blockAction->getActionType(), new BlockPosition(0, 0, 0), Facing::DOWN);
				}elseif($blockAction instanceof PlayerBlockActionWithBlockInfo){
					$actionHandled = $this->handlePlayerActionFromData($blockAction->getActionType(), $blockAction->getBlockPosition(), $blockAction->getFace());
				}

				if(!$actionHandled){
					$packetHandled = false;
					$this->session->getLogger()->debug("Unhandled player block action at offset $k in PlayerAuthInputPacket");
				}
			}
		}
		if($breakPredicted){
			$this->inventoryManager->syncMismatchedPredictedSlotChanges();
		}

		if($itemStackRequest !== null){
			$itemStackResponse = $itemStackResponseBuilder?->build() ?? new ItemStackResponse(ItemStackResponse::RESULT_ERROR, $itemStackRequest->getRequestId());
			$this->session->sendDataPacket(ItemStackResponsePacket::create([$itemStackResponse]));
		}

		return $packetHandled;
	}

	public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{
		$result = true;

		if(count($packet->trData->getActions()) > 50){
			throw new PacketHandlingException("Too many actions in inventory transaction");
		}
		if($packet->requestChangedSlots !== null && count($packet->requestChangedSlots) > 10){
			throw new PacketHandlingException("Too many slot sync requests in inventory transaction");
		}

		$this->inventoryManager->setCurrentItemStackRequestId($packet->requestId);
		$this->inventoryManager->addRawPredictedSlotChanges($packet->trData->getActions());

		if($packet->trData instanceof NormalTransactionData){
			$result = $this->handleNormalTransaction($packet->trData, $packet->requestId);
		}elseif($packet->trData instanceof MismatchTransactionData){
			$this->session->getLogger()->debug("Mismatch transaction received");
			$this->inventoryManager->requestSyncAll();
			$result = true;
		}elseif($packet->trData instanceof UseItemTransactionData){
			$result = $this->handleUseItemTransaction($packet->trData);
		}elseif($packet->trData instanceof UseItemOnEntityTransactionData){
			$result = $this->handleUseItemOnEntityTransaction($packet->trData);
		}elseif($packet->trData instanceof ReleaseItemTransactionData){
			$result = $this->handleReleaseItemTransaction($packet->trData);
		}

		$this->inventoryManager->syncMismatchedPredictedSlotChanges();

		//requestChangedSlots asks the server to always send out the contents of the specified slots, even if they
		//haven't changed. Handling these is necessary to ensure the client inventory stays in sync if the server
		//rejects the transaction. The most common example of this is equipping armor by right-click, which doesn't send
		//a legacy prediction action for the destination armor slot.
		if($packet->requestChangedSlots !== null){
			foreach($packet->requestChangedSlots as $containerInfo){
				foreach($containerInfo->getChangedSlotIndexes() as $netSlot){
					[$windowId, $slot] = ItemStackContainerIdTranslator::translate($containerInfo->getContainerId(), $this->inventoryManager->getCurrentWindowId(), $netSlot);
					$inventoryAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slot);
					if($inventoryAndSlot !== null){ //trigger the normal slot sync logic
						$this->inventoryManager->onSlotChange($inventoryAndSlot[0], $inventoryAndSlot[1]);
					}
				}
			}
		}

		$this->inventoryManager->setCurrentItemStackRequestId(null);
		return $result;
	}

	private function executeInventoryTransaction(InventoryTransaction $transaction, int $requestId) : bool{
		$this->player->setUsingItem(false);

		$this->inventoryManager->setCurrentItemStackRequestId($requestId);
		$this->inventoryManager->addTransactionPredictedSlotChanges($transaction);
		try{
			$transaction->execute();
			//bundle contents changed through their dynamic containers are stored back in the bundle items
			$this->inventoryManager->flushBundleWritebacks();
		}catch(TransactionValidationException $e){
			$this->inventoryManager->requestSyncAll();
			$logger = $this->session->getLogger();
			$logger->debug("Invalid inventory transaction $requestId: " . $e->getMessage());

			return false;
		}catch(TransactionCancelledException){
			$this->session->getLogger()->debug("Inventory transaction $requestId cancelled by a plugin");

			return false;
		}finally{
			$this->inventoryManager->syncMismatchedPredictedSlotChanges();
			$this->inventoryManager->setCurrentItemStackRequestId(null);
		}
		$this->payOutFurnaceExperience($transaction);

		return true;
	}

	/**
	 * A player taking items out of the result slot of a furnace, blast furnace or smoker gets the experience the
	 * furnace stored by smelting, as orbs at the player (hoppers taking the result leave it stored).
	 */
	private function payOutFurnaceExperience(InventoryTransaction $transaction) : void{
		foreach($transaction->getActions() as $action){
			if(
				!$action instanceof SlotChangeAction ||
				$action->getSlot() !== FurnaceInventory::SLOT_RESULT ||
				$action->getTargetItem()->getCount() >= $action->getSourceItem()->getCount()
			){
				continue;
			}
			$inventory = $action->getInventory();
			if(!$inventory instanceof FurnaceInventory){
				continue;
			}
			$holder = $inventory->getHolder();
			$furnace = $holder->getWorld()->getTile($holder);
			if($furnace instanceof Furnace){
				$xp = $furnace->takeStoredExperience();
				if($xp > 0){
					$this->player->getWorld()->dropExperience($this->player->getPosition(), $xp);
				}
			}
		}
	}

	private function handleNormalTransaction(NormalTransactionData $data, int $itemStackRequestId) : bool{
		//When the ItemStackRequest system is used, this transaction type is used for dropping items by pressing Q.
		//I don't know why they don't just use ItemStackRequest for that too, which already supports dropping items by
		//clicking them outside an open inventory menu, but for now it is what it is.
		//Fortunately, this means we can be much stricter about the validation criteria.

		$actionCount = count($data->getActions());
		if($actionCount > 2){
			if($actionCount > 5){
				throw new PacketHandlingException("Too many actions ($actionCount) in normal inventory transaction");
			}

			//Due to a bug in the game, this transaction type is still sent when a player edits a book. We don't need
			//these transactions for editing books, since we have BookEditPacket, so we can just ignore them.
			$this->session->getLogger()->debug("Ignoring normal inventory transaction with $actionCount actions (drop-item should have exactly 2 actions)");
			return false;
		}

		$sourceSlot = null;
		$clientItemStack = null;
		$droppedCount = null;

		foreach($data->getActions() as $networkInventoryAction){
			if($networkInventoryAction->sourceType === NetworkInventoryAction::SOURCE_WORLD && $networkInventoryAction->inventorySlot === NetworkInventoryAction::ACTION_MAGIC_SLOT_DROP_ITEM){
				$droppedCount = $networkInventoryAction->newItem->getItemStack()->getCount();
				if($droppedCount <= 0){
					throw new PacketHandlingException("Expected positive count for dropped item");
				}
			}elseif($networkInventoryAction->sourceType === NetworkInventoryAction::SOURCE_CONTAINER && $networkInventoryAction->windowId === ContainerIds::INVENTORY){
				//mobile players can drop an item from a non-selected hotbar slot
				$sourceSlot = $networkInventoryAction->inventorySlot;
				$clientItemStack = $networkInventoryAction->oldItem->getItemStack();
			}else{
				$this->session->getLogger()->debug("Unexpected inventory action type $networkInventoryAction->sourceType in drop item transaction");
				return false;
			}
		}
		if($sourceSlot === null || $clientItemStack === null || $droppedCount === null){
			$this->session->getLogger()->debug("Missing information in drop item transaction, need source slot, client item stack and dropped count");
			return false;
		}

		$inventory = $this->player->getInventory();

		if(!$inventory->slotExists($sourceSlot)){
			return false; //TODO: size desync??
		}

		$sourceSlotItem = $inventory->getItem($sourceSlot);
		if($sourceSlotItem->getCount() < $droppedCount){
			return false;
		}
		$serverItemStack = $this->session->getTypeConverter()->coreItemStackToNet($sourceSlotItem);
		//Sadly we don't have itemstack IDs here, so we have to compare the basic item properties to ensure that we're
		//dropping the item the client expects (inventory might be out of sync with the client).
		if(
			$serverItemStack->getId() !== $clientItemStack->getId() ||
			$serverItemStack->getMeta() !== $clientItemStack->getMeta() ||
			$serverItemStack->getCount() !== $clientItemStack->getCount() ||
			$serverItemStack->getBlockRuntimeId() !== $clientItemStack->getBlockRuntimeId()
			//Raw extraData may not match because of TAG_Compound key ordering differences, and decoding it to compare
			//is costly. Assume that we're in sync if id+meta+count+runtimeId match.
			//NB: Make sure $clientItemStack isn't used to create the dropped item, as that would allow the client
			//to change the item NBT since we're not validating it.
		){
			return false;
		}

		//this modifies $sourceSlotItem
		$droppedItem = $sourceSlotItem->pop($droppedCount);

		$builder = new TransactionBuilder();
		$builder->getInventory($inventory)->setItem($sourceSlot, $sourceSlotItem);
		$builder->addAction(new DropItemAction($droppedItem));

		$transaction = new InventoryTransaction($this->player, $builder->generateActions());
		return $this->executeInventoryTransaction($transaction, $itemStackRequestId);
	}

	private function handleUseItemTransaction(UseItemTransactionData $data) : bool{
		$this->player->selectHotbarSlot($data->getHotbarSlot());

		switch($data->getActionType()){
			case UseItemTransactionData::ACTION_CLICK_BLOCK:
				//TODO: start hack for client spam bug
				$clickPos = $data->getClickPosition();
				$spamBug = ($this->lastRightClickData !== null &&
					microtime(true) - $this->lastRightClickTime < 0.1 && //100ms
					$this->lastRightClickData->getFace() === $data->getFace() &&
					$this->lastRightClickData->getPlayerPosition()->distanceSquared($data->getPlayerPosition()) < 0.00001 &&
					$this->lastRightClickData->getBlockPosition()->equals($data->getBlockPosition()) &&
					$this->lastRightClickData->getClickPosition()->distanceSquared($clickPos) < 0.00001 //signature spam bug has 0 distance, but allow some error
				);
				//get rid of continued spam if the player clicks and holds right-click
				$this->lastRightClickData = $data;
				$this->lastRightClickTime = microtime(true);
				if($spamBug){
					throw new FilterNoisyPacketException();
				}
				//TODO: end hack for client spam bug

				self::validateFacing($data->getFace());

				$blockPos = $data->getBlockPosition();
				$vBlockPos = new Vector3($blockPos->getX(), $blockPos->getY(), $blockPos->getZ());
				$this->player->interactBlock($vBlockPos, $data->getFace(), $clickPos);
				if($data->getClientInteractPrediction() === PredictedResult::SUCCESS){
					//If the item has an associated blockstate ID, this means it will only place one block.
					//We can avoid syncing the adjacent blocks of the place position in this case, since that's only
					//necessary if there might be multiple blocks around the placement location affected.
					//Adjacents of the clicked block are still always synced, since it's too complicated to figure out
					//if the client might've predicted something in this case. However, since the clicked block is always
					//"behind" the placed block, this shouldn't affect bridging or fast placement.
					//This would be much easier if the client would just tell us which blocks it thinks changed...
					$syncAdjacentFace = null;
					if($data->getItemInHand()->getItemStack()->getBlockRuntimeId() === ItemTranslator::NO_BLOCK_RUNTIME_ID){
						$this->session->getLogger()->debug("Placing held item might place multiple blocks client-side; doing full adjacent sync");
						$syncAdjacentFace = $data->getFace();
					}
					$this->syncBlocksNearby($vBlockPos, $syncAdjacentFace);
				}
				return true;
			case UseItemTransactionData::ACTION_CLICK_AIR:
				if($this->player->isUsingItem()){
					$held = $this->player->getInventory()->getItemInHand();
					if($held instanceof ConsumableItem && !$held instanceof Chargeable && !$this->hasEatenLongEnough($held)){
						//a use before the eating/drinking time is up (spam clicks): vanilla keeps eating, nothing is
						//consumed; the client may have predicted the item and hunger, so both are synced back
						$hungerAttr = $this->player->getAttributeMap()->get(Attribute::HUNGER) ?? throw new AssumptionFailedError();
						$hungerAttr->markSynchronized(false);
						$this->inventoryManager->syncContents($this->player->getInventory());
						return true;
					}
					if($held instanceof Chargeable){
						//while charging, a use means the client's charge bar is full
						$this->player->completeChargingHeldItem();
					}elseif(!$this->player->consumeHeldItem()){
						$hungerAttr = $this->player->getAttributeMap()->get(Attribute::HUNGER) ?? throw new AssumptionFailedError();
						$hungerAttr->markSynchronized(false);
					}
					//TODO: workaround goat horns getting stuck in the "using item" state
					//this timed-trigger behaviour is also used for other items apart from food
					//in the future we'll generalise this logic and add proper hooks for it
					$this->player->setUsingItem(false);
					$this->usingItemSince = null;
					return true;
				}
				$this->player->useHeldItem();
				$this->usingItemSince = $this->player->isUsingItem() ? microtime(true) : null;
				return true;
		}

		return false;
	}

	/**
	 * @throws PacketHandlingException
	 */
	private static function validateFacing(int $facing) : void{
		if(!in_array($facing, Facing::ALL, true)){
			throw new PacketHandlingException("Invalid facing value $facing");
		}
	}

	/**
	 * Syncs blocks nearby to ensure that the client and server agree on the world's blocks after a block interaction.
	 */
	private function syncBlocksNearby(Vector3 $blockPos, ?int $face) : void{
		if($blockPos->distanceSquared($this->player->getLocation()) < 10000){
			$blocks = $blockPos->sidesArray();
			if($face !== null){
				$sidePos = $blockPos->getSide($face);

				/** @var Vector3[] $blocks */
				array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
			}else{
				$blocks[] = $blockPos;
			}
			foreach($this->player->getWorld()->createBlockUpdatePackets($blocks) as $packet){
				$this->session->sendDataPacket($packet);
			}
		}
	}

	/**
	 * Input of a riding player. Its own position is ignored (it sits on its seat). If it controls the vehicle, the
	 * vehicle gets its input; when the client predicts the vehicle (horses, camels... in server-authoritative movement),
	 * the packet position is the vehicle's and comes with the vehicle's rotation and ID.
	 */
	private function handleRidingInput(PlayerAuthInputPacket $packet, Entity $vehicle, Vector3 $rawPos) : void{
		if($vehicle->getControllingPassenger() !== $this->player){
			return;
		}
		$flags = $packet->getInputFlags();
		$location = $this->player->getLocation();
		$moveX = $packet->getMoveVecX();
		$moveZ = $packet->getMoveVecZ();
		if(is_nan($moveX) || is_infinite($moveX) || is_nan($moveZ) || is_infinite($moveZ)){
			$moveX = $moveZ = 0.0;
		}
		$input = new RiderInput(
			max(-1.0, min(1.0, $moveX)),
			max(-1.0, min(1.0, $moveZ)),
			$location->yaw,
			$location->pitch,
			$flags->get(PlayerAuthInputFlags::JUMPING),
			$flags->get(PlayerAuthInputFlags::SNEAKING),
			$flags->get(PlayerAuthInputFlags::SPRINTING),
			$flags->get(PlayerAuthInputFlags::PADDLING_LEFT),
			$flags->get(PlayerAuthInputFlags::PADDLING_RIGHT)
		);

		$vehicleInfo = $packet->getVehicleInfo();
		if($vehicleInfo !== null && $vehicleInfo->getPredictedVehicleActorUniqueId() === $vehicle->getId()){
			$yaw = $vehicleInfo->getVehicleRotationZ();
			$pitch = $vehicleInfo->getVehicleRotationX();
			if(is_nan($yaw) || is_infinite($yaw) || is_nan($pitch) || is_infinite($pitch)){
				$yaw = $location->yaw;
				$pitch = 0.0;
			}
			$yaw = fmod($yaw, 360);
			if($yaw < 0){
				$yaw += 360;
			}
			$vehiclePos = $rawPos->subtractVector($vehicle->getOffsetPosition(Vector3::zero()));
			if(!$vehicle->handleClientVehicleMovement($this->player, $vehiclePos, $yaw, fmod($pitch, 360)) && ($vehicle instanceof Mob || $vehicle instanceof Boat)){
				$vehicle->resyncClientVehicle($this->player);
			}
		}
		$vehicle->handleRiderInput($this->player, $input);
	}

	private function handleUseItemOnEntityTransaction(UseItemOnEntityTransactionData $data) : bool{
		$target = $this->player->getWorld()->getEntity($data->getActorRuntimeId());
		//TODO: HACK! We really shouldn't be keeping disconnected players (and generally flagged-for-despawn entities)
		//in the world's entity table, but changing that is too risky for a hotfix. This workaround will do for now.
		if($target === null || $target->isFlaggedForDespawn()){
			return false;
		}

		$this->player->selectHotbarSlot($data->getHotbarSlot());

		switch($data->getActionType()){
			case UseItemOnEntityTransactionData::ACTION_INTERACT:
				$this->player->interactEntity($target, $data->getClickPosition());
				return true;
			case UseItemOnEntityTransactionData::ACTION_ATTACK:
				$this->player->attackEntity($target);
				return true;
		}

		return false;
	}

	private function handleReleaseItemTransaction(ReleaseItemTransactionData $data) : bool{
		$this->player->selectHotbarSlot($data->getHotbarSlot());

		if($data->getActionType() === ReleaseItemTransactionData::ACTION_RELEASE){
			$this->player->releaseHeldItem();
			return true;
		}

		return false;
	}

	private function handleSingleItemStackRequest(ItemStackRequest $request) : ?ItemStackResponseBuilder{
		if(count($request->getActions()) > 60){
			//recipe book auto crafting can affect all slots of the inventory when consuming inputs or producing outputs
			//this means there could be as many as 50 CraftingConsumeInput actions or Place (taking the result) actions
			//in a single request (there are certain ways items can be arranged which will result in the same stack
			//being taken from multiple times, but this is behaviour with a calculable limit)
			//this means there SHOULD be AT MOST 53 actions in a single request, but 60 is a nice round number.
			//n64Stacks = ?
			//n1Stacks = 45 - n64Stacks
			//nItemsRequiredFor1Craft = 9
			//nResults = floor((n1Stacks + (n64Stacks * 64)) / nItemsRequiredFor1Craft)
			//nTakeActionsTotal = floor(64 / nResults) + max(1, 64 % nResults) + ((nResults * nItemsRequiredFor1Craft) - (n64Stacks * 64))
			throw new PacketHandlingException("Too many actions in ItemStackRequest");
		}
		$executor = new ItemStackRequestExecutor($this->player, $this->inventoryManager, $request);
		try{
			$transaction = $executor->generateInventoryTransaction();
			if($transaction !== null){
				$result = $this->executeInventoryTransaction($transaction, $request->getRequestId());
			}else{
				$result = true; //predictions only, just send responses
			}
		}catch(ItemStackRequestProcessException $e){
			$result = false;
			$this->session->getLogger()->debug("ItemStackRequest #" . $request->getRequestId() . " failed: " . $e->getMessage());
			$this->session->getLogger()->debug(implode("\n", Utils::printableExceptionInfo($e)));
			$this->inventoryManager->requestSyncAll();
		}

		return $result ? $executor->getItemStackResponseBuilder() : null;
	}

	public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{
		$responses = [];
		if(count($packet->getRequests()) > 80){
			//TODO: we can probably lower this limit, but this will do for now
			throw new PacketHandlingException("Too many requests in ItemStackRequestPacket");
		}
		foreach($packet->getRequests() as $request){
			$responses[] = $this->handleSingleItemStackRequest($request)?->build() ?? new ItemStackResponse(ItemStackResponse::RESULT_ERROR, $request->getRequestId());
		}

		$this->session->sendDataPacket(ItemStackResponsePacket::create($responses));

		return true;
	}

	public function handleMobEquipment(MobEquipmentPacket $packet) : bool{
		if($packet->windowId === ContainerIds::OFFHAND){
			return true; //this happens when we put an item into the offhand
		}
		if($packet->windowId === ContainerIds::INVENTORY){
			$this->inventoryManager->onClientSelectHotbarSlot($packet->hotbarSlot);
			if(!$this->player->selectHotbarSlot($packet->hotbarSlot)){
				$this->inventoryManager->syncSelectedHotbarSlot();
			}
			return true;
		}
		return false;
	}

	public function handleInteract(InteractPacket $packet) : bool{
		if($packet->action === InteractPacket::ACTION_MOUSEOVER){
			//sent when the crosshair (or touch) points at an entity, also when the held item or metadata change: touch
			//controls show the interaction button text of the pointed entity (target 0: none), e.g. "Ride"
			$target = $packet->targetActorRuntimeId !== 0 ? $this->player->getWorld()->getEntity($packet->targetActorRuntimeId) : null;
			$this->player->setInteractiveTag($target !== null && $target !== $this->player ? $target->getInteractButtonText($this->player) : "");
			return true;
		}
		if($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE){
			//sneaking while riding (the client gets off by itself; if the server refuses, it is put back on)
			$this->player->dismount(false, true);
			return true;
		}
		$target = $this->player->getWorld()->getEntity($packet->targetActorRuntimeId);
		if($target === null){
			return false;
		}
		if($packet->action === InteractPacket::ACTION_OPEN_INVENTORY){
			//the inventory button of a rider opens the screen of a horse, donkey, llama... it rides (Java
			//OPEN_VEHICLE_INVENTORY, translated the same way by GeyserMC)
			$vehicle = $this->player->getVehicle();
			if(($target === $this->player || $target === $vehicle) && $vehicle instanceof MountInventoryHolder && $vehicle->openInventory($this->player)){
				return true;
			}
			//the inventory button of a player in a boat with chest opens the chest
			if(($target === $this->player || $target === $vehicle) && $vehicle instanceof ChestBoat && $vehicle->openInventory($this->player)){
				return true;
			}
			if($target === $this->player){
				$this->inventoryManager->onClientOpenMainInventory();
				return true;
			}
		}
		return false; //TODO
	}

	public function handleBlockPickRequest(BlockPickRequestPacket $packet) : bool{
		return $this->player->pickBlock(new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ()), $packet->addUserData);
	}

	public function handleActorPickRequest(ActorPickRequestPacket $packet) : bool{
		return $this->player->pickEntity($packet->actorUniqueId);
	}

	public function handlePlayerAction(PlayerActionPacket $packet) : bool{
		return $this->handlePlayerActionFromData($packet->action, $packet->blockPosition, $packet->face);
	}

	private function handlePlayerActionFromData(int $action, BlockPosition $blockPosition, int $face) : bool{
		$pos = new Vector3($blockPosition->getX(), $blockPosition->getY(), $blockPosition->getZ());

		switch($action){
			case PlayerAction::START_BREAK:
			case PlayerAction::CONTINUE_DESTROY_BLOCK: //destroy the next block while holding down left click
				self::validateFacing($face);
				if($this->lastBlockAttacked !== null && $blockPosition->equals($this->lastBlockAttacked)){
					//the client will send CONTINUE_DESTROY_BLOCK for the currently targeted block directly before it
					//sends PREDICT_DESTROY_BLOCK, but also when it starts to break the block
					//this seems like a bug in the client and would cause spurious left-click events if we allowed it to
					//be delivered to the player
					$this->session->getLogger()->debug("Ignoring PlayerAction $action on $pos because we were already destroying this block");
					break;
				}
				if(!$this->player->attackBlock($pos, $face)){
					$this->syncBlocksNearby($pos, $face);
				}
				$this->lastBlockAttacked = $blockPosition;

				break;

			case PlayerAction::ABORT_BREAK:
			case PlayerAction::STOP_BREAK:
				$this->player->stopBreakBlock($pos);
				$this->lastBlockAttacked = null;
				break;
			case PlayerAction::START_SLEEPING:
				//unused
				break;
			case PlayerAction::STOP_SLEEPING:
				$this->player->stopSleep();
				break;
			case PlayerAction::CRACK_BREAK:
				self::validateFacing($face);
				$this->player->continueBreakBlock($pos, $face);
				$this->lastBlockAttacked = $blockPosition;
				break;
			case PlayerAction::INTERACT_BLOCK: //TODO: ignored (for now)
				break;
			case PlayerAction::CREATIVE_PLAYER_DESTROY_BLOCK:
				//in server auth block breaking, we get PREDICT_DESTROY_BLOCK anyway, so this action is redundant
				break;
			case PlayerAction::PREDICT_DESTROY_BLOCK:
				self::validateFacing($face);
				if(!$this->player->breakBlock($pos)){
					$this->syncBlocksNearby($pos, $face);
				}
				$this->lastBlockAttacked = null;
				break;
			case PlayerAction::START_ITEM_USE_ON:
			case PlayerAction::STOP_ITEM_USE_ON:
				//TODO: this has no obvious use and seems only used for analytics in vanilla - ignore it
				break;
			case PlayerAction::DIMENSION_CHANGE_ACK:
				//the client answers the server's ack once it has spawned in the new dimension
				$this->session->onClientDimensionChangeDone(null);
				return true;
			default:
				$this->session->getLogger()->debug("Unhandled/unknown player action type " . $action);
				return false;
		}

		$this->player->setUsingItem(false);

		return true;
	}

	public function handleServerboundLoadingScreen(ServerboundLoadingScreenPacket $packet) : bool{
		if($packet->getLoadingScreenType() === LoadingScreenType::STOP_LOADING_SCREEN && $packet->getLoadingScreenId() !== null){
			$this->session->onClientDimensionChangeDone($packet->getLoadingScreenId());
		}
		return true;
	}

	public function handleAnimate(AnimatePacket $packet) : bool{
		//this spams harder than a firehose on left click if "Improved Input Response" is enabled, and we don't even
		//use it anyway :<
		throw new FilterNoisyPacketException();
	}

	public function handleContainerClose(ContainerClosePacket $packet) : bool{
		$this->inventoryManager->onClientRemoveWindow($packet->windowId);
		return true;
	}

	/**
	 * @throws PacketHandlingException
	 */
	private function updateSignText(CompoundTag $nbt, string $tagName, bool $frontFace, BaseSign $block, Vector3 $pos) : bool{
		$textTag = $nbt->getTag($tagName);
		if(!$textTag instanceof CompoundTag){
			throw new PacketHandlingException("Invalid tag type " . get_debug_type($textTag) . " for tag \"$tagName\" in sign update data");
		}
		$textBlobTag = $textTag->getTag(Sign::TAG_TEXT_BLOB);
		if(!$textBlobTag instanceof StringTag){
			throw new PacketHandlingException("Invalid tag type " . get_debug_type($textBlobTag) . " for tag \"" . Sign::TAG_TEXT_BLOB . "\" in sign update data");
		}

		try{
			$text = SignText::fromBlob($textBlobTag->getValue());
		}catch(\InvalidArgumentException $e){
			throw PacketHandlingException::wrap($e, "Invalid sign text update");
		}

		$oldText = $block->getFaceText($frontFace);
		if($text->getLines() === $oldText->getLines()){
			return false;
		}

		try{
			if(!$block->updateFaceText($this->player, $frontFace, $text)){
				foreach($this->player->getWorld()->createBlockUpdatePackets([$pos]) as $updatePacket){
					$this->session->sendDataPacket($updatePacket);
				}
				return false;
			}
			return true;
		}catch(\UnexpectedValueException $e){
			throw PacketHandlingException::wrap($e);
		}
	}

	public function handleBlockActorData(BlockActorDataPacket $packet) : bool{
		$pos = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
		if($pos->distanceSquared($this->player->getLocation()) > 10000){
			return false;
		}

		$block = $this->player->getLocation()->getWorld()->getBlock($pos);
		$nbt = $packet->nbt->getRoot();
		if(!($nbt instanceof CompoundTag)) throw new AssumptionFailedError("PHPStan should ensure this is a CompoundTag"); //for phpstorm's benefit

		if($block instanceof BaseSign){
			if(!$this->updateSignText($nbt, Sign::TAG_FRONT_TEXT, true, $block, $pos)){
				//only one side can be updated at a time
				$this->updateSignText($nbt, Sign::TAG_BACK_TEXT, false, $block, $pos);
			}

			return true;
		}

		return StructureEditorRequests::handleJigsawData($this->session, $this->player, $pos, $nbt);
	}

	public function handleStructureBlockUpdate(StructureBlockUpdatePacket $packet) : bool{
		StructureEditorRequests::handleStructureBlockUpdate($this->session, $this->player, $packet);
		return true;
	}

	public function handleStructureTemplateDataRequest(StructureTemplateDataRequestPacket $packet) : bool{
		StructureEditorRequests::handleStructureTemplateDataRequest($this->session, $this->player, $packet);
		return true;
	}

	public function handleSetPlayerGameType(SetPlayerGameTypePacket $packet) : bool{
		$gameMode = $this->session->getTypeConverter()->protocolGameModeToCore($packet->gamemode);
		if($gameMode !== $this->player->getGamemode()){
			//Set this back to default. TODO: handle this properly
			$this->session->syncGameMode($this->player->getGamemode(), true);
		}
		return true;
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->radius);

		return true;
	}

	/**
	 * The End Poem and credits shown when leaving the End for the first time were closed (or skipped): the player goes
	 * on to their spawn point.
	 */
	public function handleShowCredits(ShowCreditsPacket $packet) : bool{
		if($packet->status === ShowCreditsPacket::STATUS_END_CREDITS){
			PortalTravel::getInstance()->onCreditsClosed($this->player);
		}
		return true;
	}

	/**
	 * The command block screen of a command block or of a command block minecart was edited.
	 */
	public function handleCommandBlockUpdate(CommandBlockUpdatePacket $packet) : bool{
		if($packet->isBlock){
			CommandBlockRequests::handleBlockUpdate($this->session, $this->player, $packet);
			return true;
		}
		$minecart = $this->player->getWorld()->getEntity($packet->minecartActorRuntimeId);
		if(!$minecart instanceof MinecartCommandBlock || !$minecart->canEdit($this->player) || $this->player->getLocation()->distance($minecart->getLocation()) > 16){
			return true;
		}
		$minecart->setCommand($packet->command);
		$minecart->setTrackOutput($packet->shouldTrackOutput);
		if($packet->name !== "" && $packet->name !== $minecart->getNameTag()){
			$minecart->setNameTag($packet->name);
		}
		return true;
	}

	public function handleCommandRequest(CommandRequestPacket $packet) : bool{
		if(str_starts_with($packet->command, '/')){
			$this->player->chat($packet->command);
			return true;
		}
		return false;
	}

	public function handlePlayerSkin(PlayerSkinPacket $packet) : bool{
		if($packet->skin->getFullSkinId() === $this->lastRequestedFullSkinId){
			//TODO: HACK! In 1.19.60, the client sends its skin back to us if we sent it a skin different from the one
			//it's using. We need to prevent this from causing a feedback loop.
			$this->session->getLogger()->debug("Refused duplicate skin change request");
			return true;
		}
		$this->lastRequestedFullSkinId = $packet->skin->getFullSkinId();

		$this->session->getLogger()->debug("Processing skin change request");
		try{
			$skin = $this->session->getTypeConverter()->getSkinAdapter()->fromSkinData($packet->skin);
		}catch(InvalidSkinException $e){
			throw PacketHandlingException::wrap($e, "Invalid skin in PlayerSkinPacket");
		}
		return $this->player->changeSkin($skin, $packet->newSkinName, $packet->oldSkinName);
	}

	/**
	 * @throws PacketHandlingException
	 */
	private function checkBookText(string $string, string $fieldName, int $softLimit, int $hardLimit, bool &$cancel) : string{
		if(strlen($string) > $hardLimit){
			throw new PacketHandlingException(sprintf("Book %s must be at most %d bytes, but have %d bytes", $fieldName, $hardLimit, strlen($string)));
		}

		$result = TextFormat::clean($string, false);
		//strlen() is O(1), mb_strlen() is O(n)
		if(strlen($result) > $softLimit * 4 || mb_strlen($result, 'UTF-8') > $softLimit){
			$cancel = true;
			$this->session->getLogger()->debug("Cancelled book edit due to $fieldName exceeded soft limit of $softLimit chars");
		}

		return $result;
	}

	public function handleBookEdit(BookEditPacket $packet) : bool{
		$inventory = $this->player->getInventory();
		if(!$inventory->slotExists($packet->inventorySlot)){
			return false;
		}
		//TODO: break this up into book API things
		$oldBook = $inventory->getItem($packet->inventorySlot);
		if(!($oldBook instanceof WritableBook)){
			return false;
		}

		$newBook = clone $oldBook;
		$modifiedPages = [];
		$cancel = false;
		switch($packet->type){
			case BookEditPacket::TYPE_REPLACE_PAGE:
				$text = self::checkBookText($packet->text, "page text", self::PAGE_LENGTH_SOFT_LIMIT_CHARS, WritableBookPage::PAGE_LENGTH_HARD_LIMIT_BYTES, $cancel);
				if($packet->pageNumber < 0){
					throw new PacketHandlingException("Page number cannot be negative");
				}
				$newBook->setPageText($packet->pageNumber, $text);
				$modifiedPages[] = $packet->pageNumber;
				break;
			case BookEditPacket::TYPE_ADD_PAGE:
				if(!$newBook->pageExists($packet->pageNumber)){
					//this may only come before a page which already exists
					//TODO: the client can send insert-before actions on trailing client-side pages which cause odd behaviour on the server
					return false;
				}
				$text = self::checkBookText($packet->text, "page text", self::PAGE_LENGTH_SOFT_LIMIT_CHARS, WritableBookPage::PAGE_LENGTH_HARD_LIMIT_BYTES, $cancel);
				$newBook->insertPage($packet->pageNumber, $text);
				$modifiedPages[] = $packet->pageNumber;
				break;
			case BookEditPacket::TYPE_DELETE_PAGE:
				if(!$newBook->pageExists($packet->pageNumber)){
					return false;
				}
				$newBook->deletePage($packet->pageNumber);
				$modifiedPages[] = $packet->pageNumber;
				break;
			case BookEditPacket::TYPE_SWAP_PAGES:
				if($packet->pageNumber < 0 || $packet->secondaryPageNumber < 0){
					throw new PacketHandlingException("Page numbers cannot be negative");
				}
				if(!$newBook->pageExists($packet->pageNumber) || !$newBook->pageExists($packet->secondaryPageNumber)){
					//the client will create pages on its own without telling us until it tries to switch them
					$newBook->addPage(max($packet->pageNumber, $packet->secondaryPageNumber));
				}
				$newBook->swapPages($packet->pageNumber, $packet->secondaryPageNumber);
				$modifiedPages = [$packet->pageNumber, $packet->secondaryPageNumber];
				break;
			case BookEditPacket::TYPE_SIGN_BOOK:
				$title = self::checkBookText($packet->title, "title", 16, Limits::INT16_MAX, $cancel);
				//this one doesn't have a limit in vanilla, so we have to improvise
				$author = self::checkBookText($packet->author, "author", 256, Limits::INT16_MAX, $cancel);

				$newBook = VanillaItems::WRITTEN_BOOK()
					->setPages($oldBook->getPages())
					->setAuthor($author)
					->setTitle($title)
					->setGeneration(WrittenBook::GENERATION_ORIGINAL);
				break;
			default:
				return false;
		}

		//for redundancy, in case of protocol changes, we don't want to pass these directly
		$action = match($packet->type){
			BookEditPacket::TYPE_REPLACE_PAGE => PlayerEditBookEvent::ACTION_REPLACE_PAGE,
			BookEditPacket::TYPE_ADD_PAGE => PlayerEditBookEvent::ACTION_ADD_PAGE,
			BookEditPacket::TYPE_DELETE_PAGE => PlayerEditBookEvent::ACTION_DELETE_PAGE,
			BookEditPacket::TYPE_SWAP_PAGES => PlayerEditBookEvent::ACTION_SWAP_PAGES,
			BookEditPacket::TYPE_SIGN_BOOK => PlayerEditBookEvent::ACTION_SIGN_BOOK,
			default => throw new AssumptionFailedError("We already filtered unknown types in the switch above")
		};

		/*
		 * Plugins may have created books with more than 50 pages; we allow plugins to do this, but not players.
		 * Don't allow the page count to grow past 50, but allow deleting, swapping or altering text of existing pages.
		 */
		$oldPageCount = count($oldBook->getPages());
		$newPageCount = count($newBook->getPages());
		if(($newPageCount > $oldPageCount && $newPageCount > 50)){
			$this->session->getLogger()->debug("Cancelled book edit due to adding too many pages (new page count would be $newPageCount)");
			$cancel = true;
		}

		$event = new PlayerEditBookEvent($this->player, $oldBook, $newBook, $action, $modifiedPages);
		if($cancel){
			$event->cancel();
		}

		$event->call();
		if($event->isCancelled()){
			return true;
		}

		$this->player->getInventory()->setItem($packet->inventorySlot, $event->getNewBook());

		return true;
	}

	public function handleModalFormResponse(ModalFormResponsePacket $packet) : bool{
		if($packet->cancelReason !== null){
			//TODO: make APIs for this to allow plugins to use this information
			return $this->player->onFormSubmit($packet->formId, null);
		}elseif($packet->formData !== null){
			if(strlen($packet->formData) > self::MAX_FORM_RESPONSE_SIZE){
				throw new PacketHandlingException("Form response data too large, refusing to decode (received" . strlen($packet->formData) . " bytes, max " . self::MAX_FORM_RESPONSE_SIZE . " bytes)");
			}
			if(!$this->player->hasPendingForm($packet->formId)){
				$this->session->getLogger()->debug("Got unexpected response for form $packet->formId");
				return false;
			}
			try{
				$responseData = json_decode($packet->formData, true, self::MAX_FORM_RESPONSE_DEPTH, JSON_THROW_ON_ERROR);
			}catch(\JsonException $e){
				throw PacketHandlingException::wrap($e, "Failed to decode form response data");
			}
			return $this->player->onFormSubmit($packet->formId, $responseData);
		}else{
			throw new PacketHandlingException("Expected either formData or cancelReason to be set in ModalFormResponsePacket");
		}
	}

	public function handleLecternUpdate(LecternUpdatePacket $packet) : bool{
		$pos = $packet->blockPosition;
		$chunkX = $pos->getX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $pos->getZ() >> Chunk::COORD_BIT_SIZE;
		$world = $this->player->getWorld();
		if(!$world->isChunkLoaded($chunkX, $chunkZ) || $world->isChunkLocked($chunkX, $chunkZ)){
			return false;
		}

		$lectern = $world->getBlockAt($pos->getX(), $pos->getY(), $pos->getZ());
		if($lectern instanceof Lectern && $this->player->canInteract($lectern->getPosition(), 15)){
			if(!$lectern->onPageTurn($packet->page)){
				$this->syncBlocksNearby($lectern->getPosition(), null);
			}
			return true;
		}

		return false;
	}

	/**
	 * Sent when a player clicks an empty slot of an open crafter to disable or enable it.
	 */
	public function handlePlayerToggleCrafterSlotRequest(PlayerToggleCrafterSlotRequestPacket $packet) : bool{
		$pos = $packet->getPosition();
		$world = $this->player->getWorld();
		if(!$world->isInWorld($pos->getX(), $pos->getY(), $pos->getZ()) || !$world->isChunkLoaded($pos->getX() >> Chunk::COORD_BIT_SIZE, $pos->getZ() >> Chunk::COORD_BIT_SIZE)){
			return false;
		}
		$tile = $world->getTileAt($pos->getX(), $pos->getY(), $pos->getZ());
		if(!$tile instanceof TileCrafter || $this->player->getCurrentWindow() !== $tile->getInventory()){
			return false;
		}
		$tilePos = $tile->getPosition();
		if($tile->setSlotDisabled($packet->getSlot(), $packet->isDisabled())){
			$world->addSound($tilePos->add(0.5, 0.5, 0.5), $packet->isDisabled() ? new CrafterDisableSlotSound() : new ClickSound());
		}else{
			//the slot holds an item: undo the client's prediction
			$this->syncBlocksNearby($tilePos, null);
		}
		return true;
	}

	public function handleEmote(EmotePacket $packet) : bool{
		$this->player->emote($packet->getEmoteId());
		return true;
	}

	public function handleMapInfoRequest(MapInfoRequestPacket $packet) : bool{
		//sent for maps in the inventory or in item frames when the client doesn't have their data yet; the pixels the
		//client may send (client-side generation) are not trusted and ignored
		MapManager::getInstance()->handleMapInfoRequest($this->player, $packet->mapId);
		return true;
	}

	public function handlePositionTrackingDBClientRequest(PositionTrackingDBClientRequestPacket $packet) : bool{
		//sent by the client while it holds a lodestone compass, to find out where the lodestone is
		$handle = $packet->getTrackingId();
		$db = PositionTrackingDB::getInstance();
		$entry = $db->get($handle);
		$world = $this->player->getWorld();
		$nbt = CompoundTag::create();
		if($entry === null){
			$action = $db->wasAssigned($handle) ? PositionTrackingDBServerBroadcastPacket::ACTION_DESTROY : PositionTrackingDBServerBroadcastPacket::ACTION_NOT_FOUND;
		}elseif($entry["world"] !== $world->getFolderName()){
			//the lodestone is in another world: the compass spins, like in another dimension in vanilla
			$action = PositionTrackingDBServerBroadcastPacket::ACTION_NOT_FOUND;
		}elseif(
			$world->isChunkLoaded($entry["x"] >> Chunk::COORD_BIT_SIZE, $entry["z"] >> Chunk::COORD_BIT_SIZE) &&
			!$world->getBlockAt($entry["x"], $entry["y"], $entry["z"]) instanceof Lodestone
		){
			//the lodestone was removed without being broken (e.g. replaced by a plugin)
			$db->remove($handle);
			$action = PositionTrackingDBServerBroadcastPacket::ACTION_DESTROY;
		}else{
			$action = PositionTrackingDBServerBroadcastPacket::ACTION_UPDATE;
			$nbt->setInt("dim", $world->getDimension()->getNetworkId());
			$nbt->setString("id", sprintf("0x%08X", $handle));
			$nbt->setByte("version", 1);
			$nbt->setByte("status", 0);
			$nbt->setTag("pos", new ListTag([new IntTag($entry["x"]), new IntTag($entry["y"]), new IntTag($entry["z"])]));
		}
		$this->session->sendDataPacket(PositionTrackingDBServerBroadcastPacket::create($action, $handle, new CacheableNbt($nbt)));
		return true;
	}
}
