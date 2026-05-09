#!/usr/bin/env bash
# Generate self-signed CA + leaf cert pairs that match the slots embedded in
# Taiko Green EBOOT.elf (.data section). Once these CAs are baked into a
# patched EBOOT via scripts/patch_eboot_usb_probe.py from the namco357-dongle
# repo, the legacy-tls-proxy can present the matching leaf certs and the
# cabinet will trust the handshake without needing Sega's real keys.
#
# Slots (see namco357-dongle/docs/mucha_update_path.md §6g.0):
#   mucha       0x010f2298  CA   1656 bytes max  (NAMCO BANDAI Mucha root)
#               0x010f1e70  leaf 1064 bytes max  (*.mucha-prd.nbgi-amnet.jp)
#   vsapi       0x010f2910  CA   1280 bytes max  (vsapi.taiko-p.jp)
#               0x010f2910  leaf reuses self-signed CA
#   donder     0x010f2e10  CA   ~?    bytes max (vsapi.donderhiroba.jp)
#               (legacy chain, kept for completeness)
#
# Each cert uses 1024-bit RSA with the same field set as the original embedded
# certs, which keeps the PEM body small enough to fit in the reserved slot.
# The patcher refuses replacements that exceed the slot length, so do not
# bump these to RSA-2048 without first confirming the slot reservation can
# absorb the larger PEM.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${REPO_ROOT}/certificates/generated"
DAYS=7000

usage() {
    cat <<USAGE
Usage: $0 [--out-dir DIR] [--days N]

Generates open-source CA + leaf cert pairs into:
  ${OUT_DIR}/donderhiroba/  ca.{pem,key}  leaf.{pem,key}
  ${OUT_DIR}/mucha/         ca.{pem,key}  leaf.{pem,key}
  ${OUT_DIR}/vsapi/         ca.{pem,key}  leaf.{pem,key}
  ${OUT_DIR}/donder/        ca.{pem,key}  leaf.{pem,key}

Defaults: out-dir=${OUT_DIR} days=${DAYS}

Idempotent: skips regeneration if all expected files already exist.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --out-dir) OUT_DIR="$2"; shift 2 ;;
        --days)    DAYS="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown arg: $1" >&2; usage; exit 1 ;;
    esac
done

# Generate one CA + one leaf signed by it.
#
# Args:
#   $1  slot-name (used for output subdir)
#   $2  CA subject (one-line OpenSSL "/" form)
#   $3  leaf subject
#   $4  leaf SAN list (DNS:..,DNS:..)
generate_pair() {
    local name="$1"
    local ca_subj="$2"
    local leaf_subj="$3"
    local leaf_san="$4"
    local dir="${OUT_DIR}/${name}"

    if [[ -f "${dir}/ca.pem" && -f "${dir}/ca.key" \
        && -f "${dir}/leaf.pem" && -f "${dir}/leaf.key" ]]; then
        echo "[skip] ${name}: all files already exist in ${dir}"
        return
    fi

    mkdir -p "${dir}"

    # CA
    openssl genrsa -out "${dir}/ca.key" 1024 2>/dev/null
    openssl req -new -x509 -key "${dir}/ca.key" \
        -days "${DAYS}" -sha256 \
        -subj "${ca_subj}" \
        -out "${dir}/ca.pem"

    # Leaf
    openssl genrsa -out "${dir}/leaf.key" 1024 2>/dev/null

    local cnf="${dir}/leaf.cnf"
    cat > "${cnf}" <<EOF
[req]
distinguished_name = dn
prompt = no
[dn]
EOF
    # convert "/X=Y/A=B" subject into key=value lines for OpenSSL config
    awk -v subj="${leaf_subj}" 'BEGIN {
        n = split(substr(subj, 2), parts, "/");
        for (i = 1; i <= n; i++) print parts[i];
    }' >> "${cnf}"
    cat >> "${cnf}" <<EOF
