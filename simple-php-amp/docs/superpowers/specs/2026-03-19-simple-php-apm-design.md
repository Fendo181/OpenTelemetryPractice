# simple-php-apm 設計ドキュメント

**作成日**: 2026-03-19
**目的**: PHPerKaigi 2026 デモ用 - OpenTelemetry PHP SDK で APM を自作する

## プロジェクト概要

PHPerKaigi 2026 の登壇デモ用として、シンプルなTodoアプリにOpenTelemetry PHP SDKを使った計装（APM）を1から実装します。

このプロジェクトの目的は「APMが内部で何をしているか」をコードレベルで示すことです。

## アーキテクチャ

### システム構成図

```
┌─────────────────────────────────────────────────────────────┐
│  simple-php-apm/                                            │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ PHP Application (php-app:8080)                      │   │
│  │                                                     │   │
│  │  public/index.php (ルーティング)                    │   │
│  │         ↓                                          │   │
│  │  TelemetryMiddleware.php (HTTP Span作成)            │   │
│  │         ↓                                          │   │
│  │  TodoHandler.php (ビジネスロジック)                 │   │
│  │         ↓                                          │   │
│  │  TodoRepository.php (DB Span作成)                   │   │
│  │         ↓                                          │   │
│  │  SQLite (database/todos.db)                        │   │
│  │                                                     │   │
│  │  TelemetryInitializer.php                          │   │
│  │    ├─ TracerProvider (OTLP HTTP Exporter)          │   │
│  │    ├─ MeterProvider (OTLP HTTP Exporter)           │   │
│  │    └─ LoggerProvider (OTLP HTTP Exporter)          │   │
│  │                                                     │   │
│  └──────────────┬──────────────────────────────────────┘   │
│                 │ OTLP HTTP :4318                          │
│                 │ /v1/traces, /v1/metrics, /v1/logs        │
└─────────────────┼──────────────────────────────────────────┘
                  ▼
  ┌───────────────────────────────────────────┐
  │  OpenTelemetry Collector (otel:4318)      │
  │                                           │
  │  Pipelines:                               │
  │    traces  → otlp/jaeger                  │
  │    metrics → prometheus exporter (:8889)  │
  │    logs    → console (stdout)             │
  └─────┬──────────────┬───────────────────────┘
        │              │
        ▼              ▼
  ┌─────────┐    ┌──────────────┐
  │ Jaeger  │    │ Prometheus   │
  │ :16686  │    │ :9090        │
  └────┬────┘    └──────┬───────┘
       │                │
       └────────┬───────┘
                ▼
        ┌───────────────┐
        │   Grafana     │
        │   :3000       │
        │               │
        │ - Jaeger DS   │
        │ - Prometheus DS│
        │ - Dashboard   │
        └───────────────┘
```

### Docker Compose構成

**サービス一覧:**
1. **php-app** - PHP 8.2 + built-in server (:8080)
2. **otel-collector** - OpenTelemetry Collector Contrib (:4318)
3. **jaeger** - Jaeger All-in-One (:16686)
4. **prometheus** - Prometheus (:9090)
5. **grafana** - Grafana (:3000) + 自動プロビジョニング

**ボリューム:**
- `./php-app` → `/app` (PHPアプリコード)
- `./database` → `/app/database` (SQLiteファイル永続化)
- `./infra/grafana/provisioning` → `/etc/grafana/provisioning` (ダッシュボード自動設定)

### 起動フロー

```
1. docker compose up -d
   ↓
2. php-app が起動（PHP built-in server :8080）
   ↓
3. http://localhost:8080 にアクセス
   ↓
4. index.php がリクエストを受信
   ↓
5. TelemetryInitializer で Provider初期化（毎リクエスト）
   ↓
6. TelemetryMiddleware が HTTP Span作成
   ↓
7. TodoHandler → TodoRepository と処理（Child Span作成）
   ↓
8. OTLP HTTP で Collector に送信
   ↓
9. Jaeger/Prometheus/Grafana で可視化
```

## コンポーネント設計

### ディレクトリ構造

