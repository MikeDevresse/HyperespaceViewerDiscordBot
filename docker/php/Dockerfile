ARG VERSION

# DEV Image
FROM php:${VERSION}-fpm-alpine AS dev


# System dependencies
RUN apk add --no-cache --virtual dev-deps git; \
	set -eux; \
	apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		zlib-dev \
		libzip-dev \
		postgresql-dev \
	; \
    pecl install pcov; \
    docker-php-ext-enable pcov; \
	docker-php-ext-configure zip; \
    docker-php-ext-configure opcache  --enable-opcache; \
	docker-php-ext-install -j$(nproc) \
		pdo_pgsql \
		zip \
    	opcache \
	; \
	runDeps="$( \
		scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
			| tr ',' '\n' \
			| sort -u \
			| awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
	)"; \
	apk add --no-cache --virtual .api-phpexts-rundeps $runDeps; \
	apk del .build-deps

# Copy default.ini configuration file
COPY ./docker/php/default.ini /usr/local/etc/php/conf.d/default.ini

# Installing composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set workdir to api so that all the following commands will be executed in this dir
WORKDIR /hyperespace

# Copy dev image to test
FROM dev AS test

# Copy all files
COPY . .

# Install composer dependencies
RUN composer install --no-interaction --optimize-autoloader


FROM dev AS prod

# Copy all files
COPY . .

# Install composer dependencies without dev dependencies
RUN composer install --no-dev --no-interaction --optimize-autoloader && \
	 apk del dev-deps && \
	 rm -f /usr/local/bin/composer
