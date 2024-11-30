FROM serversideup/php:8.3-fpm

USER ROOT
RUN install-php-extensions bcmath

USER www-data