```
simple-php-apm/
├── docker-compose.yml
├── otel-collector-config.yaml
├── php-app/
│   ├── Dockerfile
│   ├── composer.json
│   ├── public/
│   │   └── index.php                        # エントリーポイント + ルーティング
│   ├── src/
│   │   ├── Telemetry/
│   │   │   ├── TelemetryInitializer.php     # 全Provider初期化（毎リクエスト）
│   │   │   ├── TracerFactory.php            # Tracer生成ロジック
│   │   │   ├── MeterFactory.php             # Meter生成ロジック
│   │   │   └── LoggerFactory.php            # Logger生成ロジック
│   │   ├── Middleware/
│   │   │   └── TelemetryMiddleware.php      # HTTPリクエスト全体をSpanでラップ
│   │   ├── Handler/
│   │   │   └── TodoHandler.php              # RESTエンドポイント処理
│   │   └── Repository/
│   │       └── TodoRepository.php           # SQLite操作（DB Span付き）
│   └── database/
│       ├── schema.sql                        # テーブル定義
│       └── todos.db                          # SQLiteファイル（自動生成）
└── infra/
    ├── prometheus/
    │   └── prometheus.yml                    # Prometheus設定
    └── grafana/
        └── provisioning/
            ├── datasources/
            │   ├── jaeger.yaml               # Jaeger DataSource
            │   └── prometheus.yaml           # Prometheus DataSource
            └── dashboards/
                ├── dashboard.yaml            # ダッシュボードプロバイダー設定
                └── todo-apm-dashboard.json   # メトリクスダッシュボード定義
```

### 主要コンポーネント

#### 1. public/index.php - エントリーポイント

**役割:**
- HTTPリクエストを受信
- ルーティング（GET/POST/PUT/DELETE + パス解析）
- TelemetryMiddleware でラップしてから TodoHandler に処理を委譲
- エラーハンドリング（404/405）

**重要ポイント:**
- Pure PHPなので `$_SERVER['REQUEST_METHOD']` と `$_SERVER['REQUEST_URI']` で手動ルーティング
- TelemetryInitializer の初期化は **毎リクエスト** 実行（PHP Shared Nothing）
- Content-Type: application/json でJSON APIとして動作

#### 2. src/Telemetry/TelemetryInitializer.php - Provider集約

**役割:**
- TracerProvider / MeterProvider / LoggerProvider の初期化を1箇所に集約
- OTLP HTTP Exporter の設定（エンドポイント: `http://otel-collector:4318`）
- リソース属性の設定（`service.name`, `service.version`）
- グローバルなTracer/Meter/Loggerの取得メソッド提供

**重要ポイント:**
- **PHPのShared Nothingアーキテクチャ**: リクエストごとに初期化が必要（コメントで強調）
- **商用APMとの比較**: 「DatadogやNew Relicの拡張機能は、この初期化を自動でやっている」とコメントで説明
- シングルトンパターンは **使わない**（リクエストごとに破棄されるため意味がない）

#### 3. src/Telemetry/TracerFactory.php - Tracer生成

**役割:**
- TracerProvider の作成と設定
- OTLP HTTP Trace Exporter の初期化
- AlwaysOnSampler の設定（デモなので全トレース記録）
- BatchSpanProcessor の設定

**実装するSampler:**
- **AlwaysOnSampler**: 全リクエストをトレース（デモ用）
- コメントで本番環境では ParentBasedSampler + TraceIdRatioBased を推奨

#### 4. src/Telemetry/MeterFactory.php - Meter生成

**役割:**
- MeterProvider の作成と設定
- OTLP HTTP Metric Exporter の初期化
- PeriodicExportingMetricReader の設定（60秒間隔）

**実装するMetrics:**
- **Counter**: `http.requests.total` （エンドポイント別・ステータス別）
- **Histogram**: `http.request.duration` （レイテンシ分布、単位: ms）
- **ObservableGauge**: `process.memory.usage` （PHPメモリ使用量、単位: bytes）

#### 5. src/Telemetry/LoggerFactory.php - Logger生成

