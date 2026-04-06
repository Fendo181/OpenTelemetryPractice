<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\API\Signals;

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
        $baseEndpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318';
        $endpoint  = HttpEndpointResolver::create()->resolveToString($baseEndpoint, Signals::TRACE);
        $headers = [];
        foreach (explode(',', getenv('OTEL_EXPORTER_OTLP_HEADERS') ?: '') as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        $transport = (new OtlpHttpTransportFactory())->create($endpoint, ContentTypes::PROTOBUF, $headers);
        $exporter  = new SpanExporter($transport);

        // 2. BatchSpanProcessor - Spanを溜めて効率的に送信
        // リアルタイムで1個ずつ送るとオーバーヘッドが大きいため、バッチ化する。
        $spanProcessor = new BatchSpanProcessor(
            $exporter,
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
        );

        // 3. Resource Attributes - サービスのメタデータ
        // service.name はトレースを識別するための最重要属性。
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 4. TracerProvider の生成（Builder パターンで安全に構築）
        return (new TracerProviderBuilder())
            ->setResource($resource)
            ->setSampler(new AlwaysOnSampler())
            ->addSpanProcessor($spanProcessor)
            ->build();
    }
}
