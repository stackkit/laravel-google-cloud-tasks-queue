FROM serversideup/php:8.3-fpm

USER root
RUN install-php-extensions bcmath

USER www-data