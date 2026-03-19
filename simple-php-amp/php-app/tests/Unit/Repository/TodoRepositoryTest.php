<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use SimplePhpApm\Repository\TodoRepository;
use Tests\Helper\TelemetryMockTrait;

/**
 * TodoRepository のユニットテスト
 *
 * t_wada TDD の Red-Green-Refactor サイクルに従い、
 * 「振る舞い」をテストする。実装の詳細（SQLクエリ等）はテストしない。
 *
 * テスト戦略:
 * - インメモリ SQLite を使い、外部ファイルに依存しない
 * - OTel テレメトリは TelemetryMockTrait でモック化
 * - 各テストは独立して実行可能（setUp で状態をリセット）
 */
class TodoRepositoryTest extends TestCase
{
    use TelemetryMockTrait;

    private \PDO $db;
    private TodoRepository $repository;

    protected function setUp(): void
    {
        // インメモリ SQLite でテスト用 DB を構築
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE todos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                completed INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->setUpTelemetry();
        $this->repository = new TodoRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->tearDownTelemetry();
    }

    // =========================================================
    // findAll() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function Todoが存在しない場合findAllは空の配列を返す(): void
    {
        $result = $this->repository->findAll();

        $this->assertSame([], $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 複数のTodoが存在する場合findAllは全件返す(): void
    {
        $this->repository->create('Todo 1');
        $this->repository->create('Todo 2');
        $this->repository->create('Todo 3');

        $todos = $this->repository->findAll();

        $this->assertCount(3, $todos);
    }

    // =========================================================
    // findById() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在するIDを指定するとfindByIdはTodoを返す(): void
    {
        $id = $this->repository->create('テストTodo');

        $todo = $this->repository->findById($id);

        $this->assertNotNull($todo);
        $this->assertSame($id, (int) $todo['id']);
        $this->assertSame('テストTodo', $todo['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを指定するとfindByIdはnullを返す(): void
    {
        $todo = $this->repository->findById(999);

        $this->assertNull($todo);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 新規作成直後のTodoの完了フラグはfalse(): void
    {
        $id   = $this->repository->create('新しいTodo');
        $todo = $this->repository->findById($id);

        $this->assertSame(0, (int) $todo['completed']);
    }

    // =========================================================
    // create() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function createは新しいTodoのIDを返す(): void
    {
        $id = $this->repository->create('テストTodo');

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 連続してcreateすると異なるIDが採番される(): void
    {
        $id1 = $this->repository->create('Todo 1');
        $id2 = $this->repository->create('Todo 2');

        $this->assertNotSame($id1, $id2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createしたTodoはfindAllで取得できる(): void
    {
        $this->repository->create('確認用Todo');

        $todos = $this->repository->findAll();

        $this->assertCount(1, $todos);
        $this->assertSame('確認用Todo', $todos[0]['title']);
    }

    // =========================================================
    // update() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateでTodoの完了フラグをtrueに変更できる(): void
    {
        $id = $this->repository->create('未完了Todo');
        $this->repository->update($id, true);

        $todo = $this->repository->findById($id);

        $this->assertSame(1, (int) $todo['completed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateでTodoの完了フラグをfalseに戻せる(): void
    {
        $id = $this->repository->create('完了済みTodo');
        $this->repository->update($id, true);
        $this->repository->update($id, false);

        $todo = $this->repository->findById($id);

        $this->assertSame(0, (int) $todo['completed']);
    }

    // =========================================================
    // delete() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function deleteするとTodoがfindByIdで取得できなくなる(): void
    {
        $id = $this->repository->create('削除対象Todo');
        $this->repository->delete($id);

        $todo = $this->repository->findById($id);

        $this->assertNull($todo);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function deleteすると件数が減る(): void
    {
        $id = $this->repository->create('削除対象Todo');
        $this->repository->create('残すTodo');

        $this->repository->delete($id);

        $todos = $this->repository->findAll();
        $this->assertCount(1, $todos);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 別のTodoを削除しても他のTodoには影響がない(): void
    {
        $idToDelete = $this->repository->create('削除するTodo');
        $idToKeep   = $this->repository->create('残すTodo');

        $this->repository->delete($idToDelete);

        $remaining = $this->repository->findById($idToKeep);
        $this->assertNotNull($remaining);
        $this->assertSame('残すTodo', $remaining['title']);
    }
}
