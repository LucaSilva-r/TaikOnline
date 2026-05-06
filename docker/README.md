# Taiko Green Network Services

The cabinet needs Bandai hostnames to resolve to this server and uses a TLS 1.0 profile that modern web servers usually reject.

## DNS

Set `TAIKO_GREEN_DNS_BIND` and `TAIKO_GREEN_DNS_ADDRESS` in `.env` to the LAN IP of this machine, then point the cabinet/RPCS3 DNS server to this machine.

Mapped hostnames:

- `mobirouter.loc`
- `tenporouter.loc`
- `bbrouter.loc`
- `dslrouter.loc`
- `naominet.jp`
- `v402-front.mucha-prd.nbgi-amnet.jp`
- `vsapi.taiko-p.jp`

## Legacy TLS

`legacy-tls-proxy` listens on the cabinet TLS ports, terminates TLS 1.0 with `certificates/cert.pfx`, and forwards decrypted HTTP to Laravel.

Ports:

- `10122`
- `54430`
- `54431`
- `57402`
- `443`
