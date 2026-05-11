FROM nginx:1.29-alpine

# Stream-mode TLS terminator in front of the Laravel backend. Replaces
# the legacy gnutls-based C proxy that pinned TLS 1.0 + RSA so the
# locked-down PS3 firmware stack could complete a handshake. The SPRX
# now ships its own mbedTLS-based client (M3/M4), so the proxy only
# needs to speak modern TLS to a small set of cabinet endpoints and
# forward the decrypted byte stream to laravel-app:8080.

COPY nginx.conf /etc/nginx/nginx.conf
