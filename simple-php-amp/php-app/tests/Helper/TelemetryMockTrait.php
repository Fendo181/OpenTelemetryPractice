<?php

declare(strict_types=1);

namespace Tests\Helper;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Log\NullLogger;
use SimplePhpApm\Telemetry\TelemetryInitializer;

/**
 * TelemetryMockTrait - テスト用 OpenTelemetry モック設定トレイト
 *
 * t_wada TDD: テストで外部依存（OTel コレクター等）を排除するためのヘルパー。
 * PHPUnit TestCase に use して setUp/tearDown で呼び出す。
 *
 * 使い方:
 *   use TelemetryMockTrait;
 *   protected function setUp(): void { $this->setUpTelemetry(); }
 *   protected function tearDown(): void { $this->tearDownTelemetry(); }
 */
trait TelemetryMockTrait
{
    /**
     * テスト用のモックテレメトリを TelemetryInitializer に注入する
     *
     * - TracerProvider: Span の生成をモック（外部への送信なし）
     * - MeterProvider: メトリクス記録をモック
     * - Logger: PSR-3 NullLogger（ログを破棄）
     */
    private function setUpTelemetry(): void
    {
        // TracerProvider モック
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $meterProvider  = $this->createMock(MeterProviderInterface::class);

        // Span 生成チェーンのモック（SpanKind::CLIENT/SERVER で使われる）
        $tracer      = $this->createMock(TracerInterface::class);
        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $span        = $this->createMock(SpanInterface::class);
        $scope       = $this->createMock(ScopeInterface::class);

        $tracerProvider->method('getTracer')->willReturn($tracer);

        // spanBuilder() チェーン: setSpanKind() → startSpan() → SpanInterface
        $tracer->method('spanBuilder')->willReturn($spanBuilder);
        $spanBuilder->method('setSpanKind')->willReturnSelf();
        $spanBuilder->method('setAttribute')->willReturnSelf();
        $spanBuilder->method('startSpan')->willReturn($span);

        // Span 操作のモック（戻り値は self でメソッドチェーン可能）
        $span->method('activate')->willReturn($scope);
        $span->method('setAttribute')->willReturnSelf();
        $span->method('setStatus')->willReturnSelf();
        $span->method('recordException')->willReturnSelf();
        // end(): void, scope->detach(): void は何もしない（デフォルト）

        // PSR-3 ロガーは NullLogger（全メソッドを無視）
        $logger = new NullLogger();

        TelemetryInitializer::initializeForTesting($tracerProvider, $meterProvider, $logger);
    }

    /**
     * テスト後に TelemetryInitializer をリセット（テスト間の汚染を防ぐ）
     */
    private function tearDownTelemetry(): void
    {
        TelemetryInitializer::reset();
    }
}
