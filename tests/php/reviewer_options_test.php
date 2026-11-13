<?php

declare(strict_types=1);

namespace Glpi\Controller {
    abstract class AbstractController {}
}

namespace {
    define('READ', 1);

    final class Session
    {
        public static array $activeEntities = [1, 2];
        public static bool $canReadUsers = true;

        public static function haveRight(string $right, int $level): bool
        {
            return $right === 'user' && $level === READ && self::$canReadUsers;
        }

        public static function haveAccessToEntity(int $entityId): bool
        {
            return in_array($entityId, self::$activeEntities, true);
        }

        public static function getActiveEntities(): array
        {
            return self::$activeEntities;
        }
    }

    final class User
    {
        public array $fields = [];
        /** @var array<int,array<string,mixed>> */
        public static array $users = [];

        public static function getTable(): string
        {
            return 'glpi_users';
        }

        public function getFromDB(int $id): bool
        {
            if (!isset(self::$users[$id])) {
                return false;
            }
            $this->fields = self::$users[$id];
            return true;
        }
    }

    final class FakeReviewerIterator implements \IteratorAggregate
    {
        public function __construct(private array $rows) {}

        public function getIterator(): \Traversable
        {
            return new \ArrayIterator($this->rows);
        }
    }

    final class FakeReviewerDB
    {
        public array $queries = [];

        public function __construct(private array $users) {}

        public function request(array $query): FakeReviewerIterator
        {
            $this->queries[] = $query;
            $rows = $this->users;
            foreach (($query['WHERE'] ?? []) as $field => $expected) {
                $rows = array_values(array_filter(
                    $rows,
                    static function (array $row) use ($field, $expected): bool {
                        $actual = $row[$field] ?? null;
                        if (is_array($expected)) {
                            return in_array((int) $actual, array_map('intval', $expected), true);
                        }
                        return (string) $actual === (string) $expected;
                    },
                ));
            }
            usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
            return new FakeReviewerIterator($rows);
        }
    }

    function reviewerAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $users = [];
    // More than 250 reviewers in the credential's entity, plus users in another
    // active entity. All of them must remain selectable.
    for ($id = 1; $id <= 280; $id++) {
        $users[$id] = [
            'id' => $id,
            'name' => sprintf('reviewer-%03d', $id),
            'realname' => sprintf('Reviewer %03d', $id),
            'firstname' => '',
            'entities_id' => 2,
            'is_deleted' => 0,
        ];
    }
    for ($id = 281; $id <= 285; $id++) {
        $users[$id] = [
            'id' => $id,
            'name' => sprintf('other-visible-%03d', $id),
            'realname' => '',
            'firstname' => '',
            'entities_id' => 1,
            'is_deleted' => 0,
        ];
    }
    for ($id = 286; $id <= 330; $id++) {
        $users[$id] = [
            'id' => $id,
            'name' => sprintf('unavailable-%03d', $id),
            'realname' => sprintf('Unavailable %03d', $id),
            'firstname' => '',
            'entities_id' => 3,
            'is_deleted' => 0,
        ];
    }
    // This selected reviewer is intentionally outside the session's entity scope.
    $users[999] = [
        'id' => 999,
        'name' => 'private-reviewer',
        'realname' => 'Private Reviewer',
        'firstname' => '',
        'entities_id' => 3,
        'is_deleted' => 0,
    ];
    User::$users = $users;

    global $DB;
    $DB = new FakeReviewerDB(array_values($users));

    require_once __DIR__ . '/../../src/Controller/CredentialController.php';

    $reflection = new \ReflectionClass(\GlpiPlugin\Credentials\Controller\CredentialController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $optionsMethod = $reflection->getMethod('reviewerOptions');
    $optionsMethod->setAccessible(true);
    $nameMethod = $reflection->getMethod('reviewerName');
    $nameMethod->setAccessible(true);

    $options = $optionsMethod->invoke($controller, 2, 999);
    reviewerAssert(count($options) === 285, 'All 280 reviewers in the credential entity and 5 reviewers in another active entity must be returned; the former 250-user cap must not recur.');
    $optionIds = array_column($options, 'id');
    reviewerAssert(count(array_unique($optionIds)) === 285, 'Reviewer options must not contain duplicate user IDs.');
    reviewerAssert(!in_array(999, $optionIds, true), 'A selected reviewer outside the active entity scope must not be appended to the selector.');
    reviewerAssert(!in_array(286, $optionIds, true), 'Users from inaccessible entities must not be returned.');
    reviewerAssert($nameMethod->invoke($controller, 999) === '', 'The reviewer label helper must not disclose a user name from an inaccessible entity.');

    $query = $DB->queries[0] ?? [];
    reviewerAssert(($query['FROM'] ?? null) === 'glpi_users', 'The reviewer query must target GLPI users.');
    reviewerAssert(($query['WHERE']['entities_id'] ?? null) === [1, 2], 'The reviewer query must filter by the active entity scope before building options.');
    reviewerAssert(!array_key_exists('LIMIT', $query), 'The reviewer query must not reintroduce a fixed result limit.');

    $queryCount = count($DB->queries);
    Session::$activeEntities = [1];
    $outsideOptions = $optionsMethod->invoke($controller, 2, 0);
    reviewerAssert($outsideOptions === [], 'A credential entity outside the active entity scope must not yield reviewer options.');
    reviewerAssert(count($DB->queries) === $queryCount, 'An inaccessible credential entity must be rejected before querying users.');

    Session::$activeEntities = [1, 2];
    Session::$canReadUsers = false;
    $noRightOptions = $optionsMethod->invoke($controller, 2, 0);
    reviewerAssert($noRightOptions === [], 'Profiles without GLPI user READ permission must not receive reviewer options.');
    reviewerAssert(count($DB->queries) === $queryCount, 'The selector must not query users without GLPI user READ permission.');

    echo "Reviewer selector entity-scope and >250-user regression tests: OK\n";
}
