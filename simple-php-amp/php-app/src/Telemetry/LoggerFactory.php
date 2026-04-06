<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\API\Signals;

/**
 * LoggerFactory - LoggerProvider の生成ロジック
 *
 * Logs（ログ）は構造化されたイベント記録。
 * APMの真価は「Trace-Log相関」。エラーログから対応するトレースにジャンプできる。
 *
 * 重要な機能:
 * - Trace ID をログに自動付与（Trace-Log相関の実現）
 * - 構造化ログ（JSON形式でkey-valueペア）
 * - OTLP経由でCollectorに送信
 *
 * PHPerKaigi 2026デモ用
 */
class LoggerFactory
{
    /**
     * LoggerProvider を作成
     *
     * @return LoggerProviderInterface
     */
    public static function create(): LoggerProviderInterface
    {
        // 1. OTLP HTTP Logs Exporter の設定
        // CollectorにHTTPでログを送信する。エンドポイントは /v1/logs
        $baseEndpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318';
        $endpoint  = HttpEndpointResolver::create()->resolveToString($baseEndpoint, Signals::LOGS);
        $headers = [];
        foreach (explode(',', getenv('OTEL_EXPORTER_OTLP_HEADERS') ?: '') as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        $transport = (new OtlpHttpTransportFactory())->create($endpoint, ContentTypes::PROTOBUF, $headers);
        $exporter  = new LogsExporter($transport);

        // 2. BatchLogRecordProcessor - ログを溜めて効率的に送信
        $logProcessor = new BatchLogRecordProcessor(
            $exporter,
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
        );

        // 3. Resource Attributes - サービスのメタデータ
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 4. LoggerProvider の生成（Builder パターンで安全に構築）
        return LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor($logProcessor)
            ->build();
    }
}
