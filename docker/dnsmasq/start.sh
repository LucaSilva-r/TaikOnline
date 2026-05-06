#!/usr/bin/env sh
set -eu

: "${TAIKO_GREEN_DNS_ADDRESS:=127.0.0.1}"
: "${TAIKO_GREEN_DNS_UPSTREAM:=1.1.1.1}"

cat > /etc/dnsmasq.conf <<EOF
no-daemon
log-queries
log-facility=-
server=${TAIKO_GREEN_DNS_UPSTREAM}
address=/mobirouter.loc/${TAIKO_GREEN_DNS_ADDRESS}
address=/tenporouter.loc/${TAIKO_GREEN_DNS_ADDRESS}
address=/bbrouter.loc/${TAIKO_GREEN_DNS_ADDRESS}
address=/dslrouter.loc/${TAIKO_GREEN_DNS_ADDRESS}
address=/naominet.jp/${TAIKO_GREEN_DNS_ADDRESS}
address=/v402-front.mucha-prd.nbgi-amnet.jp/${TAIKO_GREEN_DNS_ADDRESS}
address=/vsapi.taiko-p.jp/${TAIKO_GREEN_DNS_ADDRESS}
EOF

exec dnsmasq --conf-file=/etc/dnsmasq.conf
