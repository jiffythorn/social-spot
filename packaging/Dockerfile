# Builds the Social Spot server image: Friendica core plus our addon and skin.
#
#   docker build -f packaging/Dockerfile \
#     --build-arg CORE_REF=stable -t social-spot .
#
# The build context must be the repository root (it needs addon/ and theme/).
# Friendica core itself is NOT in this repository - it is fetched pristine from
# upstream here, the same way .github/workflows/verify.yml does, so the image
# always contains an unmodified core plus exactly our own sources.
#
# Build arguments:
#   CORE_REPOSITORY  where to fetch Friendica from
#   CORE_REF         which branch or tag to fetch (a branch or tag name, not a
#                    SHA: `git clone --branch` cannot check out a raw SHA)

FROM php:8.3-apache-bookworm

ARG CORE_REPOSITORY=https://github.com/friendica/friendica.git
ARG CORE_REF=stable

# The extension list is the union of two sources, both checked rather than guessed:
#   * CI (.github/workflows/verify.yml): what the suite was verified with;
#   * friendica/composer.json "require": what `composer install` refuses to run
#     without. redis stays out deliberately: suggested upstream, required nowhere.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip mariadb-client gosu \
        libicu-dev libcurl4-openssl-dev libxml2-dev libzip-dev \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libgmp-dev libmagickwand-dev; \
    curl -sSLf https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
        -o /usr/local/bin/install-php-extensions; \
    chmod +x /usr/local/bin/install-php-extensions; \
    install-php-extensions \
        apcu bcmath ctype curl dom exif fileinfo gd gmp iconv imagick intl \
        mbstring mysqli opcache openssl pdo_mysql posix simplexml xml zip; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/friendica
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf

# Same checkout layout the workflow uses: shallow, single ref, then prove it is core.
RUN set -eux; \
    git clone --depth 1 --branch "$CORE_REF" "$CORE_REPOSITORY" /var/www/friendica; \
    test -f /var/www/friendica/index.php

WORKDIR /var/www/friendica

# Our sources, copied in (not symlinked: the image must be self-contained, and the
# development symlinks from tools/dev/link-into-core.sh do not exist at build time).
# This runs BEFORE composer install because core's composer.json classmaps addon/,
# and the optimized autoloader fails when that directory is absent.
COPY addon/orgsuite/ /var/www/friendica/addon/orgsuite/
COPY theme/orgtool/ /var/www/friendica/view/theme/orgtool/
RUN test -f /var/www/friendica/addon/orgsuite/orgsuite.php \
 && test -f /var/www/friendica/view/theme/orgtool/theme.php

# Upstream ignores /vendor, so a pristine checkout cannot run without this.
# composer.lock is tracked upstream, so this resolves to fixed versions.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN set -eux; \
    composer install -o --no-dev --no-interaction --no-progress; \
    test -f /var/www/friendica/vendor/autoload.php

# Friendica funnels every request through index.php with the route in `pagename`
# (see tools/dev/router.php, which emulates exactly this). Apache needs the same
# rewrite or every page but the home page 404s.
COPY packaging/assets/htaccess /var/www/friendica/.htaccess

COPY packaging/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
 && chown -R www-data:www-data /var/www/friendica

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