**役割:**
- LoggerProvider の作成と設定
- OTLP HTTP Log Exporter の初期化
- Monolog との統合（OpenTelemetryLoggerProvider経由）

**重要ポイント:**
- **Trace-Log相関**: アクティブなSpanのTrace IDを自動でログに含める
- **構造化ログ**: JSON形式で出力（key-valueペア）
- **ログレベル**: INFO / ERROR を使い分け

#### 6. src/Middleware/TelemetryMiddleware.php - HTTPトレース

**役割:**
- HTTPリクエスト全体を1つのSpanでラップ
- SpanKind: SERVER を設定
- HTTP属性を記録（`http.method`, `http.route`, `http.status_code`）
- エラー時にSpanにエラーを記録（`recordException()`）
- Metricsを記録（Counter + Histogram）
- W3C Trace Context の抽出（inbound propagation）

**処理フロー:**
```
1. Span開始（SpanKind::SERVER）
2. HTTP属性をセット
3. TodoHandler を実行
4. レスポンスステータスをSpanに記録
5. エラー時は recordException() + setStatus(ERROR)
6. Span終了
7. Metrics記録（Counter + Histogram）
```

#### 7. src/Handler/TodoHandler.php - ビジネスロジック

**役割:**
- RESTエンドポイントの実装（GET/POST/PUT/DELETE）
- バリデーション（空のタイトルチェック）
- TodoRepository の呼び出し
- ダミー外部サービス呼び出し（Context Propagation デモ用）
- ログ出力（構造化ログ + Trace ID付き）

**エンドポイント:**
- `GET /todos` - 全Todo取得
- `GET /todos/{id}` - 特定Todo取得（404エラーデモ用）
- `POST /todos` - Todo作成（バリデーションエラーデモ用）
- `PUT /todos/{id}` - Todo更新（完了フラグ切り替え）
- `DELETE /todos/{id}` - Todo削除

**ダミー外部サービス:**
- Todo作成時に「通知サービス」を呼び出す（実際にはHTTPリクエストを送らない）
- `traceparent` ヘッダーの生成ロジックをコメント付きで実装
- 「ここでW3C Trace Contextヘッダーを付与している」と明示

#### 8. src/Repository/TodoRepository.php - DB操作

**役割:**
- SQLite操作（SELECT/INSERT/UPDATE/DELETE）
- 各DB操作をChild Span（SpanKind::CLIENT）でラップ
- Span属性に実行したSQLを記録（`db.statement`）
- DB属性を記録（`db.system`, `db.name`）

**Span構造例:**
```
HTTP GET /todos (SERVER Span)
  └─ db.query: SELECT * FROM todos (CLIENT Span)
```

## データフロー

### リクエストフロー例（POST /todos）

```
1. クライアント → POST /todos {"title":"買い物"}
   ↓
2. index.php: ルーティング処理
   ↓
3. TelemetryInitializer: Provider初期化（毎リクエスト）
   ↓
4. TelemetryMiddleware: Span開始（SpanKind::SERVER）
   - 属性: http.method=POST, http.route=/todos
   ↓
5. TodoHandler::create()
   - バリデーション（タイトル空チェック）
   - Log: "Todo作成リクエストを受信"
   - ダミー外部サービス呼び出し（Context Propagation）
   ↓
6. TodoRepository::create()
   - Child Span開始（SpanKind::CLIENT）
   - 属性: db.statement="INSERT INTO todos..."
   - SQLite INSERT実行
   - Span終了
   ↓
7. TodoHandler: Log: "Todo作成完了"
   ↓
8. TelemetryMiddleware: Span終了 + Metrics記録
   - Counter: http.requests.total{endpoint=/todos, status=201} +1
   - Histogram: http.request.duration = 45ms
   ↓
9. OTLP HTTP → Collector → Jaeger/Prometheus
```

### Trace構造例

```
Trace ID: 1234567890abcdef
├─ Span: HTTP POST /todos (SpanKind::SERVER, duration: 45ms)
│  ├─ http.method: POST
│  ├─ http.route: /todos
│  ├─ http.status_code: 201
│  └─ Child Spans:
│     └─ Span: db.query: INSERT INTO todos (SpanKind::CLIENT, duration: 5ms)
│        ├─ db.system: sqlite
│        ├─ db.name: todos.db
│        └─ db.statement: INSERT INTO todos (title, completed) VALUES (?, ?)
```

