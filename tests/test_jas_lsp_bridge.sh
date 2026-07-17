#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_bridge.XXXXXX)
input=$(mktemp /tmp/jas_lsp_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_output.XXXXXX)
trap 'rm -rf "$workspace" "$input" "$output"' EXIT HUP INT TERM

mkdir -p "$workspace"
uri="file://$workspace"
body_initialize=$(printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{"general":{"positionEncodings":["utf-16"]}}}}' "$uri")
body_initialized='{"jsonrpc":"2.0","method":"initialized","params":{}}'
body_shutdown='{"jsonrpc":"2.0","id":"stop","method":"shutdown","params":null}'
body_exit='{"jsonrpc":"2.0","method":"exit","params":null}'
{
    printf 'Content-Length: %s\r\n\r\n%s' "${#body_initialize}" "$body_initialize"
    printf 'Content-Length: %s\r\n\r\n%s' "${#body_initialized}" "$body_initialized"
    printf 'Content-Length: %s\r\n\r\n%s' "${#body_shutdown}" "$body_shutdown"
    printf 'Content-Length: %s\r\n\r\n%s' "${#body_exit}" "$body_exit"
} > "$input"

"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output"
grep -q '"id":1,"result":{"capabilities"' "$output"
grep -q '"id":"stop","result":null' "$output"
count=$(grep -o 'Content-Length:' "$output" | wc -l)
test "$count" -eq 2
printf '%s\n' 'JAS STANDARD LSP BRIDGE LIFECYCLE: PASS'
