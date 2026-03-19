# simple-php-apm

**PHPerKaigi 2026 デモ用** - OpenTelemetry PHP SDK で APM を自作する

Pure PHPでOpenTelemetryの3シグナル（Traces/Metrics/Logs）を実装したシンプルなTodo APIです。
「APMが内部で何をしているか」をコードレベルで示すことを目的としています。

## プロジェクト概要

- **フレームワーク**: Pure PHP（フレームワーク不使用）
- **Webサーバー**: PHP built-in server
- **データベース**: SQLite
- **OpenTelemetry**: 手動計装（Manual Instrumentation）
- **可視化**: Jaeger（Traces）+ Grafana（Metrics）

## アーキテクチャ

```
┌─────────────────────────────────────┐
│  PHP Application (:8080)            │
│  ├─ TelemetryMiddleware (HTTP Span) │
│  ├─ TodoHandler (Business Logic)    │
│  └─ TodoRepository (DB Span)        │
└────────────┬────────────────────────┘
             │ OTLP HTTP :4318
             ▼
┌─────────────────────────────────────┐
│  OpenTelemetry Collector            │
│  ├─ Traces  → Jaeger                │
│  ├─ Metrics → Prometheus            │
│  └─ Logs    → Console                │
└─────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  Jaeger UI (:16686)                 │
│  Grafana (:3000)                    │
└─────────────────────────────────────┘
```

## クイックスタート

### 1. 依存関係のインストール

```bash
cd php-app
composer install
cd ..
```

### 2. Docker Composeで起動

```bash
docker compose up -d
```

### 3. 動作確認

```bash
# Todo作成
curl -X POST http://localhost:8080/todos \
  -H "Content-Type: application/json" \
  -d '{"title":"PHPerKaigi準備"}'

# Todo一覧取得
curl http://localhost:8080/todos

# 特定Todo取得
curl http://localhost:8080/todos/1
```

### 4. 可視化UIを確認

- **Jaeger UI**: http://localhost:16686
  - サービス「simple-php-apm」を選択してトレースを確認
- **Grafana**: http://localhost:3000
  - ログイン: `admin` / `admin`
  - 「Simple PHP APM - PHPerKaigi 2026」ダッシュボードを開く
- **Prometheus**: http://localhost:9090

## APIエンドポイント

### GET /todos
Todo一覧取得

```bash
curl http://localhost:8080/todos
```

### GET /todos/{id}
特定Todo取得

```bash
curl http://localhost:8080/todos/1
```

### POST /todos
Todo作成

```bash
curl -X POST http://localhost:8080/todos \
  -H "Content-Type: application/json" \
  -d '{"title":"買い物"}'
```

### PUT /todos/{id}
Todo更新（完了フラグ切り替え）

```bash
curl -X PUT http://localhost:8080/todos/1 \
  -H "Content-Type: application/json" \
  -d '{"completed":true}'
```

### DELETE /todos/{id}
Todo削除

```bash
curl -X DELETE http://localhost:8080/todos/1
```

## エラーデモ

### 404 Not Found

```bash
curl http://localhost:8080/todos/999
# → JaegerでERRORステータスのSpanが記録される
```

### 400 Bad Request (バリデーションエラー)

```bash
curl -X POST http://localhost:8080/todos \
  -H "Content-Type: application/json" \
  -d '{"title":""}'
# → Spanにerror.type=validationが記録される
```

## ディレクトリ構造

```
simple-php-apm/
├── docker-compose.yml                 # Docker Compose設定
├── otel-collector-config.yaml         # OpenTelemetry Collector設定
├── php-app/
│   ├── Dockerfile                     # PHPアプリのDockerfile
│   ├── composer.json                  # PHP依存関係
│   ├── public/
│   │   └── index.php                  # エントリーポイント + ルーティング
│   ├── src/
│   │   ├── Telemetry/
│   │   │   ├── TelemetryInitializer.php  # 全Provider初期化
│   │   │   ├── TracerFactory.php         # TracerProvider生成
│   │   │   ├── MeterFactory.php          # MeterProvider生成
│   │   │   └── LoggerFactory.php         # LoggerProvider生成
│   │   ├── Middleware/
│   │   │   └── TelemetryMiddleware.php   # HTTP Span作成
│   │   ├── Handler/
│   │   │   └── TodoHandler.php           # ビジネスロジック
│   │   └── Repository/
│   │       └── TodoRepository.php        # DB操作 + DB Span
│   └── database/
│       ├── schema.sql                    # テーブル定義
│       └── todos.db                      # SQLiteファイル（自動生成）
└── infra/
    ├── prometheus/
    │   └── prometheus.yml                # Prometheus設定
    └── grafana/
        └── provisioning/
            ├── datasources/              # DataSource自動設定
            │   ├── jaeger.yaml
            │   └── prometheus.yaml
            └── dashboards/               # Dashboard自動設定
                ├── dashboard.yaml
                └── todo-apm-dashboard.json
```

