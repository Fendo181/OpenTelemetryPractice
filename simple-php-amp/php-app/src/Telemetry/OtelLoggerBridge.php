<?php

declare(strict_types=1);

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Logs\LoggerInterface as OtelLoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * OtelLoggerBridge - PSR-3 LoggerInterface を OpenTelemetry に橋渡しするアダプター
 *
 * 問題: OpenTelemetry の LoggerInterface は emit() / logRecordBuilder() のみを持ち、
 * PSR-3 の info() / warning() / error() メソッドを持たない。
 *
 * 解決策: このクラスで PSR-3 メソッドを受け取り、OTel の logRecordBuilder() に変換する。
 * これにより Trace-Log 相関（Trace ID の自動付与）が維持される。
 *
 * PHPerKaigi 2026デモ用
 */
class OtelLoggerBridge implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly OtelLoggerInterface $otelLogger)
    {
    }

    /**
     * PSR-3 の log() を OTel の logRecordBuilder() に変換
     *
     * @param mixed  $level   PSR-3 ログレベル（LogLevel::INFO 等）
     * @param string|\Stringable $message ログメッセージ
     * @param array  $context コンテキスト情報
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $severity = match (strtolower((string) $level)) {
            LogLevel::EMERGENCY => Severity::FATAL,
            LogLevel::ALERT     => Severity::FATAL2,
            LogLevel::CRITICAL  => Severity::FATAL3,
            LogLevel::ERROR     => Severity::ERROR,
            LogLevel::WARNING   => Severity::WARN,
            LogLevel::NOTICE    => Severity::INFO2,
            LogLevel::INFO      => Severity::INFO,
            LogLevel::DEBUG     => Severity::DEBUG,
            default             => Severity::UNDEFINED_SEVERITY_NUMBER,
        };

        $this->otelLogger->logRecordBuilder()
            ->setSeverityNumber($severity)
            ->setSeverityText(strtoupper((string) $level))
            ->setBody((string) $message)
            ->setAttributes($context)
            ->emit();
    }
}
