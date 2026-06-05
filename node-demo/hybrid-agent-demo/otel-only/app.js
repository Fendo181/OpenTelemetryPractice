'use strict';

// ============================================================
// app.js - OtelOnly（純粋OTel）メインアプリ
//
// ※ otel-only と hybrid の app.js は完全に同一のコードです
//    違いは初期化ファイル（tracing.js / newrelic.js）のみ
//
// OTel API はここで使用するが、OTel SDK はここでは読み込まない
// SDK の初期化は tracing.js に委譲している（-r ./tracing.js で起動）
// ============================================================

const express = require('express');
const { trace, SpanStatusCode } = require('@opentelemetry/api');

const app = express();
const PORT = 3000;

// どちらのパターンで動作しているかを環境変数から判定する
const PATTERN = process.env.PATTERN_LABEL || 'OtelOnly';

// OTel トレーサーを取得する（SDK または NR Agent が背後でブリッジする）
const tracer = trace.getTracer('demo-app', '1.0.0');

// -----------------------------------------------------------
// ユーティリティ: 指定ミリ秒だけ非同期でスリープする
// -----------------------------------------------------------
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// -----------------------------------------------------------
// ユーティリティ: ランダムな UUID を生成する（crypto モジュール使用）
// -----------------------------------------------------------
const { randomUUID } = require('crypto');

// -----------------------------------------------------------
// ユーティリティ: min〜max の範囲でランダムな整数を返す
// -----------------------------------------------------------
const randomInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;

// -----------------------------------------------------------
// GET /
// ヘルスチェック兼パターン確認エンドポイント
// -----------------------------------------------------------
app.get('/', (req, res) => {
  res.json({ status: 'ok', pattern: PATTERN });
});

// -----------------------------------------------------------
// GET /order
// 注文処理シミュレーション
// OTel API でカスタムスパンをネスト作成して各フェーズを計測する
// -----------------------------------------------------------
app.get('/order', async (req, res) => {
  // 注文に紐づくメタデータを生成する
  const orderId = randomUUID();
  const customerType = Math.random() < 0.5 ? 'premium' : 'standard';
  const amount = randomInt(100, 9999);
  const startTime = Date.now();

  // 外側の try/catch: Express 4.x は非同期エラーを自動捕捉しないため
  // 明示的にレスポンスを返してスタックトレースの漏洩を防ぐ
  try {
    // ルートスパン: 注文処理全体をラップする
    await tracer.startActiveSpan('order.process', async (rootSpan) => {
      try {
        // ルートスパンに注文の共通属性を付与する
        rootSpan.setAttributes({
          'order.id': orderId,
          'customer.type': customerType,
          'order.amount': amount,
        });

        // --- フェーズ1: バリデーション（50ms）---
        await tracer.startActiveSpan('order.validate', async (validateSpan) => {
          validateSpan.setAttributes({
            'order.id': orderId,
            'customer.type': customerType,
            'order.amount': amount,
          });
          // バリデーション処理を模倣する
          await sleep(50);
          validateSpan.end();
        });

        // --- フェーズ2: DB 書き込み（100ms）---
        await tracer.startActiveSpan('order.db.insert', async (dbSpan) => {
          dbSpan.setAttributes({
            'order.id': orderId,
            'customer.type': customerType,
            'order.amount': amount,
            // DB 操作であることを示す属性
            'db.system': 'postgresql',
            'db.operation': 'INSERT',
          });
          // DB 書き込みを模倣する
          await sleep(100);
          dbSpan.end();
        });

        // --- フェーズ3: 外部通知 API（80ms）---
        await tracer.startActiveSpan('order.notify', async (notifySpan) => {
          notifySpan.setAttributes({
            'order.id': orderId,
            'customer.type': customerType,
            'order.amount': amount,
            // 外部 HTTP 呼び出しを示す属性
            'http.method': 'POST',
            'peer.service': 'notification-service',
          });
          // 外部通知 API 呼び出しを模倣する
          await sleep(80);
          notifySpan.end();
        });

        const duration = Date.now() - startTime;
        rootSpan.end();

        res.json({ orderId, customerType, amount, duration_ms: duration });
      } catch (err) {
        // 予期しないエラーをスパンに記録してから再スローする
        rootSpan.recordException(err);
        rootSpan.setStatus({ code: SpanStatusCode.ERROR, message: err.message });
        rootSpan.end();
        throw err;
      }
    });
  } catch (err) {
    if (!res.headersSent) {
      res.status(500).json({ error: 'Internal Server Error' });
    }
  }
});

// -----------------------------------------------------------
// GET /error
// 意図的にエラーを発生させるエンドポイント
// スパンに例外情報を記録して Error rate を確認するために使用する
// -----------------------------------------------------------
app.get('/error', async (req, res) => {
  await tracer.startActiveSpan('error.demo', async (span) => {
    try {
      // 意図的にエラーをスローする（ランダムではなく毎回発生）
      throw new Error('意図的なデモエラー: /error エンドポイントが呼ばれました');
    } catch (err) {
      // OTel API でスパンに例外を記録する
      span.recordException(err);
      // スパンのステータスを ERROR に設定する
      span.setStatus({ code: SpanStatusCode.ERROR, message: err.message });
      span.setAttributes({
        'error.type': 'DemoError',
        'exception.message': err.message,
      });
      span.end();

      res.status(500).json({
        error: err.message,
        pattern: PATTERN,
      });
    }
  });
});

// -----------------------------------------------------------
// グローバルエラーハンドラー
// Express のデフォルトエラーハンドラーはスタックトレースを含む HTML を返す場合があるため
// 汎用的なエラーレスポンスを返してクライアントへの情報漏洩を防ぐ
// -----------------------------------------------------------
// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
  res.status(500).json({ error: 'Internal Server Error' });
});

// -----------------------------------------------------------
// サーバー起動
// -----------------------------------------------------------
app.listen(PORT, () => {
  console.log(`[app] Pattern ${PATTERN} server running on port ${PORT}`);
});
