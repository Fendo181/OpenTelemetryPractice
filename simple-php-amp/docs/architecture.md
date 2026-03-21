# simple-php-amp Architecture

## 概要

このドキュメントでは、`simple-php-amp` における OpenTelemetry の 3 シグナル（Traces・Metrics・Logs）がどのように送信されるかの処理フローを解説します。

---

## システム全体構成

```mermaid
graph TB
    subgraph "PHP Application (port:8080)"
        APP[index.php<br/>エントリーポイント]
        INIT[TelemetryInitializer<br/>初期化統括]
        MW[TelemetryMiddleware<br/>HTTPリクエスト計装]
        HANDLER[TodoHandler<br/>ビジネスロジック]
        REPO[TodoRepository<br/>DB操作]
        BRIDGE[OtelLoggerBridge<br/>PSR-3アダプター]
        TF[TracerFactory]
        MF[MeterFactory]
        LF[LoggerFactory]
    end

    subgraph "OpenTelemetry Collector (port:4318)"
        COL[OTel Collector<br/>受信・変換・転送]
    end

    subgraph "バックエンド"
        JAEGER[Jaeger UI<br/>port:16686]
        PROM[Prometheus<br/>port:9090]
        LOKI[Loki<br/>port:3100]
        GRAFANA[Grafana<br/>port:3000]
    end

    APP --> INIT
    INIT --> TF
    INIT --> MF
    INIT --> LF
    INIT --> BRIDGE
    APP --> MW
    MW --> HANDLER
    HANDLER --> REPO

    TF -- "OTLP HTTP /v1/traces" --> COL
    MF -- "OTLP HTTP /v1/metrics" --> COL
    LF -- "OTLP HTTP /v1/logs" --> COL

    COL -- "Traces" --> JAEGER
    COL -- "Metrics" --> PROM
    COL -- "Logs" --> LOKI
    PROM --> GRAFANA
    LOKI --> GRAFANA
    JAEGER --> GRAFANA
```

---

## Traces（トレース）の処理フロー

### 概要

HTTP リクエストを起点に、DB 操作・外部サービス呼び出しまでを **Span** の親子ツリーで記録します。

```mermaid
flowchart TD
    REQ([HTTPリクエスト受信]) --> IDX[index.php]

    IDX --> INIT_T["TelemetryInitializer::initialize()\n① TracerFactory::create()"]
    INIT_T --> TF_BUILD["TracerFactory::create()\n- OtlpHttpSpanExporter 生成\n  endpoint: http://otel-collector:4318/v1/traces\n- BatchSpanProcessor 生成\n- AlwaysOnSampler 設定\n- Resource: service.name=simple-php-apm"]

    TF_BUILD --> CONF["Configurator::builder()\n  ->withTracerProvider()\n  ->activate()"]

    CONF --> MW["TelemetryMiddleware::handle()\n② HTTP SERVER Span 作成\n  - W3C Trace Context 抽出\n  - SpanKind: KIND_SERVER\n  - Span名: 'HTTP {METHOD} {PATH}'\n  - $span->activate()"]

    MW --> HANDLER["TodoHandler::*()\n③ ビジネスロジック実行\n  ※ 追加Spanは作らない"]

    HANDLER --> REPO["TodoRepository::*()\n④ DB Client Span 作成\n  - SpanKind: KIND_CLIENT\n  - Span名: 'db.query: SELECT todos'\n  - 属性: db.system=sqlite\n         db.statement=SQL文\n         db.rows_affected=N"]

    REPO --> REPO_END["DB Span 終了\n finally: $dbSpan->end()"]

    HANDLER --> EXT["TodoHandler::notifyExternalService()\n⑤ 外部サービス Span 作成\n  - SpanKind: KIND_CLIENT\n  - Span名: 'HTTP POST notification-service'\n  - traceparent ヘッダー伝播"]

    EXT --> EXT_END["外部サービス Span 終了\n finally: $extSpan->end()"]

    REPO_END --> MW_END["TelemetryMiddleware\n⑥ HTTP SERVER Span 終了\n  - http.status_code 記録\n  - finally: $span->end()\n  - scope->detach()"]

    EXT_END --> MW_END

    MW_END --> RES([レスポンス返却])

    RES --> SHUTDOWN["register_shutdown_function()\n⑦ TelemetryInitializer::shutdown()\n  TracerProvider::shutdown()"]

    SHUTDOWN --> FLUSH["BatchSpanProcessor\n⑧ 未送信 Span をフラッシュ"]

    FLUSH --> OTLP["OtlpHttpSpanExporter\n⑨ POST /v1/traces\n  → otel-collector:4318"]

    OTLP --> COL["OpenTelemetry Collector\n⑩ otlphttp receiver 受信"]

    COL --> JAEGER["otlp/jaeger exporter\n⑪ gRPC → Jaeger :4317"]

    JAEGER --> UI([Jaeger UI :16686\nトレース可視化])

    style REQ fill:#4CAF50,color:#fff
    style UI fill:#2196F3,color:#fff
    style MW fill:#FF9800,color:#fff
    style REPO fill:#9C27B0,color:#fff
    style EXT fill:#9C27B0,color:#fff
```

