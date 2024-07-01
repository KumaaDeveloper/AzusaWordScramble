<?php

namespace KumaDev\WordScramble;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\Config;
use DaPigGuy\libPiggyEconomy\libPiggyEconomy;
use DaPigGuy\libPiggyEconomy\providers\EconomyProvider;
use DaPigGuy\libPiggyEconomy\exceptions\MissingProviderDependencyException;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\scheduler\Task;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;

class Main extends PluginBase implements Listener {

    protected $scrambleEnabled = false;
    protected $taskHandler;
    protected $config;
    protected $currentWord = null;
    protected static $economyProvider = null;
    protected $currentQuestionStartTime = 0;
    private $words = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Check for required dependencies
        foreach ([
            "libPiggyEconomy" => libPiggyEconomy::class
        ] as $virion => $class) {
            if (!class_exists($class)) {
                $this->getServer()->getLogger()->error("[WordScramble] " . $virion . " virion not found. Please download DeVirion Now!.");
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
        }

        // Initialize economy library
        libPiggyEconomy::init();
        
        // Load and save configuration files
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        
        $this->saveResource("word.yml");
        $wordConfig = new Config($this->getDataFolder() . "word.yml", Config::YAML);
        $this->words = $wordConfig->get("words", []);

        $economyConfig = $this->config->get("Economy");

        // Ensure economy provider is set in the config
        if ($economyConfig === null || !isset($economyConfig['type'])) {
            $this->getServer()->getLogger()->error("[WordScramble] No economy provider specified in config.yml.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Initialize economy provider
        try {
            self::$economyProvider = libPiggyEconomy::getProvider([
                'provider' => $economyConfig['type']
            ]);
        } catch (MissingProviderDependencyException $e) {
            $this->getServer()->getLogger()->error("[WordScramble] Dependencies for provider not found: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
    }

    public function onDisable(): void {
        if ($this->taskHandler !== null) {
            $this->taskHandler->cancel();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "wordscramble") {
            if (isset($args[0])) {
                if ($args[0] === "on") {
                    if (!$this->scrambleEnabled) {
                        $this->scrambleEnabled = true;
                        $this->scheduleFirstWordScramble();
                        $sender->sendMessage("§aAzusaWordScramble questions have been enabled.");
                    } else {
                        $sender->sendMessage("§cAzusaWordScramble is already enabled.");
                    }
                } elseif ($args[0] === "off") {
                    if ($this->scrambleEnabled) {
                        $this->scrambleEnabled = false;
                        if ($this->taskHandler !== null) {
                            $this->taskHandler->cancel();
                            $this->taskHandler = null;
                        }
                        $sender->sendMessage("§cAzusaWordScramble questions have been disabled.");
                    } else {
                        $sender->sendMessage("§cAzusaWordScramble is already disabled.");
                    }
                } else {
                    $sender->sendMessage("§eUsage: /wordscramble [on/off]");
                }
            } else {
                $sender->sendMessage("§eUsage: /wordscramble [on/off]");
            }
            return true;
        }
        return false;
    }    

    public function isScrambleEnabled(): bool {
        return $this->scrambleEnabled;
    }

    public static function getEconomyProvider(): ?EconomyProvider {
        return self::$economyProvider;
    }

    public function getConfigData(): Config {
        return $this->config;
    }

    public function setCurrentWord(?string $word): void {
        $this->currentWord = $word;
    }

    public function getCurrentWord(): ?string {
        return $this->currentWord;
    }

    public function getTaskHandler(): ?TaskHandler {
        return $this->taskHandler;
    }

    public function setTaskHandler(?TaskHandler $handler): void {
        $this->taskHandler = $handler;
    }

    public function getCurrentQuestionStartTime(): int {
        return $this->currentQuestionStartTime;
    }

    public function setCurrentQuestionStartTime(int $time): void {
        $this->currentQuestionStartTime = $time;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        if ($this->scrambleEnabled && $this->taskHandler === null) {
            $this->scheduleFirstWordScramble();
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        if (count($this->getServer()->getOnlinePlayers()) === 1 && $this->taskHandler !== null) {
            $this->taskHandler->cancel();
            $this->taskHandler = null;
        }
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        if ($this->currentWord !== null) {
            $message = $event->getMessage();
            if (strtolower($message) === strtolower($this->currentWord)) {
                $player = $event->getPlayer();
                $reward = rand($this->config->get("prize_min"), $this->config->get("prize_max"));
                $this->addMoney($player, $reward);
                $this->getServer()->broadcastMessage(str_replace("{player}", $player->getName(), str_replace("{money}", $reward, $this->config->get("scramble_completion_message"))));
                $this->currentWord = null;
                $event->cancel();

                // Play level-up sound effect for all players
                $this->broadcastSound($player, "random.levelup");

                // Cancel the current task to prevent the no-answer message from appearing
                if ($this->taskHandler !== null) {
                    $this->taskHandler->cancel();
                    $this->taskHandler = null;
                }

                // Schedule next question after the delay
                $this->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
                    private $plugin;

                    public function __construct(Main $plugin) {
                        $this->plugin = $plugin;
                    }

                    public function onRun(): void {
                        if ($this->plugin->isScrambleEnabled() && count($this->plugin->getServer()->getOnlinePlayers()) > 0) {
                            $this->plugin->broadcastWordScramble();
                        }
                    }
                }, 20 * $this->getConfigData()->get("scramble_delay_solved"));
            }
        }
    }

    private function addMoney(Player $player, int $amount): void {
        $provider = self::getEconomyProvider();
        if ($provider !== null) {
            $provider->giveMoney($player, $amount); // Correct method to add money
        }
    }

    public function broadcastWordScramble(): void {
        if ($this->scrambleEnabled && count($this->getServer()->getOnlinePlayers()) > 0) {
            $word = $this->generateScrambledWord();
            $this->getServer()->broadcastMessage("Arrange them into the correct word! §e{$word['scrambled']} §f?");
            $this->setCurrentWord($word['original']);
            $this->setCurrentQuestionStartTime(time());

            // Play bell sound effect
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $this->playSound($player, "note.bell");
            }

            // Schedule the no-answer message task
            $this->scheduleNoAnswerMessageTask();
        }
    }

    public function generateScrambledWord(): array {
        return WordTask::generateScrambledWord($this);
    }

    public function scheduleFirstWordScramble(): void {
        $this->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
            private $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                if ($this->plugin->isScrambleEnabled() && count($this->plugin->getServer()->getOnlinePlayers()) > 0) {
                    $this->plugin->broadcastWordScramble();
                }
            }
        }, 20); // 1 second delay
    }

    public function scheduleNoAnswerMessageTask(): void {
        $this->setTaskHandler($this->getScheduler()->scheduleDelayedTask(new class($this) extends Task {
            private $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                if ($this->plugin->isScrambleEnabled() && count($this->plugin->getServer()->getOnlinePlayers()) > 0) {
                    if ($this->plugin->getCurrentWord() !== null) {
                        $this->plugin->getServer()->broadcastMessage($this->plugin->getConfigData()->get("no_answer_message"));
                        $this->plugin->setCurrentWord(null);

                        // Play bass attack sound effect
                        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                            $this->plugin->playSound($player, "note.bassattack");
                        }

                        // Schedule the next question after the delay
                        $this->plugin->getScheduler()->scheduleDelayedTask(new class($this->plugin) extends Task {
                            private $plugin;

                            public function __construct(Main $plugin) {
                                $this->plugin = $plugin;
                            }

                            public function onRun(): void {
                                if ($this->plugin->isScrambleEnabled() && count($this->plugin->getServer()->getOnlinePlayers()) > 0) {
                                    $this->plugin->broadcastWordScramble();
                                }
                            }
                        }, 20 * $this->plugin->getConfigData()->get("scramble_delay_solved"));
                    }
                }
            }
        }, 20 * $this->getConfigData()->get("scramble_interval")));
    }

    public function getWords(): array {
        return $this->words;
    }

    public function playSound(Player $player, string $soundName): void {
        $pk = new PlaySoundPacket();
        $pk->soundName = $soundName;
        $pk->x = $player->getPosition()->getX();
        $pk->y = $player->getPosition()->getY();
        $pk->z = $player->getPosition()->getZ();
        $pk->volume = 1;
        $pk->pitch = 1;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function broadcastSound(Player $originPlayer, string $soundName): void {
        $pk = new PlaySoundPacket();
        $pk->soundName = $soundName;
        $pk->x = $originPlayer->getPosition()->getX();
        $pk->y = $originPlayer->getPosition()->getY();
        $pk->z = $originPlayer->getPosition()->getZ();
        $pk->volume = 1;
        $pk->pitch = 1;
        
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $player->getNetworkSession()->sendDataPacket($pk);
        }
    }
}