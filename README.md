# OpenTelemetry PHP APM デモ

PHPカンファレンス向け OpenTelemetry PHP SDK の実装サンプルです。
Traces / Metrics / Logs の **3シグナル** を実際のコードで示します。

## アーキテクチャ

```
┌─────────────────────────────────────────────────────────────┐
│  PHP Application (php-otel-demo)                            │
│                                                             │
│  ┌─────────────┐ ┌──────────────┐ ┌────────────────┐      │
│  │TraceExample │ │MetricsExample│ │  LogsExample   │      │
│  │             │ │              │ │                │      │
│  │ Span作成    │ │ Counter      │ │ 構造化ログ     │      │
│  │ 属性付与    │ │ Histogram    │ │ Trace相関      │      │
│  │ 例外記録    │ │ Gauge        │ │ ログレベル別   │      │
│  └──────┬──────┘ └──────┬───────┘ └───────┬────────┘      │
│         │               │                  │               │
│         └───────────────┼──────────────────┘               │
│                         │ OTLP HTTP                        │
└─────────────────────────┼─────────────────────────────────┘
                          ▼
         ┌────────────────────────────┐
         │  OpenTelemetry Collector   │
         │  (otel-collector:4318)     │
         └──────┬──────────┬──────────┘
                │          │          │
          Traces│    Metrics│     Logs│
                ▼          ▼          ▼
         ┌──────────┐ ┌──────────┐ ┌──────┐
         │  Jaeger  │ │Prometheus│ │ Loki │
         │ :16686   │ │  :9090   │ │:3100 │
         └──────────┘ └────┬─────┘ └──┬───┘
                           │          │
                      ┌────▼──────────▼────┐
                      │      Grafana       │
                      │      :3000         │
                      └────────────────────┘
```

### システムアーキテクチャ図 (Mermaid)

```mermaid
graph LR
    subgraph App["PHP Application (php-otel-demo)"]
        direction TB
        Bootstrap["bootstrap.php\nSDK 初期化"]
        TraceEx["TraceExample\nSpan作成・属性・例外"]
        MetricsEx["MetricsExample\nCounter/Histogram/Gauge"]
        LogsEx["LogsExample\n構造化ログ・Trace相関"]
        OrderSvc["OrderService\n3シグナル統合"]
    end

    subgraph Collector["OpenTelemetry Collector"]
        direction TB
        Recv["OTLP Receiver\n:4317 gRPC / :4318 HTTP"]
        Proc["Processors\nMemoryLimiter → Batch"]
        ExpT["Exporter\notlp/jaeger"]
        ExpM["Exporter\nprometheus :8889"]
        ExpL["Exporter\nloki push"]
    end

    subgraph Storage["ストレージ"]
        Jaeger["Jaeger\n:16686"]
        Prometheus["Prometheus\n:9090"]
        Loki["Loki\n:3100"]
    end

    subgraph Viz["可視化"]
        Grafana["Grafana\n:3000"]
    end

    App -->|"OTLP HTTP\n/v1/traces\n/v1/metrics\n/v1/logs"| Recv
    Recv --> Proc
    Proc --> ExpT --> Jaeger
    Proc --> ExpM --> Prometheus
    Proc --> ExpL --> Loki
    Prometheus --> Grafana
    Loki --> Grafana
```

### テレメトリ・シグナルパイプライン (Mermaid)

```mermaid
flowchart TB
    subgraph PHP["PHP SDK (bootstrap.php)"]
        TP["TracerProvider\n+AlwaysOnSampler"]
        MP["MeterProvider\n+ExportingReader"]
        LP["LoggerProvider\n+MonologBridge"]
    end

    subgraph Pipeline["OTel Collector パイプライン"]
        direction LR
        subgraph Traces["traces pipeline"]
            TR["otlp receiver"] --> TML["memory_limiter"] --> TB["batch"] --> TJ["otlp/jaeger"]
        end
        subgraph Metrics["metrics pipeline"]
            MR["otlp receiver"] --> MML["memory_limiter"] --> MB["batch"] --> MRS["resource"] --> MP2["prometheus :8889"]
        end
        subgraph Logs["logs pipeline"]
            LR2["otlp receiver"] --> LML["memory_limiter"] --> LB["batch"] --> LL["loki"]
        end
    end

    TP -->|"OTLP HTTP /v1/traces\napplication/x-protobuf"| TR
    MP -->|"OTLP HTTP /v1/metrics\napplication/x-protobuf"| MR
    LP -->|"OTLP HTTP /v1/logs\napplication/x-protobuf"| LR2

    TJ -->|"OTLP gRPC :4317"| Jaeger["Jaeger :16686"]
    MP2 -->|"scrape :8889"| Prom["Prometheus :9090"]
    LL -->|"push /loki/api/v1/push"| LokiDB["Loki :3100"]

    Prom --> Grafana["Grafana :3000"]
    LokiDB --> Grafana
```

### OrderService ワークフロー (Mermaid)

