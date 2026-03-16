<?php

declare(strict_types=1);

/**
 * OpenTelemetry SDK 初期化
 *
 * TracerProvider / MeterProvider / LoggerProvider を構築し、
 * グローバル登録するブートストラップファイル。
 * OTLP HTTP Exporter 経由で OpenTelemetry Collector へ送信する。
 */

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\EventLogger;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricsExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

require_once __DIR__ . '/../vendor/autoload.php';

// ----------------------------------------------------------------
// Resource 定義
// サービス名・バージョン・環境などのメタデータ
// ----------------------------------------------------------------
$resource = ResourceInfoFactory::emptyResource()->merge(
    ResourceInfo::create(
        Attributes::create([
            ResourceAttributes::SERVICE_NAME        => 'php-otel-demo',
            ResourceAttributes::SERVICE_VERSION     => '1.0.0',
            ResourceAttributes::SERVICE_NAMESPACE   => 'conference-demo',
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => 'development',
        ])
    )
);

// ----------------------------------------------------------------
// Transport 設定 (OTLP HTTP)
// 環境変数 OTEL_EXPORTER_OTLP_ENDPOINT で上書き可能
// ----------------------------------------------------------------
$collectorEndpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318';

$transportFactory = new PsrTransportFactory(
    new \GuzzleHttp\Client(),
    new \Nyholm\Psr7\Factory\Psr17Factory(),
    new \Nyholm\Psr7\Factory\Psr17Factory()
);

// ----------------------------------------------------------------
// TracerProvider 構築
// ----------------------------------------------------------------
$traceTransport = $transportFactory->create(
    $collectorEndpoint . '/v1/traces',
    'application/x-protobuf'
);
$spanExporter   = new SpanExporter($traceTransport);

$tracerProvider = new TracerProvider(
    spanProcessors: [new SimpleSpanProcessor($spanExporter)],
    sampler:        new AlwaysOnSampler(),
    resource:       $resource,
);

// ----------------------------------------------------------------
// MeterProvider 構築
// ----------------------------------------------------------------
$metricsTransport = $transportFactory->create(
    $collectorEndpoint . '/v1/metrics',
    'application/x-protobuf'
);
$metricsExporter = new MetricsExporter($metricsTransport);

$meterProvider = MeterProvider::builder()
    ->setResource($resource)
    ->addReader(new ExportingReader($metricsExporter))
    ->build();

// ----------------------------------------------------------------
// LoggerProvider 構築
// ----------------------------------------------------------------
$logsTransport = $transportFactory->create(
    $collectorEndpoint . '/v1/logs',
    'application/x-protobuf'
);
$logsExporter = new LogsExporter($logsTransport);

$loggerProvider = new LoggerProvider(
    processor: new SimpleLogRecordProcessor($logsExporter),
    resource:  $resource,
);

// ----------------------------------------------------------------
// グローバル登録
// ----------------------------------------------------------------
Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setMeterProvider($meterProvider)
    ->setLoggerProvider($loggerProvider)
    ->setPropagator(\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance())
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal();

// ----------------------------------------------------------------
// Monolog + OpenTelemetry ブリッジ（ログ相関用）
// ----------------------------------------------------------------
$monologHandler = new \OpenTelemetry\Contrib\Logs\Monolog\Handler(
    $loggerProvider,
    \Psr\Log\LogLevel::DEBUG
);

$logger = new Logger('app');
$logger->pushHandler($monologHandler);
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// グローバルアクセス用に返す
return [
    'tracerProvider' => $tracerProvider,
    'meterProvider'  => $meterProvider,
    'loggerProvider' => $loggerProvider,
    'logger'         => $logger,
];
