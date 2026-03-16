<?php

declare(strict_types=1);

namespace App;

use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 * 注文サービス - 3シグナル統合デモ
 *
 * Trace / Metrics / Logs の 3つのシグナルを実際のサービスコードの
 * ように統合したデモクラス。
 *
 * 一連の注文処理フロー:
 *   1. 注文受付 (HTTP Server Span)
 *   2. バリデーション (Internal Span)
 *   3. 在庫確認 (Client Span → 外部サービス)
 *   4. 決済処理 (Client Span → 外部サービス)
 *   5. 注文確定 (DB Span)
 */
class OrderService
{
    private \OpenTelemetry\API\Trace\TracerInterface $tracer;
    private \OpenTelemetry\API\Metrics\MeterInterface $meter;
    private Logger $logger;

    // メトリクス Instrument
    private \OpenTelemetry\API\Metrics\CounterInterface $ordersCreated;
    private \OpenTelemetry\API\Metrics\CounterInterface $ordersFailed;
    private \OpenTelemetry\API\Metrics\HistogramInterface $orderProcessingTime;
    private \OpenTelemetry\API\Metrics\HistogramInterface $orderValue;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;

        $this->tracer = Globals::tracerProvider()->getTracer(
            'order-service',
            '1.0.0'
        );

        $this->meter = Globals::meterProvider()->getMeter(
            'order-service',
            '1.0.0'
        );