## 主要コンポーネント

### TelemetryInitializer
- TracerProvider / MeterProvider / LoggerProvider の初期化を集約
- PHPのShared Nothingアーキテクチャにより、毎リクエスト初期化が必要
- 商用APMのPHP拡張機能は、この初期化を自動化している

### TelemetryMiddleware
- HTTPリクエスト全体を1つのSpan（SpanKind::SERVER）でラップ
- HTTP属性（method, route, status_code）を記録
- エラー時にSpanにERROR状態を記録
- Metricsを記録（Counter + Histogram）

### TodoRepository
- SQLite操作を各クエリごとにChild Span（SpanKind::CLIENT）でラップ
- Span属性にSQLを記録（`db.statement`）
- 商用APMは、PDOをフックしてこれを自動化している

## 実装している3シグナル

### Traces（トレーシング）
- HTTPリクエストをSpanツリーとして記録
- 親子関係: HTTP SERVER Span → DB CLIENT Span
- エラー時にSpanにERROR状態を記録
- Jaeger UIで可視化

### Metrics（メトリクス）
- **Counter**: `http.requests.total` - エンドポイント別リクエスト数
- **Histogram**: `http.request.duration` - レスポンスタイム分布（P50/P95/P99）
- Prometheusで収集、Grafanaで可視化

### Logs（ログ）
- 構造化ログ（JSON形式）
- Trace IDを自動付与（Trace-Log相関）
- ログレベル（INFO / ERROR）を使い分け
- OTLP経由でCollectorに送信

## 技術スタック

- **PHP**: 8.2
- **OpenTelemetry SDK**: ^1.0
- **Database**: SQLite 3
- **OpenTelemetry Collector**: otel/opentelemetry-collector-contrib:latest
- **Jaeger**: jaegertracing/all-in-one:latest
- **Prometheus**: prom/prometheus:latest
- **Grafana**: grafana/grafana:latest

## デモシナリオ（PHPerKaigi 2026用）

### 1. Todo作成 → Jaegerでトレース確認
1. `POST /todos` でTodoを作成
2. Jaeger UIで Trace を開く
3. HTTP SERVER Span → DB CLIENT Span のネスト構造を確認
4. `db.statement` 属性にSQLクエリが記録されていることを確認

### 2. エラー → Spanにエラー記録を確認
1. `GET /todos/999` で404エラーを発生
2. Jaeger UIで ERROR状態のSpanを確認
3. `error.type=not_found` が記録されていることを確認

### 3. Grafanaでメトリクス確認
1. Grafanaダッシュボードを開く
2. Counterによるリクエスト数グラフを確認
3. HistogramによるレイテンシP50/P95/P99を確認

### 4. W3C Trace Context（時間があれば）
1. `TodoHandler.php` のコードを見せる
2. `traceparent` ヘッダー生成ロジックを説明
3. マイクロサービス間でTrace IDが伝播する仕組みを説明

## トラブルシューティング

### Collectorに接続できない

```bash
# Collectorのログを確認
docker compose logs otel-collector

# PHPアプリのログを確認
docker compose logs php-app
```

### Jaeger UIにトレースが表示されない

1. Collectorが起動しているか確認: `docker compose ps`
2. PHPアプリからCollectorにリクエストが送られているか確認: `docker compose logs otel-collector`
3. Jaeger UIで時刻範囲を広げて検索

### Grafanaでメトリクスが表示されない

1. Prometheusがメトリクスをスクレイプしているか確認: http://localhost:9090/targets
2. Prometheus UIでクエリを直接実行: `simple_php_apm_http_requests_total`

## クリーンアップ

```bash
# コンテナ停止・削除
docker compose down

# ボリュームも削除（データベース含む）
docker compose down -v
```

## ライセンス

MIT License

## 作成者

PHPerKaigi 2026 デモ用プロジェクト
