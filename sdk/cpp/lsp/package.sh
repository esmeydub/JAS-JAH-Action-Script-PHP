#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
root=$(CDPATH= cd -- "$script_dir/../../.." && pwd)
output=${1:-"$root/dist"}
version=$(tr -d '\r\n' < "$root/VERSION")
case "$version" in ''|*[!0-9A-Za-z._-]*) printf '%s\n' 'invalid JAS version' >&2; exit 2;; esac

machine=$(uname -m)
case "$machine" in
    x86_64) architecture=x86_64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) printf 'unsupported package architecture: %s\n' "$machine" >&2; exit 2 ;;
esac

revision=${JAS_SOURCE_REVISION:-}
if test -z "$revision"; then
    revision=$(git -C "$root" rev-parse HEAD 2>/dev/null || printf '%s' unknown)
fi
case "$revision" in *[!0-9A-Za-z._-]*) printf '%s\n' 'invalid source revision' >&2; exit 2;; esac
epoch=${SOURCE_DATE_EPOCH:-}
if test -z "$epoch"; then
    epoch=$(git -C "$root" log -1 --format=%ct 2>/dev/null || printf '%s' 0)
fi
case "$epoch" in ''|*[!0-9]*) printf '%s\n' 'invalid SOURCE_DATE_EPOCH' >&2; exit 2;; esac
created=$(date -u -d "@$epoch" '+%Y-%m-%dT%H:%M:%SZ')

package="jas-lsp-${version}-linux-${architecture}"
stage=$(mktemp -d /tmp/jas_lsp_package.XXXXXX)
trap 'rm -rf "$stage"' EXIT HUP INT TERM
tree="$stage/$package"
mkdir -p "$tree/bin"

cxx=${CXX:-g++}
"$cxx" -std=c++20 -O2 -Wall -Wextra -Wpedantic -Werror \
    -ffile-prefix-map="$root"=. -fdebug-prefix-map="$root"=. \
    -Wl,--build-id=none -static "$script_dir/jas_lsp_bridge.cpp" \
    -o "$tree/bin/jas-lsp-bridge" -lcrypto -lzstd -lz -ldl -pthread
strip --strip-all "$tree/bin/jas-lsp-bridge"
install -m 0755 "$script_dir/jas-lsp" "$tree/bin/jas-lsp"
install -m 0755 "$script_dir/install.sh" "$tree/install.sh"
install -m 0644 "$root/LICENSE" "$tree/LICENSE"
install -m 0644 "$root/docs/JAS_LSP_DISTRIBUTION.md" "$tree/README.md"
printf '%s\n' "$version" > "$tree/VERSION"

binary_hash=$(sha256sum "$tree/bin/jas-lsp-bridge" | awk '{print $1}')
launcher_hash=$(sha256sum "$tree/bin/jas-lsp" | awk '{print $1}')
license_hash=$(sha256sum "$tree/LICENSE" | awk '{print $1}')
cat > "$tree/JAS-LSP.spdx" <<EOF
SPDXVersion: SPDX-2.3
DataLicense: CC0-1.0
SPDXID: SPDXRef-DOCUMENT
DocumentName: JAS-LSP-${version}-linux-${architecture}
DocumentNamespace: https://github.com/esmeydub/JAS-JAH-Action-Script-PHP/lsp/${version}/${revision}/${architecture}
Creator: Organization: JAS - JAH Action Script PHP
Created: ${created}

PackageName: JAS LSP external compatibility bridge
SPDXID: SPDXRef-Package-JAS-LSP
PackageVersion: ${version}
PackageSupplier: Organization: JAS - JAH Action Script PHP
PackageDownloadLocation: NOASSERTION
FilesAnalyzed: true
PackageLicenseConcluded: MIT
PackageLicenseDeclared: MIT
PackageCopyrightText: Copyright (c) 2026 JAS - JAH Action Script PHP

FileName: ./bin/jas-lsp-bridge
SPDXID: SPDXRef-File-Bridge
FileChecksum: SHA256: ${binary_hash}
LicenseConcluded: MIT
LicenseInfoInFile: MIT

FileName: ./bin/jas-lsp
SPDXID: SPDXRef-File-Launcher
FileChecksum: SHA256: ${launcher_hash}
LicenseConcluded: MIT
LicenseInfoInFile: MIT

FileName: ./LICENSE
SPDXID: SPDXRef-File-License
FileChecksum: SHA256: ${license_hash}
LicenseConcluded: MIT
LicenseInfoInFile: MIT

Relationship: SPDXRef-DOCUMENT DESCRIBES SPDXRef-Package-JAS-LSP
Relationship: SPDXRef-Package-JAS-LSP CONTAINS SPDXRef-File-Bridge
Relationship: SPDXRef-Package-JAS-LSP CONTAINS SPDXRef-File-Launcher
Relationship: SPDXRef-Package-JAS-LSP CONTAINS SPDXRef-File-License
EOF

compiler=$($cxx --version | sed -n '1p')
cat > "$tree/PROVENANCE.jahp" <<EOF
schema=JAS_LSP_PROVENANCE_1
project=JAS-JAH-Action-Script-PHP
version=${version}
platform=linux
architecture=${architecture}
source_revision=${revision}
source_date_epoch=${epoch}
compiler=${compiler}
build_profile=static-cxx20-openssl3
binary_sha256=${binary_hash}
core_protocol=JASB-JASL
json_boundary=external-bridge-only
EOF

find "$tree" -exec touch -h -d "@$epoch" {} +
mkdir -p "$output"
archive="$output/$package.tar.gz"
tar --sort=name --format=ustar --owner=0 --group=0 --numeric-owner \
    --mtime="@$epoch" -C "$stage" -cf - "$package" | gzip -n > "$archive"
(CDPATH= cd -- "$output" && sha256sum "$(basename -- "$archive")" > "$(basename -- "$archive").sha256")

if test -n "${JAS_LSP_SIGNING_KEY:-}"; then
    test -f "$JAS_LSP_SIGNING_KEY" && test ! -L "$JAS_LSP_SIGNING_KEY" || {
        printf '%s\n' 'signing key must be a regular non-symlink file' >&2
        exit 2
    }
    test "$(stat -c %u "$JAS_LSP_SIGNING_KEY")" -eq "$(id -u)" || {
        printf '%s\n' 'signing key must belong to the current user' >&2
        exit 2
    }
    permissions=$(stat -c %a "$JAS_LSP_SIGNING_KEY")
    case "$permissions" in *[1-7][0-7]|*[0-7][1-7]) printf '%s\n' 'signing key permissions are too broad' >&2; exit 2;; esac
    openssl pkey -in "$JAS_LSP_SIGNING_KEY" -pubout -out "$archive.pem" >/dev/null 2>&1
    openssl pkeyutl -sign -rawin -inkey "$JAS_LSP_SIGNING_KEY" -in "$archive" -out "$archive.sig"
    openssl pkeyutl -verify -rawin -pubin -inkey "$archive.pem" -in "$archive" -sigfile "$archive.sig" >/dev/null
fi

printf '%s\n' "$archive"
