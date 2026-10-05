# Contao 6 demo site with the Supertext translation bundle.
# Build context is the REPOSITORY ROOT (Railway: Dockerfile path `demo/Dockerfile`), so
# the image always runs the bundle as committed:
#
#   docker build -f demo/Dockerfile -t contao-supertext-demo .
#
# Runtime variables: DATABASE_URL (or MYSQL* / MARIADB* parts), APP_SECRET,
# SUPERTEXT_API_KEY, SUPERTEXT_ENVIRONMENT, DEMO_ADMIN_*, DEMO_EDITOR_* (see docs/DEVELOPER.md).

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip git mariadb-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd pdo_mysql opcache \
    && (a2dismod -f mpm_event mpm_worker || true) && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod

# The demo project, then the bundle from this repository as a path repository.
COPY demo/composer.json ./
# Real values come from the platform's variables; .env only sets the environment.
RUN printf 'APP_ENV=prod\n' > .env
COPY composer.json README.md LICENSE plugin/
COPY src plugin/src
COPY config plugin/config
COPY contao plugin/contao
COPY templates plugin/templates
COPY public plugin/public
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

COPY demo/docker/php.ini /usr/local/etc/php/conf.d/contao.ini
COPY demo/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/config config
COPY demo/src src
COPY demo/docker/entrypoint.sh /usr/local/bin/demo-entrypoint
RUN composer dump-autoload --no-dev --optimize \
    && composer run-script post-install-cmd \
    && chmod +x /usr/local/bin/demo-entrypoint \
    && chown -R www-data:www-data var public assets system 2>/dev/null; true

# Railway terminates TLS in a proxy in front of the container: trust its
# X-Forwarded-* headers so Contao generates https URLs without the internal port.
ENV PORT=8080 TRUSTED_PROXIES=REMOTE_ADDR
EXPOSE 8080
CMD ["demo-entrypoint"]
