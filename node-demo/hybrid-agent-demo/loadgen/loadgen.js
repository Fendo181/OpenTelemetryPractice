'use strict';

// ============================================================
// loadgen.js - 負荷生成スクリプト
// アプリの全エンドポイントに定期的にリクエストを送信する
// Node.js 20 の組み込み fetch を使用（node-fetch は不要）
// ============================================================

// リクエスト先ホスト（docker-compose のサービス名）
const BASE_URL = 'http://app:3000';

// リクエスト対象のエンドポイント一覧
const ENDPOINTS = ['/', '/order', '/error'];

// -----------------------------------------------------------
// 配列をランダムな順番にシャッフルして返す（Fisher-Yates）
// -----------------------------------------------------------
function shuffle(arr) {
  const result = [...arr];
  for (let i = result.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [result[i], result[j]] = [result[j], result[i]];
  }
  return result;
}

// -----------------------------------------------------------
// 指定ミリ秒だけ待機する
// -----------------------------------------------------------
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// -----------------------------------------------------------
// 指定 URL に GET リクエストを送り結果をログ出力する
// -----------------------------------------------------------
async function fetchEndpoint(url) {
  try {
    const res = await fetch(url);
    const body = await res.text();
    console.log(`[loadgen] ${res.status} GET ${url} => ${body}`);
  } catch (err) {
    // 接続エラーはスタックトレースなしで出力する（起動直後の一時的な失敗を含む）
    console.error(`[loadgen] ERROR GET ${url} => ${err.message}`);
  }
}

// -----------------------------------------------------------
// メインループ: 3秒間隔でエンドポイントを順番にリクエストする
// -----------------------------------------------------------
async function main() {
  // アプリの起動完了を待つために 5 秒間待機する
  console.log('[loadgen] Waiting 5 seconds for app to start...');
  await sleep(5000);
  console.log('[loadgen] Starting load generation...');

  // 無限ループでリクエストを繰り返す
  while (true) {
    // エンドポイントをランダムな順番にシャッフルしてからリクエストする
    const shuffled = shuffle(ENDPOINTS);
    for (const path of shuffled) {
      await fetchEndpoint(`${BASE_URL}${path}`);
    }
    // 次のサイクルまで 3 秒待機する
    await sleep(3000);
  }
}

main().catch((err) => {
  console.error('[loadgen] Fatal error:', err);
  process.exit(1);
});
