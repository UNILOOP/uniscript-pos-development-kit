FROM docker:29-cli AS docker_cli
FROM php:8.4-cli-alpine
RUN apk add --no-cache libzip-dev icu-dev && docker-php-ext-install -j2 zip bcmath intl
COPY --from=docker_cli /usr/local/bin/docker /usr/local/bin/docker
WORKDIR /opt/kit
COPY bootstrap.php composer.json README.md LICENSE CONTRIBUTING.md SECURITY.md dev-kit /opt/kit/
COPY src /opt/kit/src
COPY bin /opt/kit/bin
COPY docker /opt/kit/docker
COPY examples /opt/kit/examples
COPY tests /opt/kit/tests
COPY ui /opt/kit/ui
COPY contracts /opt/kit/contracts
COPY fixtures /opt/kit/fixtures
COPY docs /opt/kit/docs
RUN printf 'memory_limit=256M\nupload_max_filesize=20M\npost_max_size=22M\ndisplay_errors=0\nlog_errors=1\n' > /usr/local/etc/php/conf.d/kit-local.ini
CMD ["php", "bin/dev-service.php", "operator"]
