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

namespace pocketmine\plugin;

use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\event\plugin\PluginErrorEvent;
use pocketmine\network\PacketHandlingException;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\thread\ThreadCrashException;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function array_filter;
use function array_values;
use function count;
use function implode;
use function is_string;
use function microtime;
use function rtrim;
use function str_starts_with;

/**
 * Isolates failures of plugin code so that a buggy plugin cannot crash the whole server.
 *
 * Every place where the core calls into plugin code (event handlers, scheduled tasks, commands, async task
 * completions, form responses, promise callbacks, chunk listeners, plugin-defined blocks/entities, plugin
 * load/enable/disable...) catches \Throwable and passes it to {@link PluginFaultHandler::isolate()}, which:
 *
 *  1. Attributes the failure to a plugin: first the registered owner of the entry point (the plugin which registered
 *     the listener / owns the task scheduler / owns the command), then the file in which the called object's class or
 *     closure was declared, then the files of the stack trace (innermost first), like the crash dump does.
 *  2. If no plugin can be blamed, the throwable is rethrown: core bugs must never be hidden, so they still crash the
 *     server as before.
 *  3. Otherwise a {@link PluginErrorEvent} is called, the failure is logged at ERROR level as
 *     "[Plugin vX] Unhandled exception in <entry point>" with the full stack trace, and the server carries on. Whatever
 *     the plugin did before failing stays done (e.g. an event keeps the state the failing handler left it in).
 *  4. If the plugin fails {@link PluginFaultHandler::getDisableAfterErrors()} times within
 *     {@link PluginFaultHandler::getErrorWindowSeconds()} seconds (by default 10 failures in 60 seconds), it is scheduled
 *     to be disabled, with a CRITICAL log.
 *
 * Disabling is deferred: the plugin is only marked as pending, and actually disabled by
 * {@link PluginFaultHandler::processPendingDisables()} at the end of the current server tick. Disabling a plugin from
 * inside its own failing callback (while the core is iterating a handler list, a scheduler heap, a task queue...) is
 * therefore never needed. Until then, the core skips the plugin's remaining event handlers and scheduled tasks
 * ({@link PluginFaultHandler::isPendingDisable()}).
 *
 * Exceptions used for control flow by the core (task cancellation, command usage errors, bad packets) are always
 * rethrown. A {@link DisablePluginException} thrown from any entry point disables the plugin that threw it.
 *
 * Uncatchable fatal errors (memory exhaustion, compile errors, segfaults...) cannot be isolated and still crash the
 * server.
 *
 * The old behaviour (any plugin exception crashes the server) can be restored by setting
 * `plugins.crash-on-unhandled-exception: true` in pocketmine.yml.
 */
final class PluginFaultHandler{
	public const DEFAULT_DISABLE_AFTER_ERRORS = 10;
	public const DEFAULT_ERROR_WINDOW_SECONDS = 60;

	private static ?self $instance = null;

	/**
	 * Returns the fault handler used by the server, or null if none is installed (in which case plugin failures
	 * propagate as they used to).
	 */
	public static function getInstance() : ?self{
		return self::$instance;
	}

	/**
	 * @internal
	 */
	public static function setInstance(?self $instance) : void{
		self::$instance = $instance;
	}

	/**
	 * Entry point used by the core. Returns normally if the failure was attributed to a plugin and isolated; rethrows
	 * the throwable otherwise (no fault handler installed, core bug, control-flow exception, or crash mode enabled).
	 *
	 * @internal
	 *
	 * @param Plugin|null $owner   Plugin known to own the entry point, if any
	 * @param string      $context Human-readable description of the entry point, e.g. "command /foo"
	 * @param object|null $origin  Object or closure provided by plugin code which was being called, used to attribute
	 *                             the failure when the owner is not known
	 *
	 * @throws \Throwable
	 */
	public static function isolate(\Throwable $throwable, ?Plugin $owner, string $context, ?object $origin = null) : void{
		$handler = self::$instance;
		if($handler === null){
			throw $throwable;
		}
		$handler->handle($throwable, $owner, $context, $origin);
	}

	/**
	 * Returns whether the plugin has been scheduled to be disabled because of its failures and must not be called any
	 * more until then (the core skips its remaining event handlers and scheduled tasks).
	 */
	public static function isPluginPendingDisable(Plugin $plugin) : bool{
		return self::$instance !== null && self::$instance->isPendingDisable($plugin);
	}

	/**
	 * Recent error timestamps per plugin name.
	 * @var float[][]
	 * @phpstan-var array<string, list<float>>
	 */
	private array $errorTimes = [];

	/**
	 * Plugins disabled by this handler whose code (e.g. a custom block or entity class) keeps failing afterwards; their
	 * further failures are logged at debug level only, to avoid flooding the log.
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $quietened = [];

	/**
	 * Plugins to disable at the end of the current tick, by name.
	 * @var Plugin[]
	 * @phpstan-var array<string, Plugin>
	 */
	private array $pendingDisable = [];