### Span の親子関係

```mermaid
graph LR
    subgraph "Trace ツリー（1リクエスト分）"
        SERVER["🟦 HTTP GET /todos\n[HTTP SERVER Span]\nSpanKind: SERVER"]
        DB["🟪 db.query: SELECT todos\n[DB Client Span]\nSpanKind: CLIENT"]
        NOTIF["🟪 HTTP POST notification-service\n[外部API Span]\nSpanKind: CLIENT"]
    end

    SERVER --> DB
    SERVER --> NOTIF
```

---

## Metrics（メトリクス）の処理フロー

### 概要

HTTP リクエストのカウントとレスポンスタイムを **Counter** と **Histogram** で記録します。

```mermaid
flowchart TD
    REQ([HTTPリクエスト受信]) --> IDX[index.php]

    IDX --> INIT_M["TelemetryInitializer::initialize()\n① MeterFactory::create()"]

    INIT_M --> MF_BUILD["MeterFactory::create()\n- OtlpHttpMetricExporter 生成\n  endpoint: http://otel-collector:4318/v1/metrics\n- MeterProviderBuilder::create()\n- ExportingReader 設定\n- Resource: service.name=simple-php-apm"]

    MF_BUILD --> CONF_M["Configurator::builder()\n  ->withMeterProvider()\n  ->activate()"]

    CONF_M --> MW_START["TelemetryMiddleware::handle()\n② リクエスト開始時刻を記録\n  $startTime = microtime(true)"]

    MW_START --> PROCESS["ビジネスロジック処理\nTodoHandler → TodoRepository"]

    PROCESS --> MW_METRICS["TelemetryMiddleware::recordMetrics()\n③ finally ブロックで実行"]

    MW_METRICS --> COUNTER["Counter: http.requests.total\n  +1 カウントアップ\n  属性:\n    http.method=GET/POST/PUT/DELETE\n    http.route=/todos/:id\n    http.status_code=200/201/404/500"]

    MW_METRICS --> HISTOGRAM["Histogram: http.request.duration\n  レスポンスタイム(ms)記録\n  属性:\n    http.method=GET/POST\n    http.route=/todos/:id\n  → P50/P95/P99 計算可能"]

    COUNTER --> READER["ExportingReader\n④ 定期的にメトリクス収集"]
    HISTOGRAM --> READER

    RES([レスポンス返却]) --> SHUTDOWN_M["register_shutdown_function()\n⑤ MeterProvider::shutdown()"]

    SHUTDOWN_M --> FLUSH_M["ExportingReader\n⑥ 未送信メトリクスをフラッシュ"]

    FLUSH_M --> OTLP_M["OtlpHttpMetricExporter\n⑦ POST /v1/metrics\n  → otel-collector:4318"]

    OTLP_M --> COL_M["OpenTelemetry Collector\n⑧ otlphttp receiver 受信"]

    COL_M --> PROM_EXP["prometheus exporter\n⑨ :8889 エンドポイントで公開"]

    PROM_EXP --> PROM["Prometheus :9090\n⑩ スクレイプ収集・保存"]

    PROM --> GRAFANA["Grafana :3000\n⑪ ダッシュボード可視化\n  - リクエスト数グラフ\n  - レイテンシ分布\n  - エラー率"]

    READER --> SHUTDOWN_M

    style REQ fill:#4CAF50,color:#fff
    style GRAFANA fill:#2196F3,color:#fff
    style COUNTER fill:#FF9800,color:#fff
    style HISTOGRAM fill:#FF9800,color:#fff
```

