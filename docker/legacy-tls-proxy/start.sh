#!/usr/bin/env bash
set -euo pipefail

# Optional legacy cert.pfx still extracted to /tmp for ports that don't have
# explicit CERT_<port>_CHAIN/KEY env vars set. Skipped silently if absent.
: "${TAIKO_GREEN_CERT_PFX:=/certificates/cert.pfx}"
: "${TAIKO_GREEN_CERT_PASSWORD:=}"

if [[ -f "${TAIKO_GREEN_CERT_PFX}" ]]; then
    openssl pkcs12 -in "${TAIKO_GREEN_CERT_PFX}" -nodes -passin "pass:${TAIKO_GREEN_CERT_PASSWORD}" -out /tmp/taiko-cert.pem
    awk 'BEGIN{c=0} /BEGIN CERTIFICATE/{c=1} c{print} /END CERTIFICATE/{exit}' /tmp/taiko-cert.pem > /tmp/taiko-cert.crt
    awk 'BEGIN{c=0} /BEGIN.*PRIVATE KEY/{c=1} c{print} /END.*PRIVATE KEY/{exit}' /tmp/taiko-cert.pem > /tmp/taiko-cert.key
    rm /tmp/taiko-cert.pem
fi

exec /usr/local/bin/legacy-tls-proxy
