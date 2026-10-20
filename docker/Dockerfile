ARG BASE_IMAGE=php:8.4-cli-alpine
FROM ${BASE_IMAGE}
WORKDIR /opt/kit
COPY bootstrap.php /opt/kit/bootstrap.php
COPY src /opt/kit/src
COPY bin/invoke.php bin/runtime-server.php /opt/kit/bin/
COPY --chown=10001:10001 package /package
USER 10001:10001
EXPOSE 8080
ENTRYPOINT ["php", "-d", "display_errors=0", "-d", "log_errors=0", "-S", "0.0.0.0:8080", "/opt/kit/bin/runtime-server.php"]
