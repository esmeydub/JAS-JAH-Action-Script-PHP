#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_bridge.XXXXXX)
input=$(mktemp /tmp/jas_lsp_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_output.XXXXXX)
attack_input=$(mktemp /tmp/jas_lsp_attack_input.XXXXXX)
attack_output=$(mktemp /tmp/jas_lsp_attack_output.XXXXXX)
attack_error=$(mktemp /tmp/jas_lsp_attack_error.XXXXXX)
fake_cli=$(mktemp /tmp/jas_lsp_fake_cli.XXXXXX.php)
trap 'rm -rf "$workspace" "$input" "$output" "$attack_input" "$attack_output" "$attack_error" "$fake_cli"' EXIT HUP INT TERM

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

# La misma sesión llega byte por byte para demostrar framing parcial real.
dd if="$input" bs=1 2>/dev/null |
    "$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" > "$attack_output"
grep -q '"id":1,"result":{"capabilities"' "$attack_output"
grep -q '"id":"stop","result":null' "$attack_output"

# Un método fuera de la allowlist se rechaza en el bridge y nunca llega a PHP.
unknown=$(printf '{"jsonrpc":"2.0","id":91,"method":"workspace/executeCommand","params":{"command":"touch %s/forbidden"}}' "$workspace")
printf 'Content-Length: %s\r\n\r\n%s' "${#unknown}" "$unknown" > "$attack_input"
set +e
"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -ne 0
grep -q '"id":91,"error":{"code":-32602' "$attack_output"
test ! -e "$workspace/forbidden"

# Claves duplicadas se rechazan para impedir ambigüedad entre parsers.
duplicate='{"jsonrpc":"2.0","id":92,"id":93,"method":"initialize","params":{}}'
printf 'Content-Length: %s\r\n\r\n%s' "${#duplicate}" "$duplicate" > "$attack_input"
set +e
"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -ne 0
grep -q '"error":{"code":-32602' "$attack_output"

# Content-Length fuera del límite cierra de forma segura y no revela rutas.
printf 'Content-Length: 9000000\r\n\r\n' > "$attack_input"
set +e
JAS_TEST_SECRET=must_not_leak "$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -ne 0
grep -q '^JAS LSP bridge rejected malformed framing$' "$attack_error"
! grep -q 'must_not_leak\|/home/\|/tmp/jas_lsp_bridge' "$attack_error"

# Las rutas de arranque inválidas se rechazan antes de crear el proceso hijo.
set +e
"$bridge" /path/that/does/not/exist "$root/bin/jas" "$workspace" > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -eq 2
grep -q '^JAS LSP bridge rejected startup paths$' "$attack_error"

# Si PHP muere, el bridge termina sin reiniciar una sesión semántica incompleta.
printf '%s\n' '<?php exit(23);' > "$fake_cli"
set +e
JAS_TEST_SECRET=must_not_leak "$bridge" "$(command -v php)" "$fake_cli" "$workspace" < /dev/null > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -eq 23
test ! -s "$attack_output"
test ! -s "$attack_error"
printf '%s\n' 'JAS STANDARD LSP BRIDGE LIFECYCLE: PASS'
printf '%s\n' 'JAS LSP BRIDGE SECURITY BOUNDARY: PASS'