### 記録されるメトリクス一覧

| メトリクス名 | 種類 | 説明 | 属性 |
|------------|------|------|------|
| `http.requests.total` | Counter | HTTPリクエスト総数 | `http.method`, `http.route`, `http.status_code` |
| `http.request.duration` | Histogram | リクエスト処理時間(ms) | `http.method`, `http.route` |

---

## Logs（ログ）の処理フロー

### 概要

PSR-3 標準インターフェースでログを出力し、OpenTelemetry の **Trace ID** を自動的に付与します。

```mermaid
flowchart TD
    REQ([HTTPリクエスト受信]) --> IDX[index.php]

    IDX --> INIT_L["TelemetryInitializer::initialize()\n① LoggerFactory::create()"]

    INIT_L --> LF_BUILD["LoggerFactory::create()\n- OtlpHttpLogExporter 生成\n  endpoint: http://otel-collector:4318/v1/logs\n- BatchLogRecordProcessor 設定\n- Resource: service.name=simple-php-apm"]

    LF_BUILD --> BRIDGE["OtelLoggerBridge でラップ\n② PSR-3 LoggerInterface を実装\n  Psr\\Log\\LoggerInterface"]

    BRIDGE --> CONF_L["Configurator::builder()\n  ->withLoggerProvider()\n  ->activate()"]

    CONF_L --> HANDLER_L["TodoHandler::*()\n③ $logger = TelemetryInitializer::getLogger()\n  PSR-3 メソッド呼び出し"]

    HANDLER_L --> PSR3_INFO["$logger->info('Todo一覧取得成功', context)\n$logger->warning('Todo not found', context)\n$logger->error('DB接続失敗', context)"]

    PSR3_INFO --> BRIDGE_LOG["OtelLoggerBridge::log()\n④ PSR-3 → OTel Severity 変換\n\ninfo    → Severity::INFO\nwarning → Severity::WARN\nerror   → Severity::ERROR\ndebug   → Severity::DEBUG"]

    BRIDGE_LOG --> RECORD["LogRecordBuilder\n⑤ ログレコード構築\n  ->setSeverityNumber()\n  ->setSeverityText()\n  ->setBody(メッセージ)\n  ->setAttributes(context)\n  ※ 現在の Trace ID が自動付与"]

    RECORD --> CORRELATION["Trace-Log 相関\n⑥ 同一リクエストの\n  TraceId + SpanId が付与\n  → JaegerとLogs を紐づけ可能"]

    CORRELATION --> PROC["BatchLogRecordProcessor\n⑦ ログをバッチ化"]

    PROC --> SHUTDOWN_L["register_shutdown_function()\n⑧ LoggerProvider::shutdown()"]

    SHUTDOWN_L --> FLUSH_L["BatchLogRecordProcessor\n⑨ 未送信ログをフラッシュ"]

    FLUSH_L --> OTLP_L["OtlpHttpLogExporter\n⑩ POST /v1/logs\n  → otel-collector:4318"]

    OTLP_L --> COL_L["OpenTelemetry Collector\n⑪ otlphttp receiver 受信"]

    COL_L --> DEBUG["debug exporter\n⑫ 標準出力(JSON形式)\n  ※デバッグ確認用"]

    COL_L --> LOKI_EXP["otlp_http/loki exporter\n⑫ POST /otlp/v1/logs\n  → loki:3100"]

    LOKI_EXP --> LOKI["Loki :3100\n⑬ ログ保存・インデックス化\n  ラベル: service_name\n         deployment_environment"]

    LOKI --> GRAFANA_L["Grafana :3000\n⑭ Explore → Loki でログ検索\n  Trace-Log 相関も可能"]

    style REQ fill:#4CAF50,color:#fff
    style GRAFANA_L fill:#2196F3,color:#fff
    style BRIDGE fill:#FF5722,color:#fff
    style CORRELATION fill:#E91E63,color:#fff
    style LOKI fill:#F06000,color:#fff
```

