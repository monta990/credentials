<?php

declare(strict_types=1);

/**
 * Regression contract for GLPI 12 native inventory and Management item support.
 * This test isolates CredentialItem's type registration; live GLPI UI/DB
 * integration still needs validation in a GLPI 12 instance.
 */
namespace {
    class CommonGLPI {}

    class CommonDBTM extends CommonGLPI
    {
        /** @var array<string, mixed> */
        public array $fields = [];

        public function isEntityAssign(): bool
        {
            return array_key_exists('entities_id', $this->fields);
        }

        public function getEntityID(): int
        {
            return $this->isEntityAssign() ? (int) $this->fields['entities_id'] : -1;
        }
    }

    // Match GLPI's child-item inheritance used by Database, which derives its
    // entity from its DatabaseInstance parent when entities_id is not duplicated.
    class CommonDBConnexity extends CommonDBTM {}
    class CommonDBChild extends CommonDBConnexity
    {
        public ?int $parentEntityId = null;

        public function isEntityAssign(): bool
        {
            return parent::isEntityAssign() || $this->parentEntityId !== null;
        }

        public function getEntityID(): int
        {
            return parent::isEntityAssign() ? parent::getEntityID() : ($this->parentEntityId ?? -1);
        }
    }

    class User extends CommonDBTM {}
    class Line extends CommonDBTM {}
    class Domain extends CommonDBTM {}
    class Cluster extends CommonDBTM {}
    class Datacenter extends CommonDBTM {}
    class DCRoom extends CommonDBTM {}
    class DatabaseInstance extends CommonDBTM {}
    class Database extends CommonDBChild {}
    class PassiveDCEquipment extends CommonDBTM {}
    class PDU extends CommonDBTM {}
    class Rack extends CommonDBTM {}
    class Enclosure extends CommonDBTM {}
    class Cable extends CommonDBTM {}
    class Computer extends CommonDBTM {}
    abstract class AbstractAsset extends CommonDBTM {}

    class Plugin
    {
        /** @var array<int, array{class: string, options: array<string, mixed>}> */
        public static array $registrations = [];

        public static function registerClass(string $class, array $options = []): void
        {
            self::$registrations[] = ['class' => $class, 'options' => $options];
        }
    }
}

namespace Glpi\Plugin {
    final class Hooks
    {
        public const ITEM_PURGE = 'item_purge';
    }
}

namespace {
    require_once __DIR__ . '/../../src/CredentialItem.php';

    $CFG_GLPI = ['asset_types' => [Computer::class, AbstractAsset::class]];
    $PLUGIN_HOOKS = [];

    $types = \GlpiPlugin\Credentials\CredentialItem::getSupportedItemtypes();
    $expected = [
        User::class,
        Line::class,
        Domain::class,
        Cluster::class,
        Datacenter::class,
        DCRoom::class,
        DatabaseInstance::class,
        Database::class,
        PassiveDCEquipment::class,
        PDU::class,
        Rack::class,
        Enclosure::class,
        Cable::class,
        Computer::class,
    ];
    if ($types !== $expected) {
        throw new RuntimeException('Supported itemtypes should include User, the explicitly supported GLPI inventory/Management types, and concrete asset_types only.');
    }

    if (!\GlpiPlugin\Credentials\CredentialItem::isSupportedItemtype(Line::class)) {
        throw new RuntimeException('GLPI Line must be supported even when absent from asset_types.');
    }

    $line = new Line();
    $line->fields = ['id' => 45, 'entities_id' => 9];
    if (\GlpiPlugin\Credentials\CredentialItem::resolveParentEntityID($line) !== 9) {
        throw new RuntimeException('Phone-line entity must be resolved through CommonDBTM entity assignment.');
    }

    if (!\GlpiPlugin\Credentials\CredentialItem::isSupportedItemtype(Domain::class)) {
        throw new RuntimeException('GLPI Domain must be supported even when it is absent from asset_types.');
    }
    $domain = new Domain();
    $domain->fields = ['id' => 73, 'entities_id' => 12, 'is_recursive' => 1];
    if (\GlpiPlugin\Credentials\CredentialItem::resolveParentEntityID($domain) !== 12) {
        throw new RuntimeException('Domain entity must be resolved through CommonDBTM entity assignment.');
    }

    $database = new Database();
    $database->fields = ['id' => 88, 'name' => 'ERP database'];
    $database->parentEntityId = 21;
    if (!\GlpiPlugin\Credentials\CredentialItem::isSupportedItemtype(Database::class)
        || \GlpiPlugin\Credentials\CredentialItem::resolveParentEntityID($database) !== 21
    ) {
        throw new RuntimeException('GLPI Database child records must be supported and inherit the parent DatabaseInstance entity.');
    }

    foreach ([
        Cluster::class,
        Datacenter::class,
        DCRoom::class,
        DatabaseInstance::class,
        PassiveDCEquipment::class,
        PDU::class,
        Rack::class,
        Enclosure::class,
        Cable::class,
    ] as $itemtype) {
        if (!\GlpiPlugin\Credentials\CredentialItem::isSupportedItemtype($itemtype)) {
            throw new RuntimeException('Expected native GLPI infrastructure itemtype to be supported: ' . $itemtype);
        }
    }

    \GlpiPlugin\Credentials\CredentialItem::registerTabs();
    $registration = Plugin::$registrations[0] ?? null;
    if (($registration['class'] ?? null) !== \GlpiPlugin\Credentials\CredentialItem::class) {
        throw new RuntimeException('Credentials tab class was not registered.');
    }
    if (($registration['options']['addtabon'] ?? []) !== $expected) {
        throw new RuntimeException('Credentials tab registration must include each supported management item exactly once.');
    }
    $explicit_itemtypes = [
        Line::class,
        Domain::class,
        Cluster::class,
        Datacenter::class,
        DCRoom::class,
        DatabaseInstance::class,
        Database::class,
        PassiveDCEquipment::class,
        PDU::class,
        Rack::class,
        Enclosure::class,
        Cable::class,
    ];
    foreach ($explicit_itemtypes as $itemtype) {
        if (($PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_PURGE]['credentials'][$itemtype] ?? null) !== 'plugin_credentials_item_purge') {
            throw new RuntimeException('Permanent purge hook must be registered for GLPI item ' . $itemtype . '.');
        }
    }

    echo "GLPI inventory and Management item support contract: OK\n";
}