## エラーハンドリング

### エラーパターン

#### 1. 404 Not Found

**トリガー**: `GET /todos/999` (存在しないID)

**Span処理:**
- `span->setStatus(StatusCode::ERROR)`
- `span->setAttribute('http.status_code', 404)`
- `span->setAttribute('error.type', 'not_found')`

**Log**: "Todo ID 999 が見つかりません"

**レスポンス:**
```json
{
  "error": "Todo not found",
  "id": 999
}
```

#### 2. 400 Bad Request

**トリガー**: `POST /todos {"title":""}` (空タイトル)

**Span処理:**
- `span->setStatus(StatusCode::ERROR)`
- `span->setAttribute('http.status_code', 400)`
- `span->setAttribute('error.type', 'validation')`

**Log**: "バリデーションエラー: タイトルが空です"

**レスポンス:**
```json
{
  "error": "Validation failed",
  "message": "Title is required"
}
```

#### 3. 405 Method Not Allowed

**トリガー**: `PATCH /todos` (未実装メソッド)

**Span処理:**
- `span->setStatus(StatusCode::ERROR)`
- `span->setAttribute('http.status_code', 405)`
- `span->setAttribute('error.type', 'method_not_allowed')`

**Log**: "未サポートのメソッド: PATCH"

**レスポンス:**
```json
{
  "error": "Method not allowed",
  "method": "PATCH"
}
```

## 計装要件

### Traces（OpenTelemetry PHP SDK）

- HTTPリクエスト単位でSpanを生成（SpanKind::SERVER）
- DBクエリをChildSpanとして計測（SpanKind::CLIENT）
- クエリ文字列をattributeに含める（`db.statement`）
- エラー発生時にSpanにエラー情報をセット（`recordException()` + `setStatus(ERROR)`）
- ContextPropagation（W3C Trace Context）を実装
  - Inbound: `traceparent` ヘッダーの抽出
  - Outbound: ダミー外部サービス呼び出し時に `traceparent` ヘッダーを生成（コメント付き）
- Exporterは OTLP/HTTP（OTel Collector経由）

### Metrics

- **Counter**: `http.requests.total`
  - ラベル: `endpoint` (例: /todos), `status` (例: 200, 404)
  - 用途: エンドポイント別・ステータスコード別のリクエスト数
- **Histogram**: `http.request.duration`
  - 単位: ms
  - 用途: HTTPレスポンスタイムの分布
- **ObservableGauge**: `process.memory.usage`
  - 単位: bytes
  - 用途: PHPメモリ使用量（Shared Nothing特性を説明するため）
- Exporterは OTLP/HTTP（OTel Collector経由）

### Logs

- OTel LoggerProviderを使った構造化ログ（JSON形式）
- リクエストのTrace IDをログに含める（Trace-Log相関）
- ログレベル（INFO / ERROR）を適切に使い分ける
- Exporterは OTLP/HTTP（OTel Collector経由、標準出力にも出力）

## デモシナリオ

### 動作確認手順

```bash
# 1. 起動
docker compose up -d

# 2. Todo作成（成功）
curl -X POST http://localhost:8080/todos \
  -H "Content-Type: application/json" \
  -d '{"title":"PHPerKaigi準備"}'

# 3. Todo一覧取得
curl http://localhost:8080/todos

# 4. 特定Todo取得
curl http://localhost:8080/todos/1

# 5. Todo更新（完了フラグ）
curl -X PUT http://localhost:8080/todos/1 \
  -H "Content-Type: application/json" \
  -d '{"completed":true}'

# 6. 404エラー確認
curl http://localhost:8080/todos/999

# 7. バリデーションエラー確認
curl -X POST http://localhost:8080/todos \
  -H "Content-Type: application/json" \
  -d '{"title":""}'

# 8. Todo削除
curl -X DELETE http://localhost:8080/todos/1
```

### 可視化UIの確認

