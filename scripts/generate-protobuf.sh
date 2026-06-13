#!/usr/bin/env bash
set -euo pipefail

# Regenerates the per-version protobuf PHP classes for every Taiko game version.
#
# Each version's source protos live in protobuf/<value>/{taiko,vsinterface}.proto
# and are extracted verbatim from the game EBOOT (no package / no php options) via
# namco357-dongle/scripts/extract_embedded_protos.py, e.g.:
#
#   python3 scripts/extract_embedded_protos.py "original elf/EBOOT RED.elf" \
#       -o /path/to/TaikOnline/protobuf/red
#
# Because every version declares the same message names (HeartBeatResponse, ...),
# we inject a DISTINCT package per version (taiko.<value> / vsinterface.<value>)
# so the protobuf descriptor pool gets unique fully-qualified symbols and the 9
# dialects can coexist in one process. php_namespace puts each version in its own
# App\GameProtocol\Proto\<Studly>\{Taiko,VsInterface} namespace.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTOC="$ROOT/tools/protoc/bin/protoc"

# enum value => StudlyCase namespace segment
VERSIONS=(sorairo momoiro kimidori murasaki white red yellow blue green)
declare -A STUDLY=(
    [sorairo]=Sorairo [momoiro]=Momoiro [kimidori]=Kimidori [murasaki]=Murasaki
    [white]=White [red]=Red [yellow]=Yellow [blue]=Blue [green]=Green
)

if [[ ! -x "$PROTOC" ]]; then
    mkdir -p "$ROOT/tools/protoc"
    curl -L -o /tmp/taikonline-protoc.zip \
        https://github.com/protocolbuffers/protobuf/releases/download/v34.1/protoc-34.1-linux-x86_64.zip
    unzip -o /tmp/taikonline-protoc.zip -d "$ROOT/tools/protoc"
fi

TMP="$ROOT/storage/framework/protobuf-generated"
rm -rf "$ROOT/app/GameProtocol/Proto" "$TMP"
mkdir -p "$TMP/out"

# Injects "package <pkg>;" plus php namespace options just after the syntax line.
annotate_proto() {
    local src="$1" dst="$2" pkg="$3" php_ns="$4" meta_ns="$5"
    # protobuf string literals require escaped backslashes (App\\GameProtocol\\...)
    php_ns="${php_ns//\\/\\\\}"
    meta_ns="${meta_ns//\\/\\\\}"
    {
        printf 'syntax = "proto2";\n'
        printf 'package %s;\n' "$pkg"
        printf 'option php_namespace = "%s";\n' "$php_ns"
        printf 'option php_metadata_namespace = "%s";\n' "$meta_ns"
        # Google's PHP generator rejects proto2 "required" fields. Downgrade them
        # to "optional"; the wire format (field tags) is unchanged.
        tail -n +2 "$src" | sed -E 's/^(\s*)required /\1optional /'
    } >"$dst"
}

for version in "${VERSIONS[@]}"; do
    studly="${STUDLY[$version]}"
    srcdir="$ROOT/protobuf/$version"

    if [[ ! -f "$srcdir/taiko.proto" || ! -f "$srcdir/vsinterface.proto" ]]; then
        echo "skip $version: missing protos in $srcdir" >&2
        continue
    fi

    work="$TMP/src/$version"
    mkdir -p "$work"

    annotate_proto "$srcdir/taiko.proto" "$work/taiko_$version.proto" \
        "taiko.$version" \
        "App\\GameProtocol\\Proto\\$studly\\Taiko" \
        "App\\GameProtocol\\Proto\\$studly\\Metadata"

    annotate_proto "$srcdir/vsinterface.proto" "$work/vsinterface_$version.proto" \
        "vsinterface.$version" \
        "App\\GameProtocol\\Proto\\$studly\\VsInterface" \
        "App\\GameProtocol\\Proto\\$studly\\Metadata"

    "$PROTOC" --proto_path="$work" --php_out="$TMP/out" \
        "$work/taiko_$version.proto" "$work/vsinterface_$version.proto"

    echo "generated $version -> App\\GameProtocol\\Proto\\$studly"
done

mkdir -p "$ROOT/app/GameProtocol"
mv "$TMP/out/App/GameProtocol/Proto" "$ROOT/app/GameProtocol/"
rm -rf "$TMP"


if [[ -x "$ROOT/vendor/bin/pint" ]]; then
    "$ROOT/vendor/bin/pint" "$ROOT/app/GameProtocol/Proto" --format agent >/dev/null
fi
