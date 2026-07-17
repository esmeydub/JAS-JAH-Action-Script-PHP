#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
first=$(mktemp -d /tmp/jas_lsp_dist_first.XXXXXX)
second=$(mktemp -d /tmp/jas_lsp_dist_second.XXXXXX)
install_root=$(mktemp -d /tmp/jas_lsp_install.XXXXXX)
workspace=$(mktemp -d /tmp/jas_lsp_installed_workspace.XXXXXX)
key=$(mktemp /tmp/jas_lsp_signing_key.XXXXXX.pem)
input=$(mktemp /tmp/jas_lsp_installed_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_installed_output.XXXXXX)
tampered=$(mktemp /tmp/jas_lsp_tampered.XXXXXX.tar.gz)
trap 'rm -rf "$first" "$second" "$install_root" "$workspace" "$key" "$input" "$output" "$tampered" "$tampered.sha256"' EXIT HUP INT TERM

openssl genpkey -algorithm ED25519 -out "$key" >/dev/null 2>&1
chmod 0600 "$key"
SOURCE_DATE_EPOCH=1700000000 JAS_SOURCE_REVISION=test-revision JAS_LSP_SIGNING_KEY="$key" \
    "$root/sdk/cpp/lsp/package.sh" "$first" >/dev/null
SOURCE_DATE_EPOCH=1700000000 JAS_SOURCE_REVISION=test-revision JAS_LSP_SIGNING_KEY="$key" \
    "$root/sdk/cpp/lsp/package.sh" "$second" >/dev/null

archive_first=$(find "$first" -maxdepth 1 -name '*.tar.gz' -type f)
archive_second=$(find "$second" -maxdepth 1 -name '*.tar.gz' -type f)
cmp "$archive_first" "$archive_second"
cmp "$archive_first.sha256" "$archive_second.sha256"
"$root/sdk/cpp/lsp/verify-package.sh" "$archive_first" "$archive_first.sig" "$archive_first.pem"

# Una firma de otro contenido no valida aunque el atacante recalcule SHA-256.
cp "$archive_first" "$tampered"
printf 'x' >> "$tampered"
tampered_name=$(basename -- "$tampered")
tampered_hash=$(sha256sum "$tampered" | awk '{print $1}')
printf '%s  %s\n' "$tampered_hash" "$tampered_name" > "$tampered.sha256"
set +e
"$root/sdk/cpp/lsp/verify-package.sh" "$tampered" "$archive_first.sig" "$archive_first.pem" >/dev/null 2>&1
status=$?
set -e
test "$status" -ne 0

tar -xzf "$archive_first" -C "$install_root"
package_root=$(find "$install_root" -mindepth 1 -maxdepth 1 -type d)
"$package_root/install.sh" "$install_root/prefix" >/dev/null

php "$root/bin/jas" make:project "$workspace" 'Installed LSP' >/dev/null
uri="file://$workspace"
initialize=$(printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{}}}' "$uri")
initialized='{"jsonrpc":"2.0","method":"initialized","params":{}}'
shutdown='{"jsonrpc":"2.0","id":2,"method":"shutdown","params":null}'
exit_message='{"jsonrpc":"2.0","method":"exit","params":null}'
: > "$input"
for body in "$initialize" "$initialized" "$shutdown" "$exit_message"; do
    printf 'Content-Length: %s\r\n\r\n%s' "${#body}" "$body" >> "$input"
done
JAS_ROOT="$root" "$install_root/prefix/bin/jas-lsp" "$workspace" < "$input" > "$output"
grep -q '"id":1,"result":{"capabilities"' "$output"
grep -q '"id":2,"result":null' "$output"

printf '%s\n' 'JAS LSP REPRODUCIBLE SIGNED DISTRIBUTION: PASS'
