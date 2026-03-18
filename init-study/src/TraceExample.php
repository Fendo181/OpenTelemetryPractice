<?php

declare(strict_types=1);

namespace App;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 * トレーシング デモクラス
 *
 * デモ内容:
 * - Span の作成と属性付与
 * - 子 Span (ネスト) による処理の階層化
 * - 例外のキャプチャと Span への記録
 * - SpanKind による分類 (SERVER / CLIENT / INTERNAL)
 */
class TraceExample
{
    private \OpenTelemetry\API\Trace\TracerInterface $tracer;

    public function __construct()
    {
        $this->tracer = Globals::tracerProvider()->getTracer(
            'demo.tracer',
            '1.0.0',
            'https://opentelemetry.io/schemas/1.24.0'
        );
    }

    /**
     * HTTP リクエスト処理のシミュレーション
     * SERVER Span でリクエスト全体を包む
     */
    public function simulateHttpRequest(string $method, string $path): void
    {
        // ルートSpan: HTTP サーバー処理
        $rootSpan = $this->tracer
            ->spanBuilder('HTTP ' . $method . ' ' . $path)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $rootSpan->activate();

        try {
            // HTTP セマンティクス属性を付与
            $rootSpan->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD => $method,
                TraceAttributes::URL_PATH            => $path,
                TraceAttributes::HTTP_ROUTE          => '/orders/{id}',
                TraceAttributes::SERVER_ADDRESS      => 'localhost',
                TraceAttributes::SERVER_PORT         => 8080,
                'user.id'                            => 'user-123',
            ]);

            // 子Span: データベースクエリ
            $this->simulateDatabaseQuery('SELECT * FROM orders WHERE id = ?', [42]);

            // 子Span: 外部API呼び出し
            $this->simulateExternalApiCall('https://payment.example.com/verify');

            // 正常終了
            $rootSpan->setStatus(StatusCode::STATUS_OK);
            $rootSpan->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, 200);

        } catch (\Throwable $e) {
            // 例外を Span に記録
            $rootSpan->recordException($e, [
                'exception.escaped' => true,
            ]);
            $rootSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $rootSpan->end();
        }
    }

    /**
     * データベースクエリのシミュレーション (INTERNAL Span)
     */
    private function simulateDatabaseQuery(string $sql, array $params): void
    {
        $span = $this->tracer
            ->spanBuilder('db.query')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttributes([
                TraceAttributes::DB_SYSTEM_NAME      => 'mysql',
                TraceAttributes::DB_NAMESPACE        => 'shop',
                TraceAttributes::DB_QUERY_TEXT       => $sql,
                TraceAttributes::SERVER_ADDRESS      => 'mysql',
                TraceAttributes::SERVER_PORT         => 3306,
            ]);

            // クエリ実行をシミュレート (実際の処理の代わりにスリープ)
            usleep(random_int(5000, 20000)); // 5〜20ms

            $span->addEvent('query.executed', [
                'rows.affected' => 1,
                'duration_ms'   => 12,
            ]);

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * 外部API呼び出しのシミュレーション (CLIENT Span)
     */
    private function simulateExternalApiCall(string $url): void
    {
        $span = $this->tracer
            ->spanBuilder('payment.verify')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD  => 'POST',
                TraceAttributes::URL_FULL             => $url,
                TraceAttributes::SERVER_ADDRESS       => 'payment.example.com',
                TraceAttributes::SERVER_PORT          => 443,
            ]);

            usleep(random_int(30000, 80000)); // 30〜80ms (外部APIは遅め)

            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, 200);

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * 例外記録のデモ
     * エラー発生時の Span への例外情報記録を示す
     */
    public function simulateError(): void
    {
        $span = $this->tracer
            ->spanBuilder('order.process')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttributes([
                'order.id'     => 'order-999',
                'order.amount' => 5000,
                'order.items'  => 3,
            ]);

            // 意図的に例外を発生させる
            throw new \RuntimeException('在庫不足: 商品 SKU-001 の在庫が不足しています');

        } catch (\Throwable $e) {
            // recordException() で例外情報 (type / message / stacktrace) を記録
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            // ここでは再スローせずデモとして吸収
            echo '  [Trace] 例外をキャプチャしました: ' . $e->getMessage() . PHP_EOL;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
