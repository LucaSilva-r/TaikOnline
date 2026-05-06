FROM ubuntu:20.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential libgnutls28-dev openssl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY legacy_tls_proxy.c /tmp/legacy_tls_proxy.c
RUN gcc -O2 -Wall -Wextra -o /usr/local/bin/legacy-tls-proxy /tmp/legacy_tls_proxy.c -lgnutls -lpthread \
    && rm /tmp/legacy_tls_proxy.c

COPY start.sh /usr/local/bin/start-legacy-tls-proxy
RUN chmod +x /usr/local/bin/start-legacy-tls-proxy

ENTRYPOINT ["start-legacy-tls-proxy"]
