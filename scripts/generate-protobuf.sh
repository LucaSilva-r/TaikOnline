#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTOC="$ROOT/tools/protoc/bin/protoc"

if [[ ! -x "$PROTOC" ]]; then
    mkdir -p "$ROOT/tools/protoc"
    curl -L -o /tmp/taikonline-protoc.zip \
        https://github.com/protocolbuffers/protobuf/releases/download/v34.1/protoc-34.1-linux-x86_64.zip
    unzip -o /tmp/taikonline-protoc.zip -d "$ROOT/tools/protoc"
fi

TMP="$ROOT/storage/framework/protobuf-generated"
rm -rf "$ROOT/app/GameProtocol/Green/Proto" "$TMP"
mkdir -p "$TMP"
"$PROTOC" --proto_path="$ROOT/protobuf" --php_out="$TMP" \
    "$ROOT/protobuf/taiko.proto" \
    "$ROOT/protobuf/vsinterface.proto"

mkdir -p "$ROOT/app/GameProtocol"
mv "$TMP/App/GameProtocol/Green/Proto" "$ROOT/app/GameProtocol/Green/"
rm -rf "$TMP"

if [[ -x "$ROOT/vendor/bin/pint" ]]; then
    "$ROOT/vendor/bin/pint" "$ROOT/app/GameProtocol/Green/Proto" --format agent >/dev/null
fi
