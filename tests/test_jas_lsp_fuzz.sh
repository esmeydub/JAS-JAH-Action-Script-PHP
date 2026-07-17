#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_fuzz.XXXXXX)
input=$(mktemp /tmp/jas_lsp_fuzz_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_fuzz_output.XXXXXX)
error=$(mktemp /tmp/jas_lsp_fuzz_error.XXXXXX)
trap 'rm -rf "$workspace" "$input" "$output" "$error"' EXIT HUP INT TERM

uri="file://$workspace"
: > "$input"

# 300 árboles truncados prueban recuperación del parser sin cerrar la sesión.
case_number=1
while test "$case_number" -le 300; do
    body=$(printf '{"jsonrpc":"2.0","id":%s,"params":[' "$case_number")
    printf 'Content-Length: %s\r\n\r\n%s' "${#body}" "$body" >> "$input"
    case_number=$((case_number + 1))
done

# 200 mensajes bien formados pero no admitidos ejercen validación y allowlist.
while test "$case_number" -le 500; do
    body=$(printf '{"jsonrpc":"2.0","id":%s,"method":"workspace/executeCommand","params":{"command":"never"}}' "$case_number")
    printf 'Content-Length: %s\r\n\r\n%s' "${#body}" "$body" >> "$input"
    case_number=$((case_number + 1))
done

# El bridge debe seguir operativo después de todos los rechazos.
initialize=$(printf '{"jsonrpc":"2.0","id":901,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{}}}' "$uri")
initialized='{"jsonrpc":"2.0","method":"initialized","params":{}}'
shutdown='{"jsonrpc":"2.0","id":902,"method":"shutdown","params":null}'
exit_message='{"jsonrpc":"2.0","method":"exit","params":null}'
for body in "$initialize" "$initialized" "$shutdown" "$exit_message"; do
    printf 'Content-Length: %s\r\n\r\n%s' "${#body}" "$body" >> "$input"
done

"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output" 2> "$error"
parse_count=$(grep -o '"code":-32700' "$output" | wc -l)
invalid_count=$(grep -o '"code":-32602' "$output" | wc -l)
test "$parse_count" -eq 300
test "$invalid_count" -eq 200
grep -q '"id":901,"result":{"capabilities"' "$output"
grep -q '"id":902,"result":null' "$output"
test ! -s "$error"

printf '%s\n' 'JAS LSP PROLONGED FUZZ: PASS (500 malformed or forbidden messages)'
