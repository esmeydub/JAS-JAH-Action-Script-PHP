#!/bin/sh
set -eu

bridge=${1:-sdk/cpp/lsp/jas-lsp-bridge}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
workspace=$(mktemp -d /tmp/jas_lsp_capabilities.XXXXXX)
input=$(mktemp /tmp/jas_lsp_capabilities_input.XXXXXX)
output=$(mktemp /tmp/jas_lsp_capabilities_output.XXXXXX)
error=$(mktemp /tmp/jas_lsp_capabilities_error.XXXXXX)
trap 'rm -rf "$workspace" "$input" "$output" "$error"' EXIT HUP INT TERM

php "$root/bin/jas" make:project "$workspace" 'Portal LSP' >/dev/null
php "$root/bin/jas" make:domain "$workspace" Tramites tramite >/dev/null
php "$root/bin/jas" make:type "$workspace" NuevoTramite >/dev/null
php "$root/bin/jas" make:action "$workspace" Tramites tramite.crear NuevoTramite NuevoTramite tramites.create >/dev/null
php "$root/bin/jas" make:event "$workspace" Tramites tramite.creado NuevoTramite 1 >/dev/null

# Amplía el índice para que cancelRequest llegue antes de la respuesta cancelada.
index=0
while test "$index" -lt 300; do
    name=$(printf 'TipoExtra%03d' "$index")
    printf '%s\n' "<?php declare(strict_types=1); return ['name' => '$name', 'fields' => ['id' => 'identifier'], 'strict' => true];" > "$workspace/app/Types/$name.php"
    index=$((index + 1))
done

uri="file://$workspace"
action_uri="$uri/app/Actions/TramiteCrear.php"
type_uri="$uri/app/Types/NuevoTramite.php"
valid_content="<?php\\ndeclare(strict_types=1);\\nreturn ['domain' => 'Tramites', 'name' => 'tramite.crear', 'input' => 'NuevoTramite', 'output' => 'NuevoTramite', 'capability' => 'tramites.create', 'audit' => true];\\n"
invalid_content="<?php\\ndeclare(strict_types=1);\\nreturn ['name' => ;\\n"
line_text="return ['domain' => 'Tramites', 'name' => 'tramite.crear', 'input' => 'NuevoTramite', 'output' => 'NuevoTramite', 'capability' => 'tramites.create', 'audit' => true];"
character=$(printf '%s\n' "$line_text" | awk '{ print index($0, "NuevoTramite") - 1 }')
before_hash=$(sha256sum "$workspace/app/Types/NuevoTramite.php")
before_action_hash=$(sha256sum "$workspace/app/Actions/TramiteCrear.php")
before_event_hash=$(sha256sum "$workspace/app/Events/TramiteCreadoV1.php")

frame() {
    printf 'Content-Length: %s\r\n\r\n%s' "${#1}" "$1"
}

initialize=$(printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{"general":{"positionEncodings":["utf-16"]}}}}' "$uri")
initialized='{"jsonrpc":"2.0","method":"initialized","params":{}}'
did_open=$(printf '{"jsonrpc":"2.0","method":"textDocument/didOpen","params":{"textDocument":{"uri":"%s","languageId":"jas-php","version":1,"text":"%s"}}}' "$action_uri" "$valid_content")
position=$(printf '"textDocument":{"uri":"%s"},"position":{"line":2,"character":%s}' "$action_uri" "$character")
hover=$(printf '{"jsonrpc":"2.0","id":2,"method":"textDocument/hover","params":{%s}}' "$position")
definition=$(printf '{"jsonrpc":"2.0","id":3,"method":"textDocument/definition","params":{%s}}' "$position")
references=$(printf '{"jsonrpc":"2.0","id":4,"method":"textDocument/references","params":{%s,"context":{"includeDeclaration":true}}}' "$position")
prepare=$(printf '{"jsonrpc":"2.0","id":5,"method":"textDocument/prepareRename","params":{%s}}' "$position")
rename=$(printf '{"jsonrpc":"2.0","id":6,"method":"textDocument/rename","params":{%s,"newName":"SolicitudTramite"}}' "$position")
cancelled_hover=$(printf '{"jsonrpc":"2.0","id":7,"method":"textDocument/hover","params":{%s}}' "$position")
cancel='{"jsonrpc":"2.0","method":"$/cancelRequest","params":{"id":7}}'
did_change_invalid=$(printf '{"jsonrpc":"2.0","method":"textDocument/didChange","params":{"textDocument":{"uri":"%s","version":2},"contentChanges":[{"text":"%s"}]}}' "$action_uri" "$invalid_content")
did_change_valid=$(printf '{"jsonrpc":"2.0","method":"textDocument/didChange","params":{"textDocument":{"uri":"%s","version":3},"contentChanges":[{"text":"%s"}]}}' "$action_uri" "$valid_content")
did_close=$(printf '{"jsonrpc":"2.0","method":"textDocument/didClose","params":{"textDocument":{"uri":"%s"}}}' "$action_uri")
shutdown='{"jsonrpc":"2.0","id":8,"method":"shutdown","params":null}'
exit_message='{"jsonrpc":"2.0","method":"exit","params":null}'