```mermaid
sequenceDiagram
    actor Client
    participant OS as OrderService<br/>(SERVER Span)
    participant V as validateOrder<br/>(INTERNAL Span)
    participant INV as inventory-service<br/>(CLIENT Span)
    participant PAY as payment-service<br/>(CLIENT Span)
    participant DB as db.orders<br/>(CLIENT Span)
    participant OTel as OTel Collector

    Client->>+OS: createOrder(order)
    OS->>OTel: 📝 Log INFO: 注文処理を開始します

    OS->>+V: validateOrder()
    Note over V: items / qty / price チェック
    V-->>-OS: ✅ validation.passed

    OS->>+INV: POST /api/check (skus)
    Note over INV: 在庫確認シミュレーション
    INV-->>-OS: HTTP 200 (all_available: true)
    OS->>OTel: 📝 Log DEBUG: 在庫確認完了

    OS->>+PAY: POST /api/charge (amount)
    Note over PAY: 決済処理シミュレーション
    PAY-->>-OS: HTTP 200 (transaction_id)
    OS->>OTel: 📝 Log INFO: 決済処理完了

    OS->>+DB: INSERT INTO orders
    Note over DB: DB 保存シミュレーション
    DB-->>-OS: db.row.inserted

    OS->>OTel: 📊 Counter: orders.created.total +1
    OS->>OTel: 📊 Histogram: orders.value (amount)
    OS->>OTel: 📊 Histogram: orders.processing_time (ms)
    OS->>OTel: 📝 Log INFO: 注文が正常に完了しました

    OS-->>-Client: {status: completed, transaction_id}
```

## プロジェクト構成

```
.
├── docker-compose.yml           # 全サービス定義
├── otel-collector-config.yaml   # Collector パイプライン設定
├── composer.json                # PHP 依存関係
├── src/
│   ├── bootstrap.php            # SDK 初期化 (Provider / Exporter / Propagator)
│   ├── TraceExample.php         # Span 作成・属性・例外記録デモ
│   ├── MetricsExample.php       # Counter / Histogram / Gauge デモ
│   ├── LogsExample.php          # 構造化ログ + Trace 相関デモ
│   └── OrderService.php         # 3シグナル統合デモサービス
├── public/
│   └── index.php                # エントリーポイント
└── infra/
    ├── prometheus/prometheus.yml
    └── grafana/provisioning/
        ├── datasources/
        └── dashboards/
```

## セットアップ手順

### 前提条件

- Docker Desktop 4.x 以上
- Docker Compose v2 以上
- PHP 8.1 以上 + Composer (ローカル実行の場合)

### 1. リポジトリのクローン

```bash
git clone https://github.com/Fendo181/OpenTelemetryPractice.git
cd OpenTelemetryPractice
```

### 2. Docker 環境の起動

```bash
# バックエンドサービスを起動
docker compose up -d otel-collector jaeger prometheus loki grafana

# 起動確認
docker compose ps
```

### 3. デモの実行

```bash
# PHP アプリを Docker で実行
docker compose run --rm app

# または Composer インストール後にローカルで実行
composer install
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318 php public/index.php
```

### 4. 結果の確認

| シグナル | ツール | URL |
|----------|--------|-----|
| **Traces** | Jaeger UI | http://localhost:16686 |
| **Metrics** | Grafana | http://localhost:3000 |
| **Logs** | Grafana + Loki | http://localhost:3000 |
| Prometheus | Prometheus UI | http://localhost:9090 |

Grafana のログイン: `admin` / `admin`

## デモ内容

### Demo 1: Tracing

- `HTTP GET /orders/42` をシミュレートしたネストした Span 構造
- DB クエリ・外部 API の CLIENT Span
- `SpanKind` による分類 (SERVER / CLIENT / INTERNAL)
- 例外の `recordException()` によるキャプチャ

### Demo 2: Metrics

| Instrument | 使用例 |
|------------|--------|
| `Counter` | HTTPリクエスト数・エラー数 |
| `Histogram` | レイテンシ分布・注文金額分布 |
| `UpDownCounter` | アクティブセッション数 |
| `ObservableGauge` | メモリ使用量 (非同期) |
| `ObservableCounter` | CPU 時間 (非同期) |

### Demo 3: Logs

- 構造化ログ（コンテキスト付き JSON）
- ログレベル別の使い分け (DEBUG / INFO / WARNING / ERROR / CRITICAL)
- アクティブな Span の `trace_id` / `span_id` を自動付与
- Monolog + OpenTelemetry ブリッジによる統合

### Demo 4: OrderService (3シグナル統合)

実際のECサイトの注文処理フローを模した統合デモ:

1. 注文受付 → `HTTP SERVER Span` 開始
2. バリデーション → `INTERNAL Span`
3. 在庫確認 → `CLIENT Span` (外部サービス呼び出し)
4. 決済処理 → `CLIENT Span` (外部サービス呼び出し)
5. DB 保存 → `CLIENT Span` (DB クエリ)
6. 各ステップで構造化ログを出力
7. 成功・失敗のメトリクスを記録

## 環境変数

| 変数名 | デフォルト値 | 説明 |
|--------|-------------|------|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Collector エンドポイント |
| `OTEL_SERVICE_NAME` | `php-otel-demo` | サービス名 |
| `OTEL_LOG_LEVEL` | `info` | SDK ログレベル |

## 依存ライブラリ

| パッケージ | バージョン | 役割 |
|-----------|-----------|------|
| `open-telemetry/api` | ^1.1 | OTel API 定義 |
| `open-telemetry/sdk` | ^1.1 | TracerProvider / MeterProvider / LoggerProvider |
| `open-telemetry/exporter-otlp` | ^1.1 | OTLP HTTP/gRPC Exporter |
| `open-telemetry/opentelemetry-logger-monolog` | ^1.1 | Monolog ブリッジ |
| `monolog/monolog` | ^3.0 | ロギングライブラリ |

## クリーンアップ

```bash
docker compose down -v
```
