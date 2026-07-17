<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class AccessibilityAudit
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    public function audit(string $html): AccessibilityReport
    {
        if ($html === '' || strlen($html) > 8_388_608) throw new RuntimeException('accessibility_document_invalid');
        [$nodes, $parseError] = $this->parse($html);
        $findings = [];
        if ($parseError !== null) $this->finding($findings, 'html_structure_invalid', '4.1.2', 'error', $parseError, 'document');
        if (preg_match('/^\s*<!doctype html>/i', $html) !== 1) $this->finding($findings, 'doctype_missing', '4.1.2', 'error', 'El documento debe declarar doctype HTML.', 'document');

        $byTag = [];
        $byId = [];
        foreach ($nodes as $index => $node) {
            $byTag[$node['tag']][] = $index;
            $id = $node['attributes']['id'] ?? null;
            if (is_string($id)) $byId[$id][] = $index;
        }
        foreach ($byId as $id => $indexes) if (count($indexes) > 1) $this->finding($findings, 'duplicate_id', '4.1.2', 'error', 'El identificador aparece más de una vez.', '#' . $id);

        $htmlNodes = $byTag['html'] ?? [];
        if (count($htmlNodes) !== 1 || !LocaleNegotiator::valid((string) ($nodes[$htmlNodes[0]]['attributes']['lang'] ?? ''))) {
            $this->finding($findings, 'document_language_missing', '3.1.1', 'error', 'El documento debe declarar un único idioma BCP 47 permitido.', 'html');
        }
        if (count($byTag['head'] ?? []) !== 1 || count($byTag['body'] ?? []) !== 1) $this->finding($findings, 'document_regions_invalid', '1.3.1', 'error', 'El documento requiere un head y un body.', 'document');
        $titles = $byTag['title'] ?? [];
        if (count($titles) !== 1 || trim($nodes[$titles[0]]['text'] ?? '') === '') $this->finding($findings, 'page_title_missing', '2.4.2', 'error', 'La página necesita un título no vacío.', 'title');
        if (count($byTag['main'] ?? []) !== 1) $this->finding($findings, 'main_landmark_invalid', '1.3.1', 'error', 'La página debe contener exactamente un landmark main.', 'main');
        if (count($byTag['h1'] ?? []) !== 1) $this->finding($findings, 'primary_heading_invalid', '1.3.1', 'error', 'La página debe contener exactamente un encabezado h1.', 'h1');

        $lastHeading = 0;
        foreach ($nodes as $node) {
            if (preg_match('/^h([1-6])$/', $node['tag'], $match) !== 1) continue;
            $level = (int) $match[1];
            if (trim($node['text']) === '') $this->finding($findings, 'heading_empty', '2.4.6', 'error', 'Los encabezados necesitan texto.', $this->element($node));
            if ($lastHeading > 0 && $level > $lastHeading + 1) $this->finding($findings, 'heading_level_skipped', '1.3.1', 'error', 'La jerarquía de encabezados omite un nivel.', $this->element($node));
            $lastHeading = $level;
        }

        foreach ($byTag['img'] ?? [] as $index) {
            if (!array_key_exists('alt', $nodes[$index]['attributes'])) $this->finding($findings, 'image_alt_missing', '1.1.1', 'error', 'Toda imagen requiere atributo alt.', $this->element($nodes[$index]));
        }
        foreach ($byTag['a'] ?? [] as $index) {
            $node = $nodes[$index];
            if (!isset($node['attributes']['href'])) $this->finding($findings, 'link_href_missing', '2.4.4', 'error', 'El enlace necesita destino.', $this->element($node));
            $href = $node['attributes']['href'] ?? null;
            if (is_string($href) && str_starts_with($href, '#') && (strlen($href) === 1 || !isset($byId[substr($href, 1)]))) {
                $this->finding($findings, 'fragment_target_missing', '2.4.1', 'error', 'El enlace interno no tiene un destino existente.', $this->element($node));
            }
            if (!$this->hasAccessibleName($node, $byId, $nodes)) $this->finding($findings, 'link_name_missing', '2.4.4', 'error', 'El enlace necesita nombre accesible.', $this->element($node));
        }
        foreach ($byTag['button'] ?? [] as $index) {
            $node = $nodes[$index];
            if (!$this->hasAccessibleName($node, $byId, $nodes)) $this->finding($findings, 'button_name_missing', '4.1.2', 'error', 'El botón necesita nombre accesible.', $this->element($node));
        }

        $labels = [];
        foreach ($byTag['label'] ?? [] as $index) {
            $for = $nodes[$index]['attributes']['for'] ?? null;
            if (is_string($for)) $labels[$for] = true;
        }
        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($byTag[$tag] ?? [] as $index) {
                $node = $nodes[$index];
                $type = strtolower((string) ($node['attributes']['type'] ?? ''));
                if ($tag === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) continue;
                $id = $node['attributes']['id'] ?? null;
                $named = is_string($id) && isset($labels[$id]);
                if (!$named && !$this->hasAncestorTag($index, 'label', $nodes) && !$this->hasAccessibleName($node, $byId, $nodes)) $this->finding($findings, 'form_label_missing', '3.3.2', 'error', 'El control requiere label o nombre accesible.', $this->element($node));
                if (($node['attributes']['aria-invalid'] ?? null) === 'true') {
                    $describedBy = $node['attributes']['aria-describedby'] ?? null;
                    if (!is_string($describedBy) || !$this->referencesExist($describedBy, $byId)) {
                        $this->finding($findings, 'invalid_field_description_missing', '3.3.1', 'error', 'Un campo inválido debe referir su explicación.', $this->element($node));
                    }
                }
            }
        }

        foreach ($byTag['table'] ?? [] as $index) {
            $descendants = $this->descendants($index, $nodes);
            $captions = array_filter($descendants, fn(array $node): bool => $node['tag'] === 'caption' && trim($node['text']) !== '' && $this->nearestAncestorTag($node, 'table', $nodes) === $index);
            if ($captions === []) $this->finding($findings, 'table_caption_missing', '1.3.1', 'error', 'La tabla requiere caption.', $this->element($nodes[$index]));
            foreach ($descendants as $node) if ($node['tag'] === 'th' && $this->nearestAncestorTag($node, 'table', $nodes) === $index && !in_array($node['attributes']['scope'] ?? null, ['col', 'row'], true)) {
                $this->finding($findings, 'table_header_scope_missing', '1.3.1', 'error', 'Cada th requiere scope col o row.', $this->element($node));
            }
        }

        foreach ($nodes as $node) {
            $tabindex = $node['attributes']['tabindex'] ?? null;
            if (is_string($tabindex) && preg_match('/^[1-9][0-9]*$/', $tabindex)) $this->finding($findings, 'positive_tabindex_forbidden', '2.4.3', 'error', 'tabindex positivo altera el orden natural.', $this->element($node));
            foreach (['aria-describedby', 'aria-labelledby'] as $attribute) {
                $references = $node['attributes'][$attribute] ?? null;
                if (is_string($references) && !$this->referencesExist($references, $byId)) $this->finding($findings, 'aria_reference_missing', '4.1.2', 'error', 'La referencia ARIA no existe.', $this->element($node));
            }
        }
        $viewports = 0;
        foreach ($byTag['meta'] ?? [] as $index) {
            $node = $nodes[$index];
            if (($node['attributes']['name'] ?? null) !== 'viewport') continue;
            $viewports++;
            $content = strtolower((string) ($node['attributes']['content'] ?? ''));
            if (str_contains($content, 'user-scalable=no') || preg_match('/maximum-scale\s*=\s*1(?:\.0+)?(?:,|$)/', $content)) {
                $this->finding($findings, 'viewport_zoom_disabled', '1.4.4', 'error', 'No se puede impedir el zoom del usuario.', 'meta[name=viewport]');
            }
            if (!str_contains($content, 'width=device-width')) $this->finding($findings, 'viewport_width_invalid', '1.4.10', 'error', 'El viewport debe adaptarse al ancho del dispositivo.', 'meta[name=viewport]');
        }
        if ($viewports !== 1) $this->finding($findings, 'viewport_missing', '1.4.10', 'error', 'El documento requiere un único viewport adaptable.', 'meta[name=viewport]');

        return new AccessibilityReport($findings, $this->manualChecks());
    }

    /** @return array{list<array{tag:string,attributes:array<string,string>,parent:?int,text:string,position:int}>,?string} */
    private function parse(string $html): array
    {
        preg_match_all('/<!doctype[^>]*>|<!--.*?-->|<\/?[a-z][^>]*>/is', $html, $matches, PREG_OFFSET_CAPTURE);
        $nodes = []; $stack = []; $cursor = 0; $error = null;
        foreach ($matches[0] ?? [] as [$token, $position]) {
            $text = substr($html, $cursor, $position - $cursor);
            if ($text !== '') foreach ($stack as $open) $nodes[$open]['text'] .= html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cursor = $position + strlen($token);
            if (str_starts_with(strtolower($token), '<!doctype') || str_starts_with($token, '<!--')) continue;
            if (preg_match('/^<\/(\w[\w-]*)\s*>$/i', $token, $close) === 1) {
                $tag = strtolower($close[1]);
                $open = array_pop($stack);
                if ($open === null || $nodes[$open]['tag'] !== $tag) { $error = 'Las etiquetas HTML no están balanceadas.'; break; }
                continue;
            }
            if (preg_match('/^<([a-z][a-z0-9-]*)([^>]*)>$/is', $token, $open) !== 1) { $error = 'Existe markup no reconocido.'; break; }
            $tag = strtolower($open[1]);
            $attributes = $this->attributes($open[2]);
            if ($attributes === null) { $error = 'Existe un atributo HTML no reconocido.'; break; }
            $index = count($nodes);
            $nodes[] = ['tag' => $tag, 'attributes' => $attributes, 'parent' => $stack === [] ? null : end($stack), 'text' => '', 'position' => $position];
            if (!in_array($tag, self::VOID, true)) $stack[] = $index;
        }
        if ($error === null && $stack !== []) $error = 'Existen etiquetas HTML sin cerrar.';
        return [$nodes, $error];
    }

    /** @return array<string,string>|null */
    private function attributes(string $source): ?array
    {
        $attributes = [];
        while (trim($source) !== '') {
            if (preg_match('/^\s+([a-z_:][a-z0-9_.:-]*)(?:="([^"]*)")?/i', $source, $match) !== 1) return null;
            $name = strtolower($match[1]);
            if (isset($attributes[$name])) return null;
            $attributes[$name] = html_entity_decode($match[2] ?? $name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $source = substr($source, strlen($match[0]));
        }
        return $attributes;
    }

    private function hasAccessibleName(array $node, array $byId, array $nodes): bool
    {
        if (trim($node['text']) !== '' || trim((string) ($node['attributes']['aria-label'] ?? '')) !== '') return true;
        $labelledBy = $node['attributes']['aria-labelledby'] ?? null;
        if (!is_string($labelledBy) || !$this->referencesExist($labelledBy, $byId)) return false;
        foreach (preg_split('/\s+/', trim($labelledBy)) ?: [] as $id) {
            foreach ($byId[$id] ?? [] as $index) if (trim($nodes[$index]['text']) !== '') return true;
        }
        return false;
    }

    private function referencesExist(string $references, array $byId): bool
    {
        $ids = preg_split('/\s+/', trim($references)) ?: [];
        if ($ids === []) return false;
        foreach ($ids as $id) if ($id === '' || !isset($byId[$id]) || count($byId[$id]) !== 1) return false;
        return true;
    }

    private function descendants(int $parent, array $nodes): array
    {
        $descendants = [];
        foreach ($nodes as $node) {
            $ancestor = $node['parent'];
            while ($ancestor !== null) {
                if ($ancestor === $parent) { $descendants[] = $node; break; }
                $ancestor = $nodes[$ancestor]['parent'];
            }
        }
        return $descendants;
    }

    private function hasAncestorTag(int $index, string $tag, array $nodes): bool
    {
        $ancestor = $nodes[$index]['parent'];
        while ($ancestor !== null) {
            if ($nodes[$ancestor]['tag'] === $tag) return true;
            $ancestor = $nodes[$ancestor]['parent'];
        }
        return false;
    }

    private function nearestAncestorTag(array $node, string $tag, array $nodes): ?int
    {
        $ancestor = $node['parent'];
        while ($ancestor !== null) {
            if ($nodes[$ancestor]['tag'] === $tag) return $ancestor;
            $ancestor = $nodes[$ancestor]['parent'];
        }
        return null;
    }

    private function element(array $node): string
    {
        return isset($node['attributes']['id']) ? $node['tag'] . '#' . $node['attributes']['id'] : $node['tag'] . '@' . $node['position'];
    }

    private function finding(array &$findings, string $code, string $criterion, string $severity, string $message, string $element): void
    {
        $findings[] = compact('code', 'criterion', 'severity', 'message', 'element');
    }

    /** @return array<string,string> */
    private function manualChecks(): array
    {
        return [
            '1.4.3' => 'Contraste de texto en todos los estados y temas.',
            '1.4.10' => 'Reflow a 320 CSS px sin pérdida ni scroll bidimensional general.',
            '1.4.11' => 'Contraste de componentes, bordes, iconos y estados.',
            '2.1.1' => 'Operación completa mediante teclado.',
            '2.4.7' => 'Indicador de foco visible.',
            '2.4.11' => 'El foco no queda oculto por contenido superpuesto.',
            '2.5.8' => 'Tamaño o separación suficiente de objetivos táctiles.',
            '3.3.8' => 'Autenticación accesible sin pruebas cognitivas innecesarias.',
            '4.1.2' => 'Verificación con lector de pantalla de nombre, rol, valor y anuncios dinámicos.',
        ];
    }
}
