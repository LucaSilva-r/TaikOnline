FROM alpine:3.20

RUN apk add --no-cache dnsmasq

COPY start.sh /usr/local/bin/start-taiko-dns
RUN chmod +x /usr/local/bin/start-taiko-dns

EXPOSE 53/tcp 53/udp
ENTRYPOINT ["start-taiko-dns"]
