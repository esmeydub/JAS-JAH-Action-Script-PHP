#!/bin/sh
set -eu

test "$#" -ge 1 && test "$#" -le 3 || {
    printf '%s\n' 'usage: verify-package.sh archive [signature public-key]' >&2
    exit 2
}
archive=$1
test -f "$archive" || { printf '%s\n' 'package archive missing' >&2; exit 2; }
archive_name=$(basename -- "$archive")
test -f "$archive.sha256" || { printf '%s\n' 'package checksum missing' >&2; exit 2; }
recorded_checksum=$(cat "$archive.sha256")
actual_checksum=$(sha256sum "$archive" | awk '{print $1}')
test "$recorded_checksum" = "$actual_checksum  $archive_name" || {
    printf '%s\n' 'package checksum mismatch' >&2
    exit 1
}
printf '%s: OK\n' "$archive_name"

if test "$#" -ne 1; then
    test "$#" -eq 3 || { printf '%s\n' 'signature and public key must be provided together' >&2; exit 2; }
    openssl pkeyutl -verify -rawin -pubin -inkey "$3" -in "$archive" -sigfile "$2" >/dev/null
elif test -e "$archive.sig" || test -e "$archive.pem"; then
    printf '%s\n' 'signed package requires explicit signature and public key verification' >&2
    exit 2
fi

listing=$(mktemp /tmp/jas_lsp_listing.XXXXXX)
expected=$(mktemp /tmp/jas_lsp_expected.XXXXXX)
extract=$(mktemp -d /tmp/jas_lsp_verify.XXXXXX)
trap 'rm -rf "$listing" "$listing.sorted" "$expected" "$extract"' EXIT HUP INT TERM
tar -tzf "$archive" > "$listing"
package_name=${archive_name%.tar.gz}
case "$package_name" in ''|*[!0-9A-Za-z._-]*) printf '%s\n' 'unsafe package name' >&2; exit 1;; esac
printf '%s\n' \
    "$package_name/" \
    "$package_name/JAS-LSP.spdx" \
    "$package_name/LICENSE" \
    "$package_name/PROVENANCE.jahp" \
    "$package_name/README.md" \
    "$package_name/VERSION" \
    "$package_name/bin/" \
    "$package_name/bin/jas-lsp" \
    "$package_name/bin/jas-lsp-bridge" \
    "$package_name/install.sh" | LC_ALL=C sort > "$expected"
LC_ALL=C sort "$listing" > "$listing.sorted"
cmp "$expected" "$listing.sorted" || { printf '%s\n' 'unexpected package manifest' >&2; exit 1; }
tar -xzf "$archive" -C "$extract"
package_root="$extract/$package_name"
test -d "$package_root"
test -x "$package_root/bin/jas-lsp-bridge"
test -x "$package_root/bin/jas-lsp"
test -x "$package_root/install.sh"
test -s "$package_root/JAS-LSP.spdx"
test -s "$package_root/PROVENANCE.jahp"
file "$package_root/bin/jas-lsp-bridge" | grep -q 'statically linked'
set +e
"$package_root/bin/jas-lsp-bridge" > /dev/null 2> "$extract/usage"
status=$?
set -e
test "$status" -eq 2
grep -q '^usage: jas-lsp-bridge ' "$extract/usage"
printf '%s\n' 'JAS LSP DISTRIBUTION PACKAGE: PASS'
