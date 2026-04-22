# otel-only: 純粋 OTel パターン用 Dockerfile
FROM node:20-alpine

# 作業ディレクトリを設定する
WORKDIR /app

# 依存関係ファイルを先にコピーしてキャッシュを活用する
COPY package.json ./
RUN npm install --omit=dev

# アプリケーションのソースコードをコピーする
COPY tracing.js ./
COPY app.js ./

# ポート 3000 を公開する
EXPOSE 3000

# tracing.js を -r オプションで事前ロードしてから app.js を起動する
# これにより SDK が Express より先に初期化される
CMD ["node", "-r", "./tracing.js", "app.js"]
