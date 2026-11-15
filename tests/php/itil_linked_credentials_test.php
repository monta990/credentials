<?php

declare(strict_types=1);

/**
 * Isolated regression test for the Problem and Change linked-credential tabs.
 * It exercises native relation mapping, item access filtering, deduplication,
 * sudo-mode counting and tab registration without requiring a running GLPI DB.
 */
namespace {
    const READ = 1;

    function __(string $message, ?string $domain = null): string
    {
        return $message;
    }

    class CommonGLPI {}

    class CommonDBTM extends CommonGLPI
    {
        public array $fields = [];
        public bool $canRead = true;
        public bool $requiresReauth = false;
        public function __construct(array $fields = []) { $this->fields = $fields; }
        public function getID(): int { return (int) ($this->fields['id'] ?? 0); }
        public function getName(): string { return (string) ($this->fields['name'] ?? ''); }
        public static function getTypeName($nb = 0): string { return static::TYPE_NAME; }
        public function getFromDB(int $id): bool { return $this->getID() === $id; }
        public function can($id, $right, &$input = null, &$reauth_needed = null): bool
        {
            $reauth_needed = !$this->canRead && $this->requiresReauth;
            return $this->canRead;
        }
        public function isEntityAssign(): bool { return array_key_exists('entities_id', $this->fields); }
        public function getEntityID(): int { return (int) ($this->fields['entities_id'] ?? 0); }
        public function getLinkURL(): string { return '/front/' . static::class . '.form.php?id=' . $this->getID(); }
    }

    class Problem extends CommonDBTM { public const TYPE_NAME = 'Problem'; }
    class Change extends CommonDBTM { public const TYPE_NAME = 'Change'; }
    class Computer extends CommonDBTM { public const TYPE_NAME = 'Computer'; }
    class NetworkEquipment extends CommonDBTM { public const TYPE_NAME = 'Network equipment'; }
    class Item_Problem { public static function getTable(): string { return 'glpi_items_problems'; } }
    class Change_Item { public static function getTable(): string { return 'glpi_changes_items'; } }

    class Plugin
    {
        public static array $registrations = [];
        public static function registerClass(string $class, array $options = []): void
        {
            self::$registrations[] = ['class' => $class, 'options' => $options];
        }
    }

    class Session
    {
        public static function haveRight(string $right, int $value): bool { return true; }
    }

    class FakeDB
    {
        public array $rows = [
            'glpi_items_problems' => [
                ['problems_id' => 7, 'itemtype' => Computer::class, 'items_id' => 1],
                ['problems_id' => 7, 'itemtype' => Computer::class, 'items_id' => 1], // duplicate relation
                ['problems_id' => 7, 'itemtype' => Computer::class, 'items_id' => 2],
                ['problems_id' => 7, 'itemtype' => Computer::class, 'items_id' => 3], // sudo required
                ['problems_id' => 7, 'itemtype' => 'UnknownType', 'items_id' => 4],
            ],
            'glpi_changes_items' => [
                ['changes_id' => 9, 'itemtype' => NetworkEquipment::class, 'items_id' => 4],
            ],
        ];
        public function request(array $query): array
        {
            $rows = $this->rows[$query['FROM']] ?? [];
            $field = array_key_first($query['WHERE']);
            $value = $query['WHERE'][$field];
            return array_values(array_filter($rows, static fn(array $row): bool => (int) $row[$field] === (int) $value));
        }
    }

    $DB = new FakeDB();
    $GLOBALS['DB'] = $DB;
    $itemMap = [
        Computer::class . '#1' => new Computer(['id' => 1, 'name' => 'ERP server', 'entities_id' => 2]),
        Computer::class . '#2' => new Computer(['id' => 2, 'name' => 'Backup server', 'entities_id' => 2]),
        Computer::class . '#3' => new Computer(['id' => 3, 'name' => 'Restricted server', 'entities_id' => 2]),
        NetworkEquipment::class . '#4' => new NetworkEquipment(['id' => 4, 'name' => 'Core switch', 'entities_id' => 2]),
    ];
    $itemMap[Computer::class . '#3']->canRead = false;
    $itemMap[Computer::class . '#3']->requiresReauth = true;