{
    frame "$initialize"; frame "$initialized"; frame "$did_open"
    frame "$hover"; frame "$definition"; frame "$references"; frame "$prepare"; frame "$rename"
    frame "$cancelled_hover"; frame "$cancel"
    frame "$did_change_invalid"; frame "$did_change_valid"; frame "$did_close"
    frame "$shutdown"; frame "$exit_message"
} > "$input"

"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output" 2> "$error"
test ! -s "$error"
grep -q '"id":2,"result":{"contents":{"kind":"plaintext","value":"Tipo JAS estricto' "$output"
grep -q '"id":3,"result":' "$output"
grep -q "$type_uri" "$output"
grep -q '"id":4,"result":\[' "$output"
grep -q '"id":5,"result":{"range":' "$output"
grep -q '"placeholder":"NuevoTramite"' "$output"
grep -q '"id":6,"result":{"changes":' "$output"
grep -q '"newText":"SolicitudTramite"' "$output"
grep -q '"id":7,"error":{"code":-32800,"message":"Request cancelled"}' "$output"
grep -q '"method":"textDocument/publishDiagnostics"' "$output"
grep -q '"version":2,"diagnostics":\[{' "$output"
grep -q '"version":3,"diagnostics":\[\]' "$output"
grep -q '"id":8,"result":null' "$output"
after_hash=$(sha256sum "$workspace/app/Types/NuevoTramite.php")
test "$before_hash" = "$after_hash"
test ! -e "$workspace/app/Types/SolicitudTramite.php"

# Cliente moderno: ediciones versionadas, RenameFile y anotaciones negociadas.
initialize_advanced=$(printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{"general":{"positionEncodings":["utf-16"]},"workspace":{"workspaceEdit":{"documentChanges":true,"resourceOperations":["create","rename","delete"],"changeAnnotationSupport":{"groupsOnLabel":true}}}}}}' "$uri")
{
    frame "$initialize_advanced"; frame "$initialized"; frame "$did_open"
    frame "$rename"; frame "$shutdown"; frame "$exit_message"
} > "$input"
"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output" 2> "$error"
test ! -s "$error"
grep -q '"id":6,"result":{"documentChanges":' "$output"
grep -q "\"textDocument\":{\"uri\":\"$action_uri\",\"version\":1}" "$output"
grep -q "\"textDocument\":{\"uri\":\"$type_uri\",\"version\":null}" "$output"
grep -q '"kind":"rename"' "$output"
grep -q "\"oldUri\":\"$type_uri\"" "$output"
grep -q '"newUri":"file://.*/app/Types/SolicitudTramite.php"' "$output"
grep -q '"annotationId":"jas.rename"' "$output"
grep -q '"changeAnnotations":{"jas.rename":' "$output"
! grep -q '"result":{"changes":' "$output"

# Simula aplicación y rollback del editor; el servidor nunca toca estos archivos.
sed -i 's/NuevoTramite/SolicitudTramite/g' "$workspace/app/Actions/TramiteCrear.php" "$workspace/app/Events/TramiteCreadoV1.php" "$workspace/app/Types/NuevoTramite.php"
mv "$workspace/app/Types/NuevoTramite.php" "$workspace/app/Types/SolicitudTramite.php"
grep -q 'SolicitudTramite' "$workspace/app/Actions/TramiteCrear.php"
grep -q 'SolicitudTramite' "$workspace/app/Events/TramiteCreadoV1.php"
grep -q 'SolicitudTramite' "$workspace/app/Types/SolicitudTramite.php"
test ! -e "$workspace/app/Types/NuevoTramite.php"
mv "$workspace/app/Types/SolicitudTramite.php" "$workspace/app/Types/NuevoTramite.php"
sed -i 's/SolicitudTramite/NuevoTramite/g' "$workspace/app/Actions/TramiteCrear.php" "$workspace/app/Events/TramiteCreadoV1.php" "$workspace/app/Types/NuevoTramite.php"
test "$before_hash" = "$(sha256sum "$workspace/app/Types/NuevoTramite.php")"
test "$before_action_hash" = "$(sha256sum "$workspace/app/Actions/TramiteCrear.php")"
test "$before_event_hash" = "$(sha256sum "$workspace/app/Events/TramiteCreadoV1.php")"

# documentChanges sin permiso resourceOperations nunca incluye RenameFile.
initialize_text_only=$(printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"processId":null,"rootUri":"%s","capabilities":{"workspace":{"workspaceEdit":{"documentChanges":true,"resourceOperations":["create","delete"]}}}}}' "$uri")
{
    frame "$initialize_text_only"; frame "$initialized"; frame "$did_open"
    frame "$rename"; frame "$shutdown"; frame "$exit_message"
} > "$input"
"$bridge" "$(command -v php)" "$root/bin/jas" "$workspace" < "$input" > "$output" 2> "$error"
test ! -s "$error"
grep -q '"id":6,"result":{"documentChanges":' "$output"
! grep -q '"kind":"rename"\|"annotationId"\|"changeAnnotations"' "$output"
after_hash=$(sha256sum "$workspace/app/Types/NuevoTramite.php")
test "$before_hash" = "$after_hash"
test ! -e "$workspace/app/Types/SolicitudTramite.php"
printf '%s\n' 'JAS LSP STANDARD CAPABILITIES: PASS'
printf '%s\n' 'JAS LSP NEGOTIATED WORKSPACE EDIT: PASS'
printf '%s\n' 'JAS LSP REVERSIBLE EDITOR RENAME: PASS'
