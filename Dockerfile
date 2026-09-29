# Telegram Chat Stats — контейнер для запуска без установки зависимостей на хосте.
#
# Использование:
#   docker build -t chatstats .
#   docker run --rm \
#     -v /путь/к/экспорту:/export:ro \
#     -v $(pwd)/var/html:/app/var/html \
#     chatstats generate /export --key=my-chat
#
# Внутри контейнера код лежит в /app; папка экспорта монтируется в /export
# (read-only), результат и кэши — в /app/var (смонтируйте на хост, если нужны).

# Рантайм: официальный PHP CLI 8.4 (совпадает с требованием composer.json).
FROM php:8.4-cli

# Composer (phar) из официального образа composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Расширения и утилиты: mbstring обязателен (composer.json), unzip нужен
# для распаковки дистрибутивов зависимостей при сборке.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mbstring

WORKDIR /app

# Сначала копируем только манифесты — слой с зависимостями переиспользуется
# при изменении исходников (docker layer caching).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

# Исходники проекта (остальное исключено в .dockerignore).
COPY . .

# Точка входа — сам CLI: docker run chatstats generate <папка> [опции].
ENTRYPOINT ["php", "/app/bin/chatstats"]
CMD ["list"]