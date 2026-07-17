#!/bin/sh
set -eu

package_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
prefix=${1:-"${HOME:?}/.local"}
destination="$prefix/bin"

test -x "$package_root/bin/jas-lsp-bridge" || {
    printf '%s\n' 'invalid JAS LSP package: bridge missing' >&2
    exit 2
}
test -x "$package_root/bin/jas-lsp" || {
    printf '%s\n' 'invalid JAS LSP package: launcher missing' >&2
    exit 2
}

install -d -m 0755 "$destination"
install -m 0755 "$package_root/bin/jas-lsp-bridge" "$destination/jas-lsp-bridge"
install -m 0755 "$package_root/bin/jas-lsp" "$destination/jas-lsp"
printf 'JAS LSP installed in %s\n' "$destination"
printf '%s\n' 'Set JAS_ROOT to the trusted JAS repository before starting jas-lsp.'