```bash
# Jaeger UIでトレース確認
open http://localhost:16686

# Grafanaでメトリクス確認（ログイン: admin / admin）
open http://localhost:3000

# Prometheus UI（直接確認）
open http://localhost:9090
```

### 発表時のデモフロー（5分想定）

1. **Todo作成 → Jaegerでトレース確認**
   - `POST /todos` でTodoを作成
   - Jaeger UIで Trace を開く
   - 「ここがHTTP SERVER Spanです。この下にDB CLIENT Spanがネストしています」
   - 「このattribute `db.statement` がSQLクエリです。商用APMはこれを自動で取ります」

2. **エラー → Spanにエラー記録を確認**
   - `GET /todos/999` で404エラーを発生
   - Jaeger UIで ERROR状態のSpanを開く
   - 「エラーがSpanに記録されています。`error.type=not_found` です」
   - 「商用APMのアラートは、このERROR Spanを検知しています」

3. **Grafanaでメトリクス確認**
   - Grafanaの自動プロビジョニングされたダッシュボードを開く
   - 「Counterがここのリクエスト数グラフになっています」
   - 「Histogramがレイテンシ分布グラフです。P50/P95/P99が見えます」

4. **W3C Trace Context（時間があれば）**
   - TodoHandler のコードを見せる
   - 「ここで `traceparent` ヘッダーを生成しています」
   - 「このヘッダーがマイクロサービス間でTrace IDを伝播させる仕組みです」

## 技術スタック

### PHP依存関係（composer.json）

```json
{
  "require": {
    "php": "^8.2",
    "open-telemetry/sdk": "^1.0",
    "open-telemetry/exporter-otlp": "^1.0",
    "open-telemetry/api": "^1.0",
    "monolog/monolog": "^3.0"
  }
}
```

### インフラ

- **PHP**: 8.2
- **Database**: SQLite 3
- **Web Server**: PHP built-in server
- **OpenTelemetry Collector**: otel/opentelemetry-collector-contrib:latest
- **Jaeger**: jaegertracing/all-in-one:latest
- **Prometheus**: prom/prometheus:latest
- **Grafana**: grafana/grafana:latest

## コーディング方針

### 高密度コメント

- ほぼ全ての行に意図を説明するコメントを付ける
- 「なぜこう書くのか」を明記
- 「商用APMではここが自動化されている」を説明
- 発表時にコードを読み上げるだけで理解できるレベル

### PHP Shared Nothingアーキテクチャの明示

- リクエストごとにProvider初期化が必要な理由をコメントで説明
- シングルトンパターンを使わない理由を説明
- 商用APMのPHP拡張機能（C言語実装）との違いを説明

### 実装パターン

- **集中管理型**: Telemetryコードとビジネスロジックを完全に分離
- **教育的**: 「どこで何をしているか」が一目瞭然
- **YAGNIの徹底**: 不要な抽象化を避け、OTel SDKの生のAPIを使う

## 優先順位

1. **Traces が完全に動くこと**（最重要）
2. **Docker Compose で一発起動できること**
3. **Metrics が動くこと**
4. **Logs（Trace-Log相関）が動くこと**
5. **Grafanaダッシュボードの自動プロビジョニング**

## 成功基準

- `docker compose up -d` で全サービスが起動する
- `curl` でTodo操作ができる
- Jaeger UIでトレースが見える（親子Span構造が正しい）
- Grafanaでメトリクスグラフが表示される（自動プロビジョニング済み）
- エラー時にSpanにERROR状態が記録される
- ログにTrace IDが含まれている（構造化ログ）
- README.mdの手順通りにデモができる

## 既存プロジェクトとの差別化

### init-study/ との違い

- **init-study/**: 3シグナルを独立したExampleファイルで実装（学習用）
- **simple-php-apm**: 実際のTodoアプリに3シグナルを統合（実践用）

### newrelic-opentelemetry-apm-ui/ との違い

- **newrelic-opentelemetry-apm-ui/**: Slim Framework + Nginx + PHP-FPM
- **simple-php-apm**: Pure PHP + built-in server（よりシンプル、教育的）