[v3_req]
subjectAltName = ${leaf_san}
EOF

    openssl req -new -key "${dir}/leaf.key" \
        -config "${cnf}" \
        -out "${dir}/leaf.csr"
    openssl x509 -req -in "${dir}/leaf.csr" \
        -CA "${dir}/ca.pem" -CAkey "${dir}/ca.key" -CAcreateserial \
        -days "${DAYS}" -sha256 \
        -extfile "${cnf}" -extensions v3_req \
        -out "${dir}/leaf.pem"

    rm -f "${dir}/leaf.csr" "${cnf}" "${dir}/ca.srl"

    # Chain file the TLS proxy will load: leaf followed by issuing CA.
    cat "${dir}/leaf.pem" "${dir}/ca.pem" > "${dir}/chain.pem"

    local ca_size leaf_size
    ca_size=$(wc -c < "${dir}/ca.pem")
    leaf_size=$(wc -c < "${dir}/leaf.pem")
    echo "[ok]   ${name}: ca=${ca_size}b leaf=${leaf_size}b -> ${dir}"
}

# donderhiroba.jp: replaces slot 0x010f1a18. The original self-signed CA
# expired in 2021, so cabinets booting today already had to skip validation.
# Replacing it lets the network-authentication enforcement be left on.
generate_pair "donderhiroba" \
    "/C=JP/ST=Tokyo/L=Shinagawa/O=TaikOnline/CN=donderhiroba.jp" \
    "/C=JP/ST=Tokyo/O=TaikOnline/CN=donderhiroba.jp" \
    "DNS:donderhiroba.jp,DNS:*.donderhiroba.jp"

# Mucha: CA replaces 0x010f2298 (1656 bytes max), leaf replaces 0x010f1e70
# (1064 bytes max). DNS SANs match the cabinet's hardcoded hostname plus the
# wildcard the original cert used.
generate_pair "mucha" \
    "/C=JP/ST=Tokyo/L=Shinagawa-ku/O=TaikOnline/CN=dhcp01.prv.mucha-prd.nbgi-amnet.jp" \
    "/C=JP/CN=*.mucha-prd.nbgi-amnet.jp" \
    "DNS:*.mucha-prd.nbgi-amnet.jp"

# vsapi.taiko-p.jp: replaces slot 0x010f2910 (1280 bytes max). Original is a
# self-signed CA with the leaf == CA, but to keep the proxy uniform we still
# emit a separate leaf signed by the CA.
generate_pair "vsapi" \
    "/C=JP/ST=Kanagawa/L=Kawasaki/O=TaikOnline/CN=vsapi.taiko-p.jp" \
    "/C=JP/ST=Kanagawa/O=TaikOnline/CN=vsapi.taiko-p.jp" \
    "DNS:vsapi.taiko-p.jp"

# vsapi.donderhiroba.jp: replaces slot 0x010f2e10. Optional, useful only if
# the donderhiroba network path is exercised.
generate_pair "donder" \
    "/C=JP/ST=Tokyo/L=Shinagawa/O=TaikOnline/CN=vsapi.donderhiroba.jp" \
    "/C=JP/ST=Tokyo/O=TaikOnline/CN=vsapi.donderhiroba.jp" \
    "DNS:vsapi.donderhiroba.jp,DNS:donderhiroba.jp"

echo
echo "Done. Next steps:"
echo "  1. Patch EBOOT.elf in the namco357-dongle repo:"
echo "       scripts/patch_eboot_usb_probe.py EBOOT.elf EBOOT.muchaca.elf \\"
echo "         --replace-mucha-ca ${OUT_DIR}/mucha/ca.pem"
echo "  2. Restart legacy-tls-proxy (compose) so it loads the new chain files."
echo "  3. Add a DNS override so v402-front.mucha-prd.nbgi-amnet.jp resolves"
echo "     to the host running the proxy."