	private bool $callingEvent = false;

	/** @phpstan-var \Closure() : float */
	private \Closure $clock;

	private ?\ReflectionProperty $pluginFileProperty = null;

	/**
	 * @param \Closure      $pluginsProvider    Returns the loaded plugins, used to attribute failures by file path
	 * @param \Closure      $pluginDisabler     Disables a plugin (normally {@link PluginManager::disablePlugin()})
	 * @param int           $disableAfterErrors Failures within the window after which a plugin is disabled; 0 = never
	 * @param \Closure|null $clock              Returns the current time in seconds (for tests)
	 *
	 * @phpstan-param \Closure() : array<Plugin> $pluginsProvider
	 * @phpstan-param \Closure(Plugin) : void    $pluginDisabler
	 * @phpstan-param (\Closure() : float)|null  $clock
	 */
	public function __construct(
		private \Logger $logger,
		private \Closure $pluginsProvider,
		private \Closure $pluginDisabler,
		private bool $crashOnUnhandledException = false,
		private int $disableAfterErrors = self::DEFAULT_DISABLE_AFTER_ERRORS,
		private float $errorWindowSeconds = self::DEFAULT_ERROR_WINDOW_SECONDS,
		?\Closure $clock = null
	){
		$this->clock = $clock ?? static fn() : float => microtime(true);
	}

	/**
	 * Whether plugin failures crash the server like they used to (pmmp behaviour).
	 */
	public function isCrashOnUnhandledException() : bool{ return $this->crashOnUnhandledException; }

	/**
	 * Number of failures within the error window after which a plugin is disabled. 0 or less means never.
	 */
	public function getDisableAfterErrors() : int{ return $this->disableAfterErrors; }

	public function getErrorWindowSeconds() : float{ return $this->errorWindowSeconds; }

	/**
	 * Returns whether the given throwable is used by the core for control flow and must therefore never be swallowed.
	 */
	public static function isControlFlowException(\Throwable $throwable) : bool{
		return
			$throwable instanceof CancelTaskException ||
			$throwable instanceof InvalidCommandSyntaxException ||
			$throwable instanceof PacketHandlingException;
	}

	public function isPendingDisable(Plugin $plugin) : bool{
		return isset($this->pendingDisable[$plugin->getDescription()->getName()]);
	}

	/**
	 * Disables the plugins scheduled to be disabled. Called by the server at the end of every tick, when no plugin
	 * code is on the stack.
	 */
	public function processPendingDisables() : void{
		while(count($this->pendingDisable) > 0){
			$plugins = $this->pendingDisable;
			$this->pendingDisable = [];
			foreach($plugins as $plugin){
				if($plugin->isEnabled()){
					($this->pluginDisabler)($plugin);
				}
			}
		}
	}

	/**
	 * @see PluginFaultHandler::isolate()
	 * @throws \Throwable
	 */
	public function handle(\Throwable $throwable, ?Plugin $owner, string $context, ?object $origin = null) : void{
		if($this->crashOnUnhandledException || self::isControlFlowException($throwable)){
			throw $throwable;
		}
		$plugin = $this->attribute($throwable, $owner, $origin);
		if($plugin === null){
			//not a plugin bug (or at least we can't tell) - don't hide it
			throw $throwable;
		}
		$name = $plugin->getDescription()->getName();
		$fullName = $plugin->getDescription()->getFullName();

		if($throwable instanceof DisablePluginException){
			if($plugin->isEnabled() && !isset($this->pendingDisable[$name])){
				$this->logger->warning("[$fullName] Plugin requested to be disabled from $context");
				$this->pendingDisable[$name] = $plugin;
			}
			return;
		}

		if(!$plugin->isEnabled()){
			if(isset($this->quietened[$name])){
				$this->logger->debug("[$fullName] Unhandled exception in $context (plugin is disabled): " . $throwable::class . ": " . $throwable->getMessage());
			}elseif(isset($this->errorTimes[$name])){
				//we disabled it, and something it left behind (e.g. a custom block or entity) is still failing
				$this->quietened[$name] = true;
				$this->logger->error("[$fullName] Unhandled exception in $context (plugin is disabled; further errors from it will only be logged in debug mode)\n" . self::describe($throwable));
			}else{
				//e.g. an exception in onDisable(), or a plugin which was never enabled - just log it
				$this->logger->error("[$fullName] Unhandled exception in $context\n" . self::describe($throwable));
			}
			return;
		}
		unset($this->quietened[$name]);

		$now = ($this->clock)();
		$windowStart = $now - $this->errorWindowSeconds;
		$times = array_values(array_filter($this->errorTimes[$name] ?? [], fn(float $t) => $t > $windowStart));
		$times[] = $now;
		$this->errorTimes[$name] = $times;
		$count = count($times);
		$alreadyPending = isset($this->pendingDisable[$name]);
		$disable = !$alreadyPending && $this->disableAfterErrors > 0 && $count >= $this->disableAfterErrors;

		$suppressLog = false;
		if(!$this->callingEvent){ //a handler of PluginErrorEvent failed - don't recurse
			$this->callingEvent = true;
			try{
				$ev = new PluginErrorEvent($plugin, $throwable, $context, $count, $disable || $alreadyPending);
				$ev->call();
				$suppressLog = $ev->isCancelled();
			}finally{
				$this->callingEvent = false;
			}
		}

		if(!$suppressLog){
			$this->logger->error("[$fullName] Unhandled exception in $context\n" . self::describe($throwable));
		}

		if($disable){
			$this->logger->critical(
				"Disabling plugin $fullName due to an unhandled exception in $context" .
				($this->disableAfterErrors > 1 ? " ($count unhandled errors within " . $this->errorWindowSeconds . " seconds)" : "")
			);
			//the error history is kept so that failures of things it left behind (custom blocks/entities) get quietened
			$this->pendingDisable[$name] = $plugin;
		}
	}

