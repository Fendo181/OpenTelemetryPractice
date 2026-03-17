# OpenTelemetry PHP × New Relic 検証手順

## 1. 環境起動

```bash
# プロジェクトディレクトリに移動
cd newrelic-opentelemetry-apm-ui

# 環境変数ファイルを準備
cp .env.example .env

# Docker環境をビルド・起動
docker-compose up --build -d

# Composerの依存関係をインストール
docker-compose exec php composer install

# 全サービスの起動を確認
docker-compose ps
```

すべてのサービスが `healthy` になるまで待機してください（通常30秒〜1分）。

---

## 2. Jaeger UIでの確認手順

### 基本確認

1. ブラウザで [http://localhost:16686](http://localhost:16686) を開く
2. 疎通確認スクリプトを実行してトレースを生成する:
   ```bash
   bash scripts/test-trace.sh
   ```
3. Jaeger UIの「Service」ドロップダウンから **`my-php-app`** を選択
4. 「Find Traces」をクリック

### `/api/hello` のトレース確認

1. Traces一覧から `/api/hello` のエントリをクリック
2. トレース詳細画面で以下を確認:
   - 親スパン `GET /api/hello` が存在する
   - その子スパンとして `greeting.logic` が表示される
   - 各スパンの属性（`http.method`, `greeting.message` など）が付与されている

### `/api/slow` のトレース確認

1. `/api/slow` のトレースをクリック
2. 以下を確認:
   - スパンの duration が 500ms〜1500ms の範囲内である
   - `sleep_ms` 属性にスリープ時間が記録されている

### `/api/error` のトレース確認

1. `/api/error` のトレースをクリック
2. 以下を確認:
   - スパンのステータスが **ERROR** になっている
   - `error.type` 属性に例外クラス名が記録されている
   - Logs セクションに例外スタックトレースが記録されている

---

## 3. New Relicへの切り替え手順

### 手順

1. `.env` ファイルを編集:

   ```bash
   # Jaeger向け設定をコメントアウト
   # OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318

   # New Relic向け設定を有効化
   OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.nr-data.net
   OTEL_EXPORTER_OTLP_HEADERS=api-key=YOUR_NR_LICENSE_KEY
   ```

2. `YOUR_NR_LICENSE_KEY` を実際のNew Relicライセンスキーに置き換え

3. コンテナを再起動:
   ```bash
   docker-compose restart php
   ```

4. 疎通確認:
   ```bash
   bash scripts/test-trace.sh
   ```

5. New Relic UIで確認:
   - [https://one.newrelic.com](https://one.newrelic.com) にログイン
   - **APM & Services** → **`my-php-app`** を選択
   - Distributed Tracing でスパンが表示されることを確認

### 重要な注意点

- New Relicに `telemetry.sdk.language=php` のResource Attributeが必要です（`.env.example` にプリセット済み）
- New Relicのデータ反映には数分のラグがある場合があります

---

## 4. トラブルシューティング

### スパンがJaeger UIに表示されない場合

| 確認項目 | 対処法 |
|---------|--------|
| Jaegerが起動しているか | `docker-compose ps` で `jaeger` サービスの状態を確認 |
| PHPコンテナのログ | `docker-compose logs php` でエラーがないか確認 |
| OTel拡張が有効か | `docker-compose exec php php -m \| grep opentelemetry` で確認 |
| エンドポイントURL | `.env` の `OTEL_EXPORTER_OTLP_ENDPOINT` が `http://jaeger:4318` か確認 |
| ネットワーク接続 | `docker-compose exec php curl -v http://jaeger:4318/v1/traces` で疎通確認 |

### Composerインストールが失敗する場合

```bash
# プラットフォーム要件を無視してインストール
docker-compose exec php composer install --ignore-platform-reqs

# minimum-stability の変更が必要な場合
# composer.json に "minimum-stability": "dev" を追加
```

### PHP-FPMでスパンが送信されない場合

- `TracingSetup.php` 内の `register_shutdown_function` が正しく設定されているか確認
- `BatchSpanProcessor` で問題がある場合は `SimpleSpanProcessor` に変更:
  ```php
  use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
  // BatchSpanProcessor の代わりに:
  // new BatchSpanProcessor($exporter)
  // ↓
  new SimpleSpanProcessor($exporter)
  ```

### コンテナの再ビルド

```bash
# キャッシュなしで再ビルド
docker-compose build --no-cache
docker-compose up -d
```
