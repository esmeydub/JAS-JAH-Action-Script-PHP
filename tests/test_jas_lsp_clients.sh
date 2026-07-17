#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_clients.XXXXXX)
input=$(mktemp /tmp/jas_lsp_client_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_client_output.XXXXXX)
error=$(mktemp /tmp/jas_lsp_client_error.XXXXXX)
trap 'rm -rf "$workspace" "$input" "$output" "$error"' EXIT HUP INT TERM
php "$root/bin/jas" make:project "$workspace" 'JAS Client Profiles' >/dev/null
uri="file://$workspace"

verify_profile() {
    profile=$1
    capabilities=$2
    encoding=$3
    initialize=$(printf '{"jsonrpc":"2.0","id":"%s-init","method":"initialize","params":{"processId":null,"clientInfo":{"name":"%s"},"rootUri":"%s","capabilities":%s}}' "$profile" "$profile" "$uri" "$capabilities")
    initialized='{"jsonrpc":"2.0","method":"initialized","params":{}}'
    shutdown=$(printf '{"jsonrpc":"2.0","id":"%s-stop","method":"shutdown","params":null}' "$profile")
    exit_message='{"jsonrpc":"2.0","method":"exit","params":null}'
    : > "$input"
    for body in "$initialize" "$initialized" "$shutdown" "$exit_message"; do
        printf 'Content-Length: %s\r\n\r\n%s' "${#body}" "$body" >> "$input"
    done
    "$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output" 2> "$error"
    grep -q "\"id\":\"$profile-init\",\"result\":{\"capabilities\":{\"positionEncoding\":\"$encoding\"" "$output"
    grep -q "\"id\":\"$profile-stop\",\"result\":null" "$output"
    grep -q '"serverInfo":{"name":"JAS Language Server"' "$output"
    test ! -s "$error"
}

verify_profile neovim '{"general":{"positionEncodings":["utf-16"]},"workspace":{"workspaceEdit":{"documentChanges":true,"resourceOperations":["rename"],"changeAnnotationSupport":{"groupsOnLabel":true}}}}' utf-16
verify_profile eglot '{}' utf-16
verify_profile sublime-lsp '{"general":{"positionEncodings":["utf-16"]},"workspace":{"workspaceEdit":{"documentChanges":true,"resourceOperations":["create","rename","delete"]}}}' utf-16
verify_profile helix '{"general":{"positionEncodings":["utf-8","utf-16"]},"workspace":{"workspaceEdit":{"documentChanges":true,"resourceOperations":["rename"]}}}' utf-8

printf '%s\n' 'JAS LSP CLIENT PROFILES: PASS (Neovim, Eglot, Sublime LSP, Helix)'
