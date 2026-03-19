# PHPerKaigi 2026デモ用 - OpenTelemetry PHP APM
# PHP 8.2 + Composer + SQLite

FROM php:8.2-cli

# システムパッケージとPHP拡張のインストール
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 作業ディレクトリの設定
WORKDIR /app

# Composer依存関係のインストール（キャッシュ効率化）
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# アプリケーションコードをコピー
COPY . .

# データベースディレクトリの権限設定
RUN mkdir -p /app/database && chmod 777 /app/database

# PHP built-in serverでアプリを起動
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]

EXPOSE 8080
