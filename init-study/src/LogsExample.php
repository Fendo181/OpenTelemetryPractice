<?php

declare(strict_types=1);

namespace App;

use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanContext;

/**
 * ログ デモクラス
 *
 * デモ内容:
 * - 構造化ログ（コンテキスト付き）
 * - Trace 相関（trace_id / span_id を自動付与）
 * - ログレベル別の使い分け
 * - Monolog + OpenTelemetry ブリッジによる統合
 */
class LogsExample
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 構造化ログのデモ
     *
     * コンテキスト配列を使って構造化データをログに付与する。
     * Loki 等のログ基盤でフィールド検索が可能になる。
     */
    public function demonstrateStructuredLogging(): void
    {
        echo PHP_EOL . '--- Logs デモ ---' . PHP_EOL;

        // DEBUG: 詳細なデバッグ情報
        $this->logger->debug('注文処理開始', [
            'order_id'   => 'order-123',
            'user_id'    => 'user-456',
            'item_count' => 3,
        ]);
        echo '  [Logs] DEBUG: 注文処理開始' . PHP_EOL;

        // INFO: 通常の操作ログ
        $this->logger->info('注文が作成されました', [
            'order_id' => 'order-123',
            'amount'   => 15800,
            'currency' => 'JPY',
            'items'    => [
                ['sku' => 'SKU-001', 'qty' => 2, 'price' => 5400],
                ['sku' => 'SKU-007', 'qty' => 1, 'price' => 5000],
            ],
        ]);
        echo '  [Logs] INFO: 注文作成' . PHP_EOL;

        // NOTICE: 注目すべき通常イベント
        $this->logger->notice('ユーザーが初回購入を完了しました', [
            'user_id'    => 'user-456',
            'order_id'   => 'order-123',
            'first_time' => true,
        ]);
        echo '  [Logs] NOTICE: 初回購入' . PHP_EOL;

        // WARNING: 問題ではないが注意が必要な状況
        $this->logger->warning('決済レスポンスが遅延しています', [
            'payment_gateway' => 'stripe',
            'response_time_ms'=> 2850,
            'threshold_ms'    => 2000,
        ]);
        echo '  [Logs] WARNING: 決済レスポンス遅延' . PHP_EOL;

        // ERROR: エラーが発生したが処理は継続
        $this->logger->error('在庫確認に失敗しました', [
            'sku'         => 'SKU-999',
            'error_code'  => 'INVENTORY_SERVICE_UNAVAILABLE',
            'retry_count' => 3,
        ]);
        echo '  [Logs] ERROR: 在庫確認失敗' . PHP_EOL;

        // CRITICAL: 緊急対応が必要
        $this->logger->critical('決済処理システムに接続できません', [
            'service'         => 'payment-gateway',
            'last_successful' => '2026-03-16T10:00:00Z',
            'impact'          => '全注文が処理不能',
        ]);
        echo '  [Logs] CRITICAL: 決済システム接続不能' . PHP_EOL;

        echo PHP_EOL;
    }

    /**
     * Trace 相関のデモ
     *
     * アクティブな Span が存在する場合、Monolog ブリッジが
     * trace_id と span_id を自動でログに付与する。
     * これにより Jaeger と Loki を横断したデバッグが可能になる。
     */
    public function demonstrateTraceCorrelation(): void
    {
        echo '--- Trace 相関ログ デモ ---' . PHP_EOL;

        $tracer = Globals::tracerProvider()->getTracer('demo.logs');
        $span   = $tracer->spanBuilder('order.create')->startSpan();
        $scope  = $span->activate();

        try {
            $ctx = $span->getContext();
            echo sprintf(
                '  [Trace Context] trace_id=%s span_id=%s%s',
                $ctx->getTraceId(),
                $ctx->getSpanId(),
                PHP_EOL
            );

            // このログには trace_id / span_id が自動付与される
            $this->logger->info('注文処理中 (Trace 相関あり)', [
                'order_id' => 'order-789',
                'step'     => 'validate_items',
            ]);
            echo '  [Logs] trace_id/span_id が自動付与されます' . PHP_EOL;

            $this->logger->info('在庫確認完了', [
                'order_id'      => 'order-789',
                'step'          => 'check_inventory',
                'items_in_stock'=> true,
            ]);

            $this->logger->info('決済処理完了', [
                'order_id'       => 'order-789',
                'step'           => 'process_payment',
                'transaction_id' => 'txn-abc-123',
            ]);

        } finally {
            $scope->detach();
            $span->end();
        }

        echo PHP_EOL;
    }

    /**
     * 例外ログのデモ
     */
    public function demonstrateExceptionLogging(): void
    {
        echo '--- 例外ログ デモ ---' . PHP_EOL;

        try {
            throw new \InvalidArgumentException('無効な注文IDです: order-000');
        } catch (\Throwable $e) {
            // context に exception を渡すと Monolog が自動でフォーマット
            $this->logger->error('注文処理で例外が発生しました', [
                'exception' => $e,
                'order_id'  => 'order-000',
                'action'    => 'get_order',
            ]);
            echo '  [Logs] ERROR: 例外情報付きログを記録' . PHP_EOL;
        }

        echo PHP_EOL;
    }
}