        $this->initMetrics();
    }

    private function initMetrics(): void
    {
        $this->ordersCreated = $this->meter->createCounter(
            'orders.created.total',
            '{order}',
            '作成された注文の総数'
        );

        $this->ordersFailed = $this->meter->createCounter(
            'orders.failed.total',
            '{order}',
            '失敗した注文の総数'
        );

        $this->orderProcessingTime = $this->meter->createHistogram(
            'orders.processing_time',
            'ms',
            '注文処理にかかった時間'
        );

        $this->orderValue = $this->meter->createHistogram(
            'orders.value',
            'JPY',
            '注文金額の分布'
        );
    }

    /**
     * 注文作成のメインフロー
     *
     * @param array{
     *   id: string,
     *   user_id: string,
     *   items: array<array{sku: string, qty: int, price: int}>,
     * } $order
     */
    public function createOrder(array $order): array
    {
        $startTime = microtime(true);

        // ルート Span: 注文作成処理全体
        $span = $this->tracer
            ->spanBuilder('OrderService.createOrder')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        try {
            $totalAmount = array_sum(array_column(
                array_map(fn($item) => ['total' => $item['qty'] * $item['price']], $order['items']),
                'total'
            ));

            $span->setAttributes([
                'order.id'           => $order['id'],
                'order.user_id'      => $order['user_id'],
                'order.items_count'  => count($order['items']),
                'order.total_amount' => $totalAmount,
            ]);

            $this->logger->info('注文処理を開始します', [
                'order_id'   => $order['id'],
                'user_id'    => $order['user_id'],
                'item_count' => count($order['items']),
                'amount'     => $totalAmount,
            ]);

            // Step 1: バリデーション
            $this->validateOrder($order);

            // Step 2: 在庫確認
            $this->checkInventory($order['items']);

            // Step 3: 決済処理
            $transactionId = $this->processPayment($order['user_id'], $totalAmount);

            // Step 4: 注文確定（DB保存）
            $this->saveOrder($order, $transactionId);

            $span->setStatus(StatusCode::STATUS_OK);

            // 成功メトリクスを記録
            $this->ordersCreated->add(1, [
                'order.category' => $this->categorizeOrder($totalAmount),
            ]);
            $this->orderValue->record($totalAmount, [
                'order.status' => 'completed',
            ]);

            $result = [
                'order_id'       => $order['id'],
                'status'         => 'completed',
                'transaction_id' => $transactionId,
                'amount'         => $totalAmount,
            ];

            $this->logger->info('注文が正常に完了しました', $result);

            return $result;

        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            $this->ordersFailed->add(1, [
                'error.type' => get_class($e),
            ]);

            $this->logger->error('注文処理が失敗しました', [
                'order_id'  => $order['id'],
                'exception' => $e,
            ]);

            throw $e;

        } finally {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->orderProcessingTime->record($durationMs, [
                'order.status' => isset($result) ? 'completed' : 'failed',
            ]);

            $scope->detach();
            $span->end();
        }
    }

    /**
     * 注文バリデーション (INTERNAL Span)
     */
    private function validateOrder(array $order): void
    {
        $span  = $this->tracer->spanBuilder('OrderService.validateOrder')->startSpan();
        $scope = $span->activate();

        try {
            $span->setAttribute('order.id', $order['id']);

            $this->logger->debug('注文バリデーション実行中', ['order_id' => $order['id']]);

            if (empty($order['items'])) {
                throw new \InvalidArgumentException('注文に商品が含まれていません');
            }

            foreach ($order['items'] as $item) {
                if ($item['qty'] <= 0) {
                    throw new \InvalidArgumentException("商品 {$item['sku']} の数量が不正です");
                }
                if ($item['price'] <= 0) {
                    throw new \InvalidArgumentException("商品 {$item['sku']} の価格が不正です");
                }
            }

            usleep(random_int(1000, 3000));

            $span->addEvent('validation.passed', ['rules_checked' => 5]);
            $this->logger->debug('バリデーション成功', ['order_id' => $order['id']]);

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * 在庫確認 (CLIENT Span - 外部在庫サービス)
     */
    private function checkInventory(array $items): void
    {
        $span = $this->tracer
            ->spanBuilder('inventory-service.check')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $skus = array_column($items, 'sku');
            $span->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD => 'POST',
                TraceAttributes::URL_FULL            => 'http://inventory-service/api/check',
                'inventory.skus'                     => implode(',', $skus),
                'inventory.item_count'               => count($items),
            ]);

            $this->logger->debug('在庫確認リクエスト送信', ['skus' => $skus]);

            // 在庫サービス呼び出しをシミュレート
            usleep(random_int(10000, 30000));

            $span->setAttributes([
                TraceAttributes::HTTP_RESPONSE_STATUS_CODE => 200,
                'inventory.all_available' => true,
            ]);

            $this->logger->debug('在庫確認完了 - 全商品在庫あり', ['skus' => $skus]);

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * 決済処理 (CLIENT Span - 外部決済サービス)
     */
    private function processPayment(string $userId, int $amount): string
    {
        $span = $this->tracer
            ->spanBuilder('payment-service.charge')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD => 'POST',
                TraceAttributes::URL_FULL            => 'http://payment-service/api/charge',
                'payment.user_id'                    => $userId,
                'payment.amount'                     => $amount,
                'payment.currency'                   => 'JPY',
            ]);

            $this->logger->info('決済処理リクエスト送信', [
                'user_id' => $userId,
                'amount'  => $amount,
            ]);

            // 決済処理をシミュレート（外部APIは比較的遅い）
            usleep(random_int(50000, 120000));

            $transactionId = 'txn-' . substr(md5(uniqid()), 0, 12);

            $span->setAttributes([
                TraceAttributes::HTTP_RESPONSE_STATUS_CODE => 200,
                'payment.transaction_id' => $transactionId,
                'payment.status'         => 'approved',
            ]);

            $this->logger->info('決済処理完了', [
                'user_id'        => $userId,
                'transaction_id' => $transactionId,
            ]);

            return $transactionId;

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * 注文保存 (CLIENT Span - データベース)
     */
    private function saveOrder(array $order, string $transactionId): void
    {
        $span = $this->tracer
            ->spanBuilder('db.orders.insert')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttributes([
                TraceAttributes::DB_SYSTEM_NAME  => 'mysql',
                TraceAttributes::DB_NAMESPACE    => 'shop',
                TraceAttributes::DB_OPERATION_NAME => 'INSERT',
                TraceAttributes::DB_QUERY_TEXT   => 'INSERT INTO orders (id, user_id, transaction_id) VALUES (?, ?, ?)',
            ]);

            usleep(random_int(5000, 15000));

            $span->addEvent('db.row.inserted', ['table' => 'orders']);
            $this->logger->debug('注文をDBに保存しました', [
                'order_id'       => $order['id'],
                'transaction_id' => $transactionId,
            ]);

        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function categorizeOrder(int $amount): string
    {
        return match (true) {
            $amount < 1000  => 'small',
            $amount < 10000 => 'medium',
            default         => 'large',
        };
    }
}
