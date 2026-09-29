# PHP of the test environment of the bundle: the extensions its dependencies need (gd and zip for PhpSpreadsheet, intl for the ICU translations) and the drivers of the databases
# it is tested on, SQLite being built in
FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd intl pdo_mysql zip

WORKDIR /app