    function getItemForItemtype(string $itemtype): ?CommonDBTM
    {
        global $itemMap;
        // Return a fresh GLPI-style item object; getFromDB() resolves the requested ID.
        foreach ($itemMap as $key => $item) {
            if (str_starts_with($key, $itemtype . '#')) {
                return new class($itemtype, $itemMap) extends CommonDBTM {
                    private string $type;
                    private array $map;
                    public function __construct(string $type, array $map) { $this->type = $type; $this->map = $map; }
                    public function getFromDB(int $id): bool
                    {
                        $source = $this->map[$this->type . '#' . $id] ?? null;
                        if (!$source) return false;
                        $this->fields = $source->fields;
                        $this->canRead = $source->canRead;
                        $this->requiresReauth = $source->requiresReauth;
                        return true;
                    }
                    public function can($id, $right, &$input = null, &$reauth_needed = null): bool
                    {
                        $reauth_needed = !$this->canRead && $this->requiresReauth;
                        return $this->canRead;
                    }
                    public function getName(): string { return (string) ($this->fields['name'] ?? ''); }
                };
            }
        }
        return null;
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static string $rightname = 'plugin_credentials';
        public static function getActiveIDsForItem(string $itemtype, int $items_id, int $entity_id): array
        {
            return $itemtype === \Computer::class && $items_id === 3 ? [103] : [];
        }
        public static function getLabelsForItem(string $itemtype, int $items_id, int $entity_id): array
        {
            if ($itemtype === \Computer::class && $items_id === 1) {
                return [['id' => 101, 'name' => 'ERP access', 'date_mod' => '2026-11-14']];
            }
            if ($itemtype === \Computer::class && $items_id === 2) {
                return [
                    ['id' => 101, 'name' => 'ERP access', 'date_mod' => '2026-11-14'],
                    ['id' => 102, 'name' => 'Backup access', 'date_mod' => null],
                ];
            }
            if ($itemtype === \NetworkEquipment::class && $items_id === 4) {
                return [['id' => 104, 'name' => 'Switch admin', 'date_mod' => null]];
            }
            return [];
        }
    }

    final class CredentialItem
    {
        public static function isSupportedItemtype(string $itemtype): bool
        {
            return in_array($itemtype, [\Computer::class, \NetworkEquipment::class], true);
        }
        public static function resolveParentEntityID(\CommonDBTM $item): int
        {
            return $item->getEntityID();
        }
    }

    final class Security
    {
        public static function routeUrl(string $path, array $query = []): string
        {
            return '/plugins/credentials/' . $path . '?' . http_build_query($query);
        }
    }
}

namespace {
    require_once __DIR__ . '/../../src/CredentialTicketAggregator.php';
    require_once __DIR__ . '/../../src/CredentialITILTab.php';

    $parents = \GlpiPlugin\Credentials\CredentialITILTab::getSupportedParents();
    if (array_keys($parents) !== [Problem::class, Change::class]) {
        throw new RuntimeException('Problem and Change must be the only supported ITIL parent types.');
    }
    if ($parents[Problem::class] !== ['relation' => Item_Problem::class, 'parent_field' => 'problems_id']) {
        throw new RuntimeException('Problem must use the native Item_Problem relation.');
    }
    if ($parents[Change::class] !== ['relation' => Change_Item::class, 'parent_field' => 'changes_id']) {
        throw new RuntimeException('Change must use the native Change_Item relation.');
    }

    \GlpiPlugin\Credentials\CredentialITILTab::register();
    $registration = Plugin::$registrations[0] ?? [];
    if (($registration['class'] ?? null) !== \GlpiPlugin\Credentials\CredentialITILTab::class
        || ($registration['options']['addtabon'] ?? []) !== [Problem::class, Change::class]
    ) {
        throw new RuntimeException('Credentials tab must be registered on both Problems and Changes.');
    }

    $problem = new Problem(['id' => 7, 'name' => 'ERP outage']);
    $problem_rows = \GlpiPlugin\Credentials\CredentialITILTab::getCredentialRows($problem);
    if ($problem_rows['count'] !== 3 || count($problem_rows['rows']) !== 2 || !$problem_rows['requires_reauth']) {
        throw new RuntimeException('Problem aggregation must deduplicate visible credentials and count sudo-protected ones.');
    }
    $erp = array_values(array_filter($problem_rows['rows'], static fn(array $row): bool => $row['id'] === 101))[0] ?? null;
    if (!$erp || count($erp['sources']) !== 2 || !str_contains($erp['detail_url'], 'itemtype=Computer')) {
        throw new RuntimeException('Problem aggregation must list all source items for a shared credential.');
    }

    $change = new Change(['id' => 9, 'name' => 'Core switch maintenance']);
    $change_rows = \GlpiPlugin\Credentials\CredentialITILTab::getCredentialRows($change);
    if ($change_rows['count'] !== 1 || count($change_rows['rows']) !== 1 || $change_rows['requires_reauth']) {
        throw new RuntimeException('Change aggregation must resolve its native associated item and credential.');
    }
    if ($change_rows['rows'][0]['sources'] !== ['Network equipment: Core switch']) {
        throw new RuntimeException('Change source labels must identify the linked inventory item.');
    }

    $controller_source = file_get_contents(__DIR__ . '/../../src/Controller/ITILCredentialsController.php');
    $count_source = file_get_contents(__DIR__ . '/../../src/Controller/CredentialController.php');
    $setup_source = file_get_contents(__DIR__ . '/../../setup.php');
    if (
        !is_string($controller_source)
        || !str_contains($controller_source, 'in_array($parent_itemtype, $supported_parent_types, true)')
        || !str_contains($controller_source, 'checkReAuthenticationOrRedirect')
        || !str_contains($count_source, 'CredentialITILTab::getCredentialRows($parent)')
        || !str_contains($setup_source, 'CredentialITILTab::register()')
    ) {
        throw new RuntimeException('Secure ITIL listing, aggregate count route or plugin tab registration is missing.');
    }

    echo "Problem and Change linked-credential integration contract: OK\n";
}