	/**
	 * Returns the full printable information (message and stack trace) about the throwable, including the trace of
	 * the other thread for failures of async tasks.
	 */
	private static function describe(\Throwable $throwable) : string{
		$lines = Utils::printableExceptionInfo($throwable);
		for($e = $throwable; $e !== null; $e = $e->getPrevious()){
			if($e instanceof ThreadCrashException){
				$info = $e->getCrashInfo();
				$lines[] = "--- Exception on thread " . $info->getThreadName() . " ---";
				$lines[] = $info->makePrettyMessage();
				foreach($info->getTrace() as $frame){
					$lines[] = "  " . $frame->getPrintableFrame();
				}
			}
		}
		return implode("\n", $lines);
	}

	/**
	 * Returns the number of failures counted for the given plugin within the error window.
	 */
	public function getRecentErrorCount(Plugin $plugin) : int{
		$windowStart = ($this->clock)() - $this->errorWindowSeconds;
		return count(array_filter($this->errorTimes[$plugin->getDescription()->getName()] ?? [], fn(float $t) => $t > $windowStart));
	}

	/**
	 * Determines which plugin is responsible for the given throwable, or null if it doesn't look like a plugin's fault.
	 */
	public function attribute(\Throwable $throwable, ?Plugin $owner = null, ?object $origin = null) : ?Plugin{
		if($owner !== null){
			return $owner;
		}
		if($origin !== null && ($plugin = $this->findPluginByObject($origin)) !== null){
			return $plugin;
		}

		for($e = $throwable; $e !== null; $e = $e->getPrevious()){
			if($e instanceof ThreadCrashException){
				//failure on another thread (e.g. AsyncTask::onRun()): its trace is in the crash info
				$info = $e->getCrashInfo();
				if(($plugin = $this->findPluginByFile($info->getFile())) !== null){
					return $plugin;
				}
				foreach($info->getTrace() as $frame){
					$frameFile = $frame->getFile();
					if($frameFile !== null && ($plugin = $this->findPluginByFile($frameFile)) !== null){
						return $plugin;
					}
				}
			}
			if(($plugin = $this->findPluginByFile($e->getFile())) !== null){
				return $plugin;
			}
			foreach($e->getTrace() as $frame){
				if(isset($frame["file"]) && ($plugin = $this->findPluginByFile($frame["file"])) !== null){
					return $plugin;
				}
			}
		}
		return null;
	}

	/**
	 * Returns the plugin which declared the given closure or the class of the given object, if any.
	 */
	public function findPluginByObject(object $object) : ?Plugin{
		$file = $object instanceof \Closure ?
			(new \ReflectionFunction($object))->getFileName() :
			(new \ReflectionClass($object))->getFileName();
		return is_string($file) ? $this->findPluginByFile($file) : null;
	}

	/**
	 * Returns the plugin whose files contain the given source file, if any.
	 * Only works for plugins extending {@link PluginBase} (like the crash dump).
	 */
	public function findPluginByFile(string $file) : ?Plugin{
		$cleanFile = Filesystem::cleanPath($file);
		foreach(($this->pluginsProvider)() as $plugin){
			if(!$plugin instanceof PluginBase){
				continue;
			}
			//PluginBase::getFile() is protected; the crash dump uses reflection the same way
			$this->pluginFileProperty ??= new \ReflectionProperty(PluginBase::class, "file");
			$pluginFile = $this->pluginFileProperty->getValue($plugin);
			if(!is_string($pluginFile) || $pluginFile === ""){
				continue;
			}
			$cleanPluginFile = Filesystem::cleanPath($pluginFile); //always ends with "/"
			if(
				str_starts_with($cleanFile, $cleanPluginFile) || //phar or folder plugin
				$cleanFile === rtrim($cleanPluginFile, "/") //script plugin (single .php file)
			){
				return $plugin;
			}
		}
		return null;
	}
}
