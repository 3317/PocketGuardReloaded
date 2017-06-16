<?php

namespace MinecrafterJPN;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Level;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\tile\Chest;

class PocketGuard extends PluginBase implements Listener {
    /** @var PocketGuardDatabaseManager  */
    private $databaseManager;
    private $queue;
    /** @var  PocketGuardLogger */
    private $pocketGuardLogger;

    // Constants
    const NOT_LOCKED = -1;
    const NORMAL_LOCK = 0;
    const PASSCODE_LOCK = 1;
    const PUBLIC_LOCK = 2;

    public function onLoad()
	{
	}

	public function onEnable()
	{
        @mkdir($this->getDataFolder());
        $this->queue = [];
        $this->pocketGuardLogger = new PocketGuardLogger($this->getDataFolder() . 'PocketGuard.log');
        $this->databaseManager = new PocketGuardDatabaseManager($this->getDataFolder() . 'PocketGuard.sqlite3');
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getLogger()->info("§6[PocketGuard:Admin]§rPocketGuardReloadedを読み込んだよ！");
    }

	public function onDisable()
	{
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args)
	{
        if (!($sender instanceof Player)) {
            $sender->sendMessage("§4[PocketGuard]§rゲームで実行してくださいね！");
            return true;
        }
        if (isset($this->queue[$sender->getName()])) {
            $sender->sendMessage("§4[PocketGuard]§r前回のコマンドがまだいきてるよ！チェストをタッチしよう！");
            return true;
        }
        switch (strtolower($command->getName())) {
            case "pg":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "lock":
                    case "unlock":
                    case "public":
                    case "info":
                        $this->queue[$sender->getName()] = [$option];
                        break;

                    case "passlock":
                    case "passunlock":
                        if (is_null($passcode = array_shift($args))) {
                            $sender->sendMessage("§2[PocketGuard]§r使用方法: /pg passlock <passcode>");
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $passcode];
                        break;

                    case "share":
                        if (is_null($target = array_shift($args))) {
                            $sender->sendMessage("§2[PocketGuard]§r使用方法: /pg share <player>");
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $target];
                        break;

                    default:
                        $sender->sendMessage("§2[PocketGuard]§rコマンドは存在しないよ？以下のコマンドを使用してね！");
                        $sender->sendMessage("§2[PocketGuard]§r/pg <lock | unlock | public | info>");
                        $sender->sendMessage("§2[PocketGuard]§r/pg <passlock | passunlock | share>");
                        return true;
                }
                $this->pocketGuardLogger->log("[" . $sender->getName() . "] Action:Command Command:" . $command->getName() . " Args:" . implode(",", $args));
                $sender->sendMessage("§2[PocketGuard]§rチェストをタッチしよう！");
                return true;

            case "spg":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "unlock":
                        $unlockOption =strtolower(array_shift($args));
                        switch ($unlockOption) {
                            case "a":
                            case "all":
                                $this->databaseManager->deleteAll();
                                $sender->sendMessage("§6[PocketGuard:Admin]§rロックを解除しました");
                                break;

                            case "p":
                            case "player":
                                $target = array_shift($args);
                                if (is_null($target)) {
                                    $sender->sendMessage("§6[PocketGuard:Admin]§r誰のチェストロックを解除しますか？");
                                    $sender->sendMessage("§6[PocketGuard:Admin]§r/spg unlock player <player>");
                                    return true;
                                }
                                $this->databaseManager->deletePlayerData($target);
                                $sender->sendMessage("§6[PocketGuard:Admin]§r$target'のチェストロックを全て解除しました！");
                                break;

                            default:
                                $sender->sendMessage("§6[PocketGuard:Admin]§rコマンドが存在しません。以下のコマンドを使用してください。");
                                $sender->sendMessage("§6[PocketGuard:Admin]§r/spg unlock <all | player>");
                                return true;
                        }
                        break;
                      
                    default:
                        $sender->sendMessage("§6[PocketGuard:Admin]§rコマンドが存在しません。以下のコマンドを使用してください。");
                        $sender->sendMessage("§6[PocketGuard:Admin]§r/spg <unlock>");
                        return true;
                }
                $this->pocketGuardLogger->log("[" . $sender->getName() . "] Action:Command Command:" . $command->getName() . " Args:" . implode(",", $args));
                return true;
        }
        return false;
	}

    public function onPlayerBreakBlock(BlockBreakEvent $event) {
        if ($event->getBlock()->getID() === Item::CHEST and $this->databaseManager->isLocked($event->getBlock())) {
            $chest = $event->getBlock();
            $owner = $this->databaseManager->getOwner($chest);
            $attribute = $this->databaseManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest) $pairChestTile = $tile->getPair();
            if ($owner === $event->getPlayer()->getName()) {
                $this->databaseManager->unlock($chest);
                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                $event->getPlayer()->sendMessage("§2[PocketGuard]§rロックを解除したよ！");
            } elseif ($attribute !== self::NOT_LOCKED and $owner !== $event->getPlayer()->getName() and !$event->getPlayer()->hasPermission("pocketguard.op")) {
                $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストはロックされているよ！");
                $event->getPlayer()->sendMessage("§2[PocketGuard]§r\"/pg info\" で誰のチェストかわかるよ！");
                $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                $event->setCancelled();
            }
        }
    }

    public function onPlayerBlockPlace(BlockPlaceEvent $event) {
        // Prohibit placing chest next to locked chest
        if ($event->getItem()->getID() === Item::CHEST) {
            $cs = $this->getSideChest($event->getPlayer()->getLevel(), $event->getBlock()->x, $event->getBlock()->y, $event->getBlock()->z);
            if (!is_null($cs)) {
                foreach ($cs as $c) {
                    if ($this->databaseManager->isLocked($c)) {
                        $event->getPlayer()->sendMessage("§2[PocketGuard]§rロックされたチェストの隣にチェストを置くことは出来ないよ！");
                        $event->setCancelled();
                        return;
                    }
                }
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {
        // Execute task
        if ($event->getBlock()->getID() === Item::CHEST) {
            $chest = $event->getBlock();
            $owner = $this->databaseManager->getOwner($chest);
            $attribute = $this->databaseManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest) $pairChestTile = $tile->getPair();
            if (isset($this->queue[$event->getPlayer()->getName()])) {
                $task = $this->queue[$event->getPlayer()->getName()];
                $taskName = array_shift($task);
                switch ($taskName) {
                    case "lock":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->normalLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->normalLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rチェストをロックしたよ");
                            $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Lock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストはもうロックされているよ！");
                        }
                        break;

                    case "unlock":
                        if ($owner === $event->getPlayer()->getName() and $attribute === self::NORMAL_LOCK) {
                            $this->databaseManager->unlock($chest);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rロックを解除したよ！");
                            $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§r他のプレイヤーがロックしたチェストだよ！");
                        }
                        break;

                    case "public":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->publicLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->publicLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rチェストをみんなのものにしたよ！");
                            $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Public Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rエラー");
                        }
                        break;

                    case "info":
                        if ($attribute !== self::NOT_LOCKED) {
                            $message = "§2[PocketGuard]§rこのチェストのオーナーは: $owner ロックの方法は: ";
                            switch ($attribute) {
                                case self::NORMAL_LOCK:
                                    $message .= "通常です";
                                    break;

                                case self::PASSCODE_LOCK:
                                    $message .= "パスコードです";
                                    break;

                                case self::PUBLIC_LOCK:
                                    $message .= "公共です";
                                    break;
                            }
                            $event->getPlayer()->sendMessage($message);
                            $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Info Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストはまだ誰もロックしてないよ！");
                        }
                        break;

                    case "passlock":
                        if ($attribute === self::NOT_LOCKED) {
                            $passcode = array_shift($task);
                            $this->databaseManager->passcodeLock($chest, $event->getPlayer()->getName(), $passcode);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->passcodeLock($pairChestTile, $event->getPlayer()->getName(), $passcode);
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストは\"$passcode\"でロックされたよ");
                            $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Passlock Passcode:$passcode Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストはもうロックされているよ！");
                        }
                        break;

                    case "passunlock":
                        if ($attribute === self::PASSCODE_LOCK) {
                            $passcode = array_shift($task);
                            if ($this->databaseManager->checkPasscode($chest, $passcode)) {
                                $this->databaseManager->unlock($chest);
                                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                                $event->getPlayer()->sendMessage("§2[PocketGuard]§rロックを解除したよ！");
                                $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:Passunlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                            } else {
                                $event->getPlayer()->sendMessage("§2[PocketGuard]§rパスコードが違うよ！");
                                $this->pocketGuardLogger->log("[" . $event->getPlayer()->getName() . "] Action:FailPassunlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                            }
                        } else {
                            $event->getPlayer()->sendMessage("§2[PocketGuard]§r他のプレイヤーがロックしたチェストだよ！");
                        }
                        break;

                    case "share":
                        break;
                }
                $event->setCancelled();
                unset($this->queue[$event->getPlayer()->getName()]);
            } elseif($attribute !== self::NOT_LOCKED and $attribute !== self::PUBLIC_LOCK and $owner !== $event->getPlayer()->getName() and !$event->getPlayer()->hasPermission("pocketguard.op")) {
                $event->getPlayer()->sendMessage("§2[PocketGuard]§rこのチェストはロックされているよ！");
                $event->getPlayer()->sendMessage("§2[PocketGuard]§r\"/pg info\"で誰のチェストかわかるよ！");
                $event->setCancelled();
            }
        }
    }

    private function getSideChest(Level $level, $x, $y, $z)
    {
        $sideChests = [];
        $item = $level->getBlock(new Vector3($x + 1, $y, $z));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x - 1, $y, $z));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z + 1));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z - 1));
        if ($item->getID() === Item::CHEST) $sideChests[] = $item;
        return empty($sideChests) ? null : $sideChests;
    }
}