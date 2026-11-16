<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    const READ = 1;
    const UPDATE = 2;
    const DELETE = 4;
    function getEntitiesRestrictCriteria(string $table = '', string $field = '', int|array|string $value = '', bool|string $is_recursive = false): array { return []; }

    final class Session
    {
        public static function haveRight(string $right, int $mode): bool { return true; }
        public static function haveAccessToEntity(int $entity): bool { return $entity === 4; }
    }

    final class FakeIterator implements \IteratorAggregate
    {
        public function __construct(private array $rows) {}
        public function getIterator(): \Traversable { yield from $this->rows; }
    }

    final class FakeDB
    {
        public array $requests = [];
        public array $rows = [];
        public function request(array $criteria): FakeIterator
        {
            $this->requests[] = $criteria;
            if (($criteria['FROM'] ?? '') !== 'glpi_plugin_credentials_credentials') throw new RuntimeException('Unexpected DB table');
            $last = (int) ($criteria['WHERE']['id'][1] ?? 0);
            $rows = array_values(array_filter($this->rows, static fn(array $r): bool => $r['id'] > $last));
            usort($rows, static fn(array $a,array $b): int => $a['id'] <=> $b['id']);
            return new FakeIterator(array_slice($rows, 0, (int) ($criteria['LIMIT'] ?? count($rows))));
        }
    }
}

namespace GlpiPlugin\Credentials {
    final class Credential
    {
        public static string $rightname = 'plugin_credentials';
        public static function getTable(): string { return 'glpi_plugin_credentials_credentials'; }
    }
    final class CredentialCategory
    {
        public static function getOptions(int $entity): array { return []; }
    }
    final class CredentialHistory {}
    final class CredentialItem {}
    final class CredentialItemLink
    {
        public static function getLinkCounts(array $ids): array { return []; }
        public static function getLinksForCredentials(array $ids): array { return []; }
    }
    final class CredentialStatus
    {
        public static function fromDates(?string $expires, ?string $review, string $today): array
        {
            return ['key'=>'current','label'=>'Current','expired'=>false,'expiring'=>false,'review_overdue'=>false,'review_due'=>false];
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/Service/CredentialAdminService.php';
    $DB = new FakeDB();
    for ($i = 1; $i <= 60; $i++) {
        $DB->rows[] = [
            'id' => $i,
            'name' => 'Archived ' . $i,
            'entities_id' => 4,
            'is_deleted' => 1,
            'date_mod' => '2026-11-01 10:00:00',
            'date_expires' => null,
            'date_next_review' => null,
            'plugin_credentials_categories_id' => 0,
        ];
    }

    $first = \GlpiPlugin\Credentials\Service\CredentialAdminService::dashboard(4, 0, 1);
    $second = \GlpiPlugin\Credentials\Service\CredentialAdminService::dashboard(4, 0, 2);
    $third = \GlpiPlugin\Credentials\Service\CredentialAdminService::dashboard(4, 0, 3);

    if ($first['counts']['archived'] !== 60 || $first['archived_total'] !== 60 || $first['archived_pages'] !== 3) {
        throw new RuntimeException('Archive total and page count must cover all accessible archived credentials.');
    }
    if (array_column($first['archived'], 'id') !== range(1, 25)
        || array_column($second['archived'], 'id') !== range(26, 50)
        || array_column($third['archived'], 'id') !== range(51, 60)
    ) {
        throw new RuntimeException('Archive pagination must return each archived row exactly once in primary-key order.');
    }
    foreach ($DB->requests as $query) {
        if (($query['LIMIT'] ?? 0) > 500 || ($query['WHERE']['id'][0] ?? '') !== '>') {
            throw new RuntimeException('Dashboard scan must use bounded keyset pages.');
        }
    }

    echo "Dashboard keyset scan and archived pagination regression: OK\n";
}