### Trace-Log 相関（Jaeger ↔ Loki）

ログには **Trace ID** が自動付与されるため、Grafana から Jaeger のトレースとログを紐づけて確認できます。

```mermaid
flowchart LR
    LOG["Loki のログ\ntrace_id=abc123..."] -- "trace_idクリック" --> JAEGER["Jaeger UI\nTrace abc123... の詳細"]
    JAEGER -- "Logs タブ" --> LOG
```

- **Loki → Jaeger**: ログの `trace_id` フィールドをクリックすると該当トレースに飛ぶ
- **Jaeger → Loki**: トレース画面の Logs タブから同一 Trace ID のログを参照できる（`jaeger.yaml` の `tracesToLogsV2` 設定）

### PSR-3 → OTel Severity マッピング

| PSR-3 レベル | OTel Severity | 説明 |
|-------------|---------------|------|
| `emergency` | `FATAL4` | システム使用不可 |
| `alert` | `FATAL3` | 即時対応が必要 |
| `critical` | `FATAL2` | 重大な障害 |
| `error` | `ERROR` | エラー発生 |
| `warning` | `WARN` | 警告 |
| `notice` | `INFO2` | 通常だが重要 |
| `info` | `INFO` | 情報メッセージ |
| `debug` | `DEBUG` | デバッグ情報 |

---

## リクエスト全体の統合フロー

```mermaid
sequenceDiagram
    actor Client
    participant PHP as PHP App<br/>(index.php)
    participant Init as TelemetryInitializer
    participant MW as TelemetryMiddleware
    participant Handler as TodoHandler
    participant Repo as TodoRepository
    participant Col as OTel Collector
    participant Jaeger as Jaeger
    participant Prom as Prometheus
    participant Loki as Loki
    participant Grafana as Grafana

    Client->>PHP: HTTP Request
    PHP->>Init: initialize()
    Init->>Init: TracerFactory::create()
    Init->>Init: MeterFactory::create()
    Init->>Init: LoggerFactory::create()
    Init->>Init: OtelLoggerBridgeでラップ

    PHP->>MW: handle(request)
    MW->>MW: W3C Trace Context 抽出
    MW->>MW: HTTP SERVER Span 作成・activate

    MW->>Handler: execute()
    Handler->>Handler: $logger->info("処理開始") ← Trace ID 自動付与
    Handler->>Repo: findAll() / save() / etc.
    Repo->>Repo: DB Client Span 作成
    Repo-->>Handler: result
    Repo->>Repo: DB Span 終了

    Handler-->>MW: response
    MW->>MW: Metrics 記録<br/>(Counter +1, Histogram ms)
    MW->>MW: HTTP SERVER Span 終了

    PHP-->>Client: HTTP Response

    Note over PHP,Col: shutdown() - フラッシュ処理
    PHP->>Col: POST /v1/traces (Spans)
    PHP->>Col: POST /v1/metrics (Counter, Histogram)
    PHP->>Col: POST /v1/logs (LogRecords + TraceID)

    Col->>Jaeger: Traces 転送
    Col->>Prom: Metrics 公開 (Prometheus scrape)
    Col->>Loki: Logs 転送 (OTLP HTTP)
    Col->>Col: Logs → stdout (debug exporter)

    Prom->>Grafana: メトリクスデータ提供
    Loki->>Grafana: ログデータ提供
    Jaeger->>Grafana: トレースデータ提供
```

