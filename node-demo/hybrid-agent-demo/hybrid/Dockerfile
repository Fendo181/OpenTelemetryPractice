# hybrid: Hybrid Agent パターン用 Dockerfile
FROM node:20-alpine

# 作業ディレクトリを設定する
WORKDIR /app

# 依存関係ファイルを先にコピーしてキャッシュを活用する
COPY package.json ./
RUN npm install --omit=dev

# アプリケーションのソースコードをコピーする
COPY newrelic.js ./
COPY app.js ./

# ポート 3000 を公開する
EXPOSE 3000

# newrelic モジュールを -r オプションで事前ロードしてから app.js を起動する
# New Relic Agent が起動時に OTel API をブリッジするため、tracing.js は不要
CMD ["node", "-r", "newrelic", "app.js"]
