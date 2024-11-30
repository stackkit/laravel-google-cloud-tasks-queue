FROM serversideup/php:8.4-fpm

USER root
RUN install-php-extensions bcmath

USER www-data