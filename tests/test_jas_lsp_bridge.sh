#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
timeout_bridge=${2:-}
rate_bridge=${3:-}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_bridge.XXXXXX)
input=$(mktemp /tmp/jas_lsp_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_output.XXXXXX)
attack_input=$(mktemp /tmp/jas_lsp_attack_input.XXXXXX)
attack_output=$(mktemp /tmp/jas_lsp_attack_output.XXXXXX)
attack_error=$(mktemp /tmp/jas_lsp_attack_error.XXXXXX)
fake_cli=$(mktemp /tmp/jas_lsp_fake_cli.XXXXXX.php)
trap 'rm -rf "$workspace" "$input" "$output" "$attack_input" "$attack_output" "$attack_error" "$fake_cli" "${outside:-}"' EXIT HUP INT TERM

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
test "$status" -eq 1
test ! -s "$attack_output"
grep -q '^JAS LSP bridge terminated after backend failure$' "$attack_error"

# Landlock impide que un backend sustituido escriba dentro o fuera del workspace.
outside="${workspace}-outside"
printf '%s\n' original > "$outside"
printf '%s\n' '<?php @file_put_contents($argv[3] . "/inside-write", "contaminated"); @file_put_contents($argv[3] . "-outside", "contaminated");' > "$fake_cli"
set +e
"$bridge" "$(command -v php)" "$fake_cli" "$workspace" < /dev/null > "$attack_output" 2> "$attack_error"
status=$?
set -e
test "$status" -eq 1
test ! -e "$workspace/inside-write"
test "$(cat "$outside")" = original
rm -f "$outside"

if test -n "$timeout_bridge"; then
    # Seccomp impide incluso sockets locales; sin el filtro el backend se colgaría.
    printf '%s\n' '<?php $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); if (is_array($pair)) { while (true) { usleep(100000); } }' > "$fake_cli"
    set +e
    "$timeout_bridge" "$(command -v php)" "$fake_cli" "$workspace" < /dev/null > "$attack_output" 2> "$attack_error"
    status=$?
    set -e
    test "$status" -eq 1
    grep -q '^JAS LSP bridge terminated after backend failure$' "$attack_error"

    # Backend colgado: el watchdog mata PHP y corta la sesión completa.
    printf '%s\n' '<?php while (true) { usleep(100000); }' > "$fake_cli"
    printf 'Content-Length: %s\r\n\r\n%s' "${#body_initialize}" "$body_initialize" > "$attack_input"
    set +e
    "$timeout_bridge" "$(command -v php)" "$fake_cli" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
    status=$?
    set -e
    test "$status" -eq 1
    grep -q '^JAS LSP bridge terminated an unresponsive backend$' "$attack_error"

    # El request 257 se rechaza localmente; el backend sólo drena y no responde.
    printf '%s\n' '<?php while (!feof(STDIN)) { fread(STDIN, 8192); usleep(1000); }' > "$fake_cli"
    initialize_pressure=$(printf '{"jsonrpc":"2.0","id":1000,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{}}}' "$uri")
    printf 'Content-Length: %s\r\n\r\n%s' "${#initialize_pressure}" "$initialize_pressure" > "$attack_input"
    request=1001
    while test "$request" -le 1256; do
        pressure=$(printf '{"jsonrpc":"2.0","id":%s,"method":"textDocument/hover","params":{"textDocument":{"uri":"%s/test.php"},"position":{"line":0,"character":0}}}' "$request" "$uri")
        printf 'Content-Length: %s\r\n\r\n%s' "${#pressure}" "$pressure" >> "$attack_input"
        request=$((request + 1))
    done
    set +e
    "$timeout_bridge" "$(command -v php)" "$fake_cli" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
    status=$?
    set -e
    test "$status" -eq 1
    grep -q '"id":1256,"error":{"code":-32000,"message":"Server request limit reached"}' "$attack_output"
fi

if test -n "$rate_bridge"; then
    # Una ráfaga consume el bucket; se informa una vez y el resto se descarta.
    printf '%s\n' '<?php while (!feof(STDIN)) { fread(STDIN, 8192); }' > "$fake_cli"
    : > "$attack_input"
    request=1
    while test "$request" -le 40; do
        pressure=$(printf '{"jsonrpc":"2.0","id":%s,"method":"workspace/executeCommand","params":{"command":"forbidden"}}' "$request")
        printf 'Content-Length: %s\r\n\r\n%s' "${#pressure}" "$pressure" >> "$attack_input"
        request=$((request + 1))
    done
    set +e
    "$rate_bridge" "$(command -v php)" "$fake_cli" "$workspace" < "$attack_input" > "$attack_output" 2> "$attack_error"
    status=$?
    set -e
    test "$status" -eq 1
    grep -q '"id":33,"error":{"code":-32001,"message":"Language message rate limit exceeded"}' "$attack_output"
    ! grep -q '"id":34' "$attack_output"
    count=$(grep -o 'Language message rate limit exceeded' "$attack_output" | wc -l)
    test "$count" -eq 1
fi
printf '%s\n' 'JAS STANDARD LSP BRIDGE LIFECYCLE: PASS'
printf '%s\n' 'JAS LSP BRIDGE SECURITY BOUNDARY: PASS'
