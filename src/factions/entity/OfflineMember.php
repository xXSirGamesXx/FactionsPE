<?php
/*
 *   FactionsPE: PocketMine-MP Plugin
 *   Copyright (C) 2016  Chris Prime
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace factions\entity;

use factions\data\MemberData;
use factions\FactionsPE;
use factions\manager\Factions;
use factions\manager\Members;
use factions\manager\Permissions;
use factions\permission\Permission;
use factions\relation\Relation;
use factions\relation\RelationParticipator;
use factions\utils\Gameplay;
use factions\utils\Text;
use localizer\Localizer;
use pocketmine\Player;

class OfflineMember extends MemberData implements IMember, RelationParticipator
{

    public function __construct(string $name, array $data = [])
    {
        $sd = FactionsPE::get()->getDataProvider()->loadMember($name);
        parent::__construct(array_merge($data, compact("name"), $sd ? $sd->__toArray() : []));
        $this->updateFaction();
    }

    /*
     * ----------------------------------------------------------
     * FACTION
     * ----------------------------------------------------------
     */

    public function updateFaction()
    {
        if (($f = Factions::getForMember($this)) instanceof Faction) {
            $this->setFactionId($f->getId(), true);
            $this->setRole($f->getRole($this));
        }
    }

    public function setFactionId(string $fid, bool $silent = false)
    {
        // Detect Nochange
        if ($fid === $this->factionId) return;
        // Get the raw old value
        $oldFactionId = $this->factionId;
        // Apply
        $this->factionId = $fid;
        if ($oldFactionId == null) $oldFactionId = Faction::NONE;
        // Update index
        $oldFaction = Factions::getById($oldFactionId);
        $faction = $this->getFaction();
        $oldFactionIdDesc = "NULL";
        $oldFactionNameDesc = "NULL";
        if ($oldFaction != null) {
            $oldFactionIdDesc = $oldFaction->getId();
            $oldFactionNameDesc = $oldFaction->getName();
        }
        $factionIdDesc = "NULL";
        $factionNameDesc = "NULL";
        if ($faction != null) {
            $factionIdDesc = $faction->getId();
            $factionNameDesc = $faction->getName();
        }
        if (Gameplay::get('log.member-faction-change', true) && !$silent) {
            FactionsPE::get()->getLogger()->info(Localizer::trans("log.member-faction-changed", [$this->getDisplayName(), $this->getName(), $oldFactionIdDesc, $oldFactionNameDesc, $factionIdDesc, $factionNameDesc]));
        }
    }

    public function getFaction(): Faction
    {
        return Factions::getById($this->getFactionId()) ?? Factions::getById(Faction::NONE);
    }

    public function getFactionId(): string
    {
        if (!$this->factionId) return Faction::NONE;
        return $this->factionId;
    }

    public function getDisplayName(): string
    {
        return $this->isOnline() ? $this->player->getDisplayName() : $this->getName();
    }

    public function isOnline(): bool
    {
        return $this->player ? $this->player->isOnline() : false;
    }

    public function setRole(string $role)
    {
        $this->role = $role;
    }

    public function setFaction(Faction $faction)
    {
        $this->setFactionId($faction->getId());
    }

    public function hasFaction(): bool
    {
        return $this->getFaction()->isNormal();
    }

    /*
     * ----------------------------------------------------------
     * ROLE
     * ----------------------------------------------------------
     */

    public function isDefault(): bool
    {
        return false; # TODO
    }

    public function resetFactionData()
    {
        $this->factionId = null;
        $this->role = null;
        $this->title = null;
    }

    public function isRecruit(): bool
    {
        return $this->getRole() === Relation::RECRUIT;
    }

    public function getRole(): string
    {
        return $this->role ?? Relation::NONE;
    }

    public function isMember(): bool
    {
        return $this->getRole() === Relation::MEMBER;
    }

    public function isOfficer(): bool
    {
        return $this->getRole() === Relation::OFFICER;
    }

    /*
     * ----------------------------------------------------------
     * RELATION
     * ----------------------------------------------------------
     */

    public function isLeader(): bool
    {
        return $this->getRole() === Relation::LEADER;
    }

    public function isFriend(RelationParticipator $observer, bool $ignorePeaceful = false): bool
    {
        return Relation::isFriend($this->getRelationTo($observer, $ignorePeaceful));
    }

    public function getRelationTo(RelationParticipator $observer, bool $ignorePeaceful = false): string
    {
        return Relation::getRelationOfThatToMe($this, $observer, $ignorePeaceful);
    }

    public function isEnemy(RelationParticipator $observer, bool $ignorePeaceful = false): bool
    {
        return Relation::isEnemy($this->getRelationTo($observer, $ignorePeaceful));
    }


    /*
     * ----------------------------------------------------------
     * POWER
     * ----------------------------------------------------------
     */

    public function getColorTo(RelationParticipator $observer, bool $ignorePeaceful = false): string
    {
        return Relation::getColor($this->getRelationTo($observer, $ignorePeaceful));
    }

    public function getPower(bool $limit = true): int
    {
        $p = $this->power;
        if ($limit) {
            $p = min(
                max(
                    $this->power,
                    $this->getPowerMin()
                ),
                $this->getPowerMax()
            );
        }
        return $p - $this->getPowerBoost();
    }

    public function getPowerMin(): int
    {
        return (int)Gameplay::get('power.player.min', -100);
    }

    public function getPowerMax(): int
    {
        return (int)Gameplay::get('power.player.max', 100);
    }

    public function getPowerBoost(): int
    {
        return $this->powerBoost;
    }

    public function getDefaultPower(): int
    {
        return (int)Gameplay::get('power.player.default', 10);
    }

    public function getPowerPerDeath(): int
    {
        return (int)Gameplay::get('power.player.per-death', 5);
    }

    public function hasPowerBoost(): bool
    {
        return $this->powerBoost !== 0;
    }

    public function setPowerBoost(int $boost)
    {
        $this->powerBoost = $boost;
    }

    /*
     * ----------------------------------------------------------
     * PERMISSION
     * ----------------------------------------------------------
     */

    public function setPower(int $power)
    {
        $this->power = $power;
    }

    public function isPermitted(Permission $permission): bool
    {
        return $this->getFaction()->isPermitted($this->getRole(), $permission);
    }

    public function isOverriding(): bool
    {
        if ($this->overriding === NULL) return false;
        if ($this->overriding === FALSE) return false;
        if ($this->getPlayer() instanceof Player && !$this->getPlayer()->hasPermission(Permissions::OVERRIDE)) {
            $this->setOverriding(false);
            return false;
        }
        return true;
    }

    /*
     * ----------------------------------------------------------
     * PLAYER
     * ----------------------------------------------------------
     */

    public function setOverriding(bool $overriding)
    {
        $this->overriding = $overriding;
    }

    public function getNameTag(): string
    {
        return $this->player ? $this->player->getNameTag() : $this->getName();
    }

    public function isNormal(): bool
    {
        return !$this->isNone();
    }

    public function isNone(): bool
    {
        return $this->factionId === Faction::NONE;
    }

    public function sendMessage($message)
    {
        if (!$this->player) return;
        $this->player->sendMessage($message);
    }

    /*
     * ----------------------------------------------------------
     * LAST-ACTIVITY
     * ----------------------------------------------------------
     */

    public function updateLastActivity()
    {
        $this->setLastActivity(time());
    }

}
