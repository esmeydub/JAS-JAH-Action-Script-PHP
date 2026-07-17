#!/bin/sh
set -eu

nvim=${1:-}
bridge=${2:-sdk/cpp/lsp/jas-lsp-bridge}
if test -z "$nvim"; then
    nvim=$(command -v nvim || true)
fi
if test -z "$nvim" || test ! -x "$nvim"; then
    printf '%s\n' 'SKIP JAS LSP NEOVIM (editor binary unavailable)'
    exit 0
fi

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_neovim.XXXXXX)
lua=$(mktemp /tmp/jas_lsp_neovim.XXXXXX.lua)
output=$(mktemp /tmp/jas_lsp_neovim_output.XXXXXX)
nvim_home=$(mktemp -d /tmp/jas_lsp_neovim_home.XXXXXX)
trap 'rm -rf "$workspace" "$lua" "$output" "$nvim_home"' EXIT HUP INT TERM

php "$root/bin/jas" make:project "$workspace" 'Neovim Real Client' >/dev/null
php "$root/bin/jas" make:domain "$workspace" Tramites tramite >/dev/null
php "$root/bin/jas" make:type "$workspace" Solicitud >/dev/null
php "$root/bin/jas" make:action "$workspace" Tramites tramite.crear Solicitud Solicitud tramites.create >/dev/null
source_file="$workspace/app/Actions/TramiteCrear.php"

cat > "$lua" <<'LUA'
local bridge = assert(os.getenv('JAS_TEST_BRIDGE'))
local php = assert(os.getenv('JAS_TEST_PHP'))
local root = assert(os.getenv('JAS_TEST_ROOT'))
local workspace = assert(os.getenv('JAS_TEST_WORKSPACE'))
local source = assert(os.getenv('JAS_TEST_SOURCE'))
vim.cmd('edit ' .. vim.fn.fnameescape(source))
vim.bo.filetype = 'php'
local client_id = assert(vim.lsp.start({
  name = 'jas',
  cmd = { bridge, php, root .. '/bin/jas', workspace },
  root_dir = workspace,
}))
assert(vim.wait(10000, function()
  local client = vim.lsp.get_client_by_id(client_id)
  return client ~= nil and client.initialized and vim.lsp.buf_is_attached(0, client_id)
end, 20), 'JAS Neovim client did not initialize')
local client = assert(vim.lsp.get_client_by_id(client_id))
assert(client.server_capabilities.hoverProvider == true, 'JAS hover capability missing')
assert(client.server_capabilities.definitionProvider == true, 'JAS definition capability missing')
local line = vim.api.nvim_buf_get_lines(0, 4, 5, false)[1]
local column = assert(line:find('Solicitud', 1, true)) - 1
local response = client:request_sync('textDocument/hover', {
  textDocument = { uri = vim.uri_from_bufnr(0) },
  position = { line = 4, character = column },
}, 5000, 0)
assert(response ~= nil and response.err == nil and response.result ~= nil, 'JAS hover failed through Neovim')
client:stop(false)
assert(vim.wait(10000, function() return vim.lsp.get_client_by_id(client_id) == nil end, 20), 'JAS Neovim client did not stop')
print('JAS LSP REAL NEOVIM CLIENT: PASS')
vim.cmd('qa!')
LUA

if ! JAS_TEST_BRIDGE="$bridge" JAS_TEST_PHP="$(command -v php)" JAS_TEST_ROOT="$root" \
    JAS_TEST_WORKSPACE="$workspace" JAS_TEST_SOURCE="$source_file" \
    XDG_STATE_HOME="$nvim_home/state" XDG_CACHE_HOME="$nvim_home/cache" XDG_CONFIG_HOME="$nvim_home/config" \
    "$nvim" --headless --clean -l "$lua" > "$output" 2>&1; then
    sed -n '1,120p' "$output" >&2
    exit 1
fi
grep -q 'JAS LSP REAL NEOVIM CLIENT: PASS' "$output"
printf '%s\n' 'JAS LSP REAL NEOVIM CLIENT: PASS'
