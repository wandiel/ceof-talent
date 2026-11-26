# syntax=docker/dockerfile:1.10
# =============================================================================
# CEOF TALENT — imagem do backend Laravel (serviços app, queue e scheduler)
#
# Base: Ubuntu 24.04 LTS (noble) com PHP 8.3 dos repositórios oficiais Ubuntu.
# Motivo: PHP 8.3 com correções de segurança mantidas pela Canonical até 2029,
# extensões pré-compiladas (sem compilar no build) e builds reprodutíveis.
#
# Targets:
#   dev  -> código montado via bind mount, Composer disponível, UID remapeável
#   prod -> código e dependências embutidos, sem Composer, otimizado
# =============================================================================

ARG UBUNTU_IMAGE=ubuntu:noble-20261110.1
ARG COMPOSER_IMAGE=composer:2.9.8

FROM ${COMPOSER_IMAGE} AS composer

# -----------------------------------------------------------------------------
# base: runtime PHP-FPM comum a todos os targets
# -----------------------------------------------------------------------------
FROM ${UBUNTU_IMAGE} AS base

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=America/Manaus \
    PHP_VERSION=8.3 \
    APP_DIR=/var/www/html \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_FPM_MAX_CHILDREN=20

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates tzdata curl unzip file procps openssl \
        libfcgi-bin \
        php8.3-fpm php8.3-cli php8.3-common \
        php8.3-pgsql php8.3-redis \
        php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
        php8.3-intl php8.3-bcmath php8.3-gd php8.3-opcache php8.3-readline \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/* /etc/php/8.3/fpm/pool.d/*

COPY docker/php/conf/php.ini        /etc/php/8.3/mods-available/ceof-talent.ini
COPY docker/php/conf/php-fpm.conf   /etc/php/8.3/fpm/php-fpm.conf
COPY docker/php/conf/www.conf       /etc/php/8.3/fpm/pool.d/www.conf
COPY docker/php/entrypoint.sh       /usr/local/bin/ceof-entrypoint
COPY docker/php/healthcheck.sh      /usr/local/bin/ceof-healthcheck

RUN phpenmod -v 8.3 -s ALL ceof-talent \
    && chmod 0755 /usr/local/bin/ceof-entrypoint /usr/local/bin/ceof-healthcheck \
    && mkdir -p ${APP_DIR} \
    && chown www-data:www-data ${APP_DIR}

WORKDIR ${APP_DIR}

EXPOSE 9000
STOPSIGNAL SIGQUIT
ENTRYPOINT ["ceof-entrypoint"]
CMD ["app"]

# -----------------------------------------------------------------------------
# dev: desenvolvimento local, código montado em /var/www/html
# -----------------------------------------------------------------------------
FROM base AS dev

# Alinha o usuário www-data ao UID/GID do desenvolvedor para que arquivos
# criados no bind mount (storage, vendor) pertençam a ele no host.
ARG UID=1000
ARG GID=1000

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git php8.3-sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && if getent passwd ${UID} >/dev/null && [ "$(getent passwd ${UID} | cut -d: -f1)" != "www-data" ]; then \
           userdel -r "$(getent passwd ${UID} | cut -d: -f1)"; \
       fi \
    && if getent group ${GID} >/dev/null && [ "$(getent group ${GID} | cut -d: -f1)" != "www-data" ]; then \
           groupdel "$(getent group ${GID} | cut -d: -f1)"; \
       fi \
    && groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} -d ${APP_DIR} www-data \
    && mkdir -p /tmp/composer && chown www-data:www-data /tmp/composer

ENV APP_ENV=local \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    COMPOSER_HOME=/tmp/composer

USER www-data

# -----------------------------------------------------------------------------
# vendor: instala dependências de produção (estágio descartável)
# -----------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

COPY backend/composer.json backend/composer.lock ./
# COMPOSER_AUTH opcional via build secret (pacotes privados / limite da API do GitHub):
#   COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}' docker compose build
# O segredo não fica gravado em nenhuma camada da imagem.
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH,required=false \
    --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY backend/ ./
RUN rm -rf storage/logs/* bootstrap/cache/*.php .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi

# -----------------------------------------------------------------------------
# prod: imagem final, sem Composer, código somente leitura
# -----------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

# O código pertence ao root (somente leitura para o processo PHP);
# apenas storage/ e bootstrap/cache/ são graváveis por www-data.
COPY --from=vendor ${APP_DIR} ${APP_DIR}
RUN chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 0775 {} +

USER www-data