---

## クラス依存関係

```mermaid
classDiagram
    class TelemetryInitializer {
        -TracerProviderInterface $tracerProvider
        -MeterProviderInterface $meterProvider
        -LoggerProviderInterface $loggerProvider
        -LoggerInterface $logger
        +initialize() void
        +getTracer(name) TracerInterface
        +getMeter(name) MeterInterface
        +getLogger(name) LoggerInterface
        +shutdown() void
    }

    class TracerFactory {
        +create() TracerProviderInterface
    }

    class MeterFactory {
        +create() MeterProviderInterface
    }

    class LoggerFactory {
        +create() LoggerProviderInterface
    }

    class OtelLoggerBridge {
        -LoggerInterface $otelLogger
        +log(level, message, context) void
        -mapSeverity(level) Severity
    }

    class TelemetryMiddleware {
        -TracerInterface $tracer
        -MeterInterface $meter
        +handle(request, handler) Response
        -recordMetrics(method, route, status, duration) void
    }

    class TodoHandler {
        -TodoRepository $repository
        -LoggerInterface $logger
        +handleGet() Response
        +handlePost() Response
        +handlePut(id) Response
        +handleDelete(id) Response
    }

    class TodoRepository {
        -PDO $db
        -TracerInterface $tracer
        +findAll() array
        +findById(id) array
        +save(title) array
        +update(id, data) array
        +delete(id) bool
    }

    TelemetryInitializer --> TracerFactory
    TelemetryInitializer --> MeterFactory
    TelemetryInitializer --> LoggerFactory
    TelemetryInitializer --> OtelLoggerBridge
    TelemetryMiddleware --> TelemetryInitializer
    TodoHandler --> TelemetryInitializer
    TodoHandler --> TodoRepository
    TodoRepository --> TelemetryInitializer
```

---

## インフラ構成

```mermaid
graph LR
    subgraph "Docker Network: apm-network"
        subgraph "php-app :8080"
            PHP[PHP Application]
        end

        subgraph "otel-collector :4317/:4318/:8889"
            COL[OTel Collector]
        end

        subgraph "jaeger :16686"
            JAE[Jaeger UI]
        end

        subgraph "prometheus :9090"
            PRO[Prometheus]
        end

        subgraph "loki :3100"
            LOK[Loki]
        end

        subgraph "grafana :3000"
            GRA[Grafana]
        end
    end

    PHP -- "OTLP HTTP :4318\n/v1/traces\n/v1/metrics\n/v1/logs" --> COL

    COL -- "gRPC :4317\nTraces" --> JAE
    COL -- "Prometheus Scrape\n:8889/metrics" --> PRO
    COL -- "OTLP HTTP :3100\nLogs" --> LOK
    PRO -- "DataSource" --> GRA
    JAE -- "DataSource" --> GRA
    LOK -- "DataSource" --> GRA
```

### サービス一覧

| サービス | ポート | 役割 |
|---------|-------|------|
| `php-app` | 8080 | PHP アプリケーション |
| `otel-collector` | 4317 / 4318 / 8889 | テレメトリ集約・転送 |
| `jaeger` | 16686 | 分散トレーシング可視化 |
| `prometheus` | 9090 | メトリクス保存・クエリ |
| `loki` | 3100 | ログ保存・クエリ |
| `grafana` | 3000 | 統合可視化ダッシュボード |
