<?php

namespace Artsakhskiyy\AuthUltraUI;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\utils\TextFormat;

/** @phpstan-ignore-next-line */
use jojoe77777\FormAPI\CustomForm;

class Main extends PluginBase implements Listener {

    private Config $data;
    private Config $config;
    private array $lockedPlayers = [];

    public function onEnable(): void {
        if ($this->getServer()->getPluginManager()->getPlugin("FormAPI") === null) {
            $this->getLogger()->critical("FormAPI is required for AuthUltraUI!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        @mkdir($this->getDataFolder());
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        $this->applyLock($player);

        if (!$this->data->exists($name)) {
            $this->sendRegisterForm($player);
        } else {
            $interval = (int)$this->config->get("login-interval", 60);
            $last = (int)$this->data->getNested("$name.lastLogin", 0);

            if (time() - $last > $interval) {
                $this->sendLoginForm($player);
            } else {
                $player->sendMessage(TextFormat::GREEN . $this->getMessage("already-auth"));
                $this->removeLock($player);
            }
        }
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->lockedPlayers[$player->getName()])) {
            $event->cancel();
        }
    }

    private function sendRegisterForm(Player $player): void {
        /** @phpstan-ignore-next-line */
        $form = new CustomForm(function (Player $p, ?array $data) {
            if ($data === null || trim($data[0]) === "") {
                $p->sendMessage(TextFormat::RED . $this->getMessage("register-error-empty"));
                $this->sendRegisterForm($p);
                return;
            }

            $password = $data[0];

            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $password)) {
                $p->sendMessage(TextFormat::RED . $this->getMessage("register-error-invalid"));
                $this->sendRegisterForm($p);
                return;
            }

            $min = (int)$this->config->get("password-min-length", 4);
            $max = (int)$this->config->get("password-max-length", 16);
            $len = strlen($password);
            if ($len < $min || $len > $max) {
                $p->sendMessage(TextFormat::RED . str_replace(["{min}", "{max}"], [$min, $max], $this->getMessage("register-error-length")));
                $this->sendRegisterForm($p);
                return;
            }

            $name = strtolower($p->getName());
            if ($this->data->exists($name)) {
                $p->sendMessage(TextFormat::RED . $this->getMessage("register-error-exists"));
                return;
            }

            $this->data->setNested("$name.password", password_hash($password, PASSWORD_DEFAULT));
            $this->data->setNested("$name.lastLogin", time());
            $this->data->save();

            $p->sendMessage(TextFormat::GREEN . $this->getMessage("register-success"));
            $this->removeLock($p);
        });

        /** @phpstan-ignore-next-line */
        $form->setTitle($this->getMessage("register-title"));
        $form->addInput($this->getMessage("register-content"), "Password");
        $form->sendToPlayer($player);
    }

    private function sendLoginForm(Player $player): void {
        /** @phpstan-ignore-next-line */
        $form = new CustomForm(function (Player $p, ?array $data) {
            if ($data === null || trim($data[0]) === "") {
                $p->sendMessage(TextFormat::RED . $this->getMessage("login-error-empty"));
                $this->sendLoginForm($p);
                return;
            }

            $password = $data[0];
            $name = strtolower($p->getName());
            $hash = $this->data->getNested("$name.password");

            if (!password_verify($password, $hash)) {
                $p->sendMessage(TextFormat::RED . $this->getMessage("login-error-wrong"));
                $this->sendLoginForm($p);
                return;
            }

            $this->data->setNested("$name.lastLogin", time());
            $this->data->save();

            $p->sendMessage(TextFormat::GREEN . $this->getMessage("login-success"));
            $this->removeLock($p);
        });

        /** @phpstan-ignore-next-line */
        $form->setTitle($this->getMessage("login-title"));
        $form->addInput($this->getMessage("login-content"), "Password");
        $form->sendToPlayer($player);
    }

    private function applyLock(Player $player): void {
        $effect = new EffectInstance(VanillaEffects::BLINDNESS(), 999999, 1, false);
        $player->getEffects()->add($effect);
        $this->lockedPlayers[$player->getName()] = true;
    }

    private function removeLock(Player $player): void {
        $player->getEffects()->clear();
        unset($this->lockedPlayers[$player->getName()]);
    }

    private function getMessage(string $key): string {
        return (string)$this->config->getNested("messages.$key", $key);
    }
}
