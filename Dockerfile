# syntax=docker/dockerfile:1
#
# HSM2Med – Webanwendung (PHP 8.5 + Apache)
# Der Build benoetigt nur die lokal vorhandenen Basis-Images. Zur Laufzeit
# wird keinerlei Internetzugriff benoetigt.
FROM php:8.5-apache

# pdo_mysql wird aus den im Image enthaltenen PHP-Quellen kompiliert (kein Download).
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && a2enmod headers \
    && a2dissite 000-default \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /var/www/html/*

COPY docker/php/hsm2med.ini "$PHP_INI_DIR/conf.d/50-hsm2med.ini"
COPY docker/apache/hsm2med.conf /etc/apache2/sites-available/hsm2med.conf
COPY docker/apache/security.conf /etc/apache2/conf-available/hsm2med-security.conf
RUN a2ensite hsm2med && a2enconf hsm2med-security

WORKDIR /var/www/html
COPY --chown=root:root . /var/www/html
RUN sed -i 's/\r$//' /var/www/html/docker/entrypoint.sh \
    && chmod 0755 /var/www/html/docker/entrypoint.sh \
    && find /var/www/html -type d -exec chmod 0755 {} + \
    && find /var/www/html -type f ! -name entrypoint.sh -exec chmod 0644 {} + \
    && mkdir -p /var/www/storage /data/imports \
    && chown -R www-data:www-data /var/www/storage /data/imports

ENV APP_DATA_DIR=/var/www/storage \
    IMPORT_DATA_DIR=/data/imports

VOLUME ["/var/www/storage", "/data/imports"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health || exit 1

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
