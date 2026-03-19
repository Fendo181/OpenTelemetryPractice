<?php

declare(strict_types=1);

namespace Tests\Unit\Handler;

use PHPUnit\Framework\TestCase;
use SimplePhpApm\Handler\TodoHandler;
use SimplePhpApm\Repository\TodoRepository;
use Tests\Helper\TelemetryMockTrait;

/**
 * TodoHandler のユニットテスト
 *
 * t_wada TDD: 「振る舞い」をテストし、実装の詳細（DB クエリ等）に依存しない。
 *
 * テスト戦略:
 * - TodoRepository をモックで差し替え（DB 不要）
 * - HTTP ステータスコード変更（http_response_code）は CLI では何もしないので無視
 * - 各テストメソッドは1つの振る舞いのみ検証（t_wada: テストの最小化）
 */
class TodoHandlerTest extends TestCase
{
    use TelemetryMockTrait;

    private TodoRepository $mockRepository;
    private TodoHandler $handler;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(TodoRepository::class);
        $this->setUpTelemetry();
        $this->handler = new TodoHandler($this->mockRepository);
    }

    protected function tearDown(): void
    {
        $this->tearDownTelemetry();
    }

    // =========================================================
    // list() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function listはtodosキーを持つ配列を返す(): void
    {
        $this->mockRepository->method('findAll')->willReturn([]);

        $result = $this->handler->list();

        $this->assertArrayHasKey('todos', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function Todoが0件の場合listは空の配列を返す(): void
    {
        $this->mockRepository->method('findAll')->willReturn([]);

        $result = $this->handler->list();

        $this->assertSame([], $result['todos']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function Todoが存在する場合listはそのままリポジトリの結果を返す(): void
    {
        $todos = [
            ['id' => '1', 'title' => 'Todo 1', 'completed' => '0'],
            ['id' => '2', 'title' => 'Todo 2', 'completed' => '1'],
        ];
        $this->mockRepository->method('findAll')->willReturn($todos);

        $result = $this->handler->list();

        $this->assertSame($todos, $result['todos']);
    }

    // =========================================================
    // get() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在するTodoのIDを指定するとgetはtodoキーで返す(): void
    {
        $todo = ['id' => '1', 'title' => 'テストTodo', 'completed' => '0'];
        $this->mockRepository->method('findById')->with(1)->willReturn($todo);

        $result = $this->handler->get(1);

        $this->assertArrayHasKey('todo', $result);
        $this->assertSame($todo, $result['todo']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを指定するとgetはerrorキーを返す(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);

        $result = $this->handler->get(999);

        $this->assertArrayHasKey('error', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを指定するとgetは404エラーメッセージを返す(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);

        $result = $this->handler->get(999);

        $this->assertSame('Todo not found', $result['error']);
        $this->assertSame(999, $result['id']);
    }

    // =========================================================
    // create() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function タイトルを指定するとcreateは新しいTodoを返す(): void
    {
        $this->mockRepository->method('create')->willReturn(1);

        $result = $this->handler->create(['title' => '新しいTodo']);

        $this->assertSame(1, $result['id']);
        $this->assertSame('新しいTodo', $result['title']);
        $this->assertFalse($result['completed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function タイトルが空文字の場合createはバリデーションエラーを返す(): void
    {
        $result = $this->handler->create(['title' => '']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Validation failed', $result['error']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function タイトルが空文字の場合createはTitleが必須のメッセージを返す(): void
    {
        $result = $this->handler->create(['title' => '']);

        $this->assertSame('Title is required', $result['message']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function titleキーがない場合createはバリデーションエラーを返す(): void
    {
        $result = $this->handler->create([]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Validation failed', $result['error']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function タイトルが空白のみの場合createはバリデーションエラーを返す(): void
    {
        $result = $this->handler->create(['title' => '   ']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Validation failed', $result['error']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function バリデーションエラーの場合createはリポジトリを呼ばない(): void
    {
        $this->mockRepository->expects($this->never())->method('create');

        $this->handler->create(['title' => '']);
    }

    // =========================================================
    // update() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在するTodoを更新するとupdateは更新後のTodoを返す(): void
    {
        $todo = ['id' => '1', 'title' => '更新対象Todo', 'completed' => '0'];
        $this->mockRepository->method('findById')->willReturn($todo);

        $result = $this->handler->update(1, ['completed' => true]);

        $this->assertSame(1, $result['id']);
        $this->assertSame('更新対象Todo', $result['title']);
        $this->assertTrue($result['completed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function completedをfalseで更新するとupdateはfalseを返す(): void
    {
        $todo = ['id' => '1', 'title' => '完了済みTodo', 'completed' => '1'];
        $this->mockRepository->method('findById')->willReturn($todo);

        $result = $this->handler->update(1, ['completed' => false]);

        $this->assertFalse($result['completed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function completedキーがない場合updateはfalseとして扱う(): void
    {
        $todo = ['id' => '1', 'title' => 'テストTodo', 'completed' => '0'];
        $this->mockRepository->method('findById')->willReturn($todo);

        $result = $this->handler->update(1, []);

        $this->assertFalse($result['completed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを更新するとupdateはerrorキーを返す(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);

        $result = $this->handler->update(999, ['completed' => true]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Todo not found', $result['error']);
        $this->assertSame(999, $result['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを更新するとupdateはリポジトリのupdateを呼ばない(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);
        $this->mockRepository->expects($this->never())->method('update');

        $this->handler->update(999, ['completed' => true]);
    }

    // =========================================================
    // delete() のテスト
    // =========================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在するTodoを削除するとdeleteは成功メッセージを返す(): void
    {
        $todo = ['id' => '1', 'title' => '削除対象Todo', 'completed' => '0'];
        $this->mockRepository->method('findById')->willReturn($todo);

        $result = $this->handler->delete(1);

        $this->assertArrayHasKey('message', $result);
        $this->assertSame('Todo deleted', $result['message']);
        $this->assertSame(1, $result['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在するTodoを削除するとdeleteはリポジトリのdeleteを呼ぶ(): void
    {
        $todo = ['id' => '1', 'title' => '削除対象Todo', 'completed' => '0'];
        $this->mockRepository->method('findById')->willReturn($todo);
        $this->mockRepository->expects($this->once())->method('delete')->with(1);

        $this->handler->delete(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを削除するとdeleteはerrorキーを返す(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);

        $result = $this->handler->delete(999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Todo not found', $result['error']);
        $this->assertSame(999, $result['id']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function 存在しないIDを削除するとdeleteはリポジトリのdeleteを呼ばない(): void
    {
        $this->mockRepository->method('findById')->willReturn(null);
        $this->mockRepository->expects($this->never())->method('delete');

        $this->handler->delete(999);
    }
}
