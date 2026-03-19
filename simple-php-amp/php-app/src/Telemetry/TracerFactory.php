<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\API\Common\Signal\Signals;

/**
 * TracerFactory - TracerProvider の生成ロジック
 *
 * Traces（トレーシング）はAPMの心臓部。
 * リクエストの流れを「Span」というツリー構造で記録する。
 *
 * このファイルで実装していること:
 * 1. OTLP HTTP Exporter - Collectorにトレースを送信
 * 2. AlwaysOnSampler - デモ用に全リクエストをトレース
 * 3. BatchSpanProcessor - 効率的な送信のためバッチ化
 * 4. Resource Attributes - service.name などのメタデータ
 *
 * PHPerKaigi 2026デモ用
 */
class TracerFactory
{
    /**
     * TracerProvider を作成
     *
     * @return TracerProviderInterface
     */
    public static function create(): TracerProviderInterface
    {
        // 1. OTLP HTTP Exporter の設定
        // CollectorにHTTPでトレースを送信する。エンドポイントは /v1/traces
        $exporter = new SpanExporter(
            \OpenTelemetry\Contrib\Otlp\HttpEndpointResolver::create(
                getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318',
                Signals::TRACE
            )
        );

        // 2. BatchSpanProcessor - Spanを溜めて効率的に送信
        // リアルタイムで1個ずつ送るとオーバーヘッドが大きいため、バッチ化する。
        // 商用APMもこの仕組みを使っている。
        $spanProcessor = new BatchSpanProcessor(
            $exporter,
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
        );

        // 3. Sampler - サンプリング戦略
        // AlwaysOnSampler: 全リクエストをトレース（デモ用）
        // 本番環境では ParentBasedSampler + TraceIdRatioBasedSampler を使う。
        // 例: 1%だけトレースして負荷を減らす
        $sampler = new AlwaysOnSampler();

        // 4. Resource Attributes - サービスのメタデータ
        // service.name はトレースを識別するための最重要属性。
        // JaegerやGrafanaでサービスごとにフィルタできる。
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 5. TracerProvider の生成
        // これがTracer（Spanを作るAPI）を提供する。
        return new TracerProvider(
            [$spanProcessor],  // SpanProcessorの配列（複数設定可能）
            $sampler,          // サンプリング戦略
            $resource          // リソース属性
        );
    }
}
