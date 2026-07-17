<?php

declare(strict_types=1);

namespace Jah\JAS\Web;

use RuntimeException;

final class DataTable implements Component
{
    private readonly Translator $translator;
    /**
     * @param array<string,string> $columns
     * @param list<array<string,scalar|null|Component|SafeHtml>> $rows
     */
    public function __construct(
        private readonly string $caption,
        private readonly array $columns,
        private readonly array $rows,
        private readonly ?string $rowHeader = null,
        private readonly ?string $emptyMessage = null,
        ?Translator $translator = null,
    ) {
        if (trim($caption) === '' || strlen($caption) > 200) throw new RuntimeException('table_caption_invalid');
        if ($columns === [] || array_is_list($columns)) throw new RuntimeException('table_columns_invalid');
        foreach ($columns as $key => $heading) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || !is_string($heading) || trim($heading) === '') {
                throw new RuntimeException('table_columns_invalid');
            }
        }
        if ($rowHeader !== null && !array_key_exists($rowHeader, $columns)) throw new RuntimeException('table_row_header_invalid');
        if (!array_is_list($rows)) throw new RuntimeException('table_rows_invalid');
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff_key($row, $columns) !== [] || array_diff_key($columns, $row) !== []) {
                throw new RuntimeException('table_row_contract_invalid');
            }
            foreach ($row as $value) {
                if (!is_scalar($value) && $value !== null && !$value instanceof Component && !$value instanceof SafeHtml) {
                    throw new RuntimeException('table_cell_invalid');
                }
            }
        }
        if ($emptyMessage !== null && (trim($emptyMessage) === '' || strlen($emptyMessage) > 200)) throw new RuntimeException('table_empty_message_invalid');
        $this->translator = $translator ?? WebTranslations::translator();
    }

    public function render(): SafeHtml
    {
        $headers = [];
        foreach ($this->columns as $heading) $headers[] = Html::element('th', ['scope' => 'col'], $heading);
        $body = [];
        if ($this->rows === []) {
            $body[] = Html::element('tr', [], Html::element('td', ['colspan' => (string) count($this->columns)], $this->emptyMessage ?? $this->translator->text('table.empty')));
        } else {
            foreach ($this->rows as $row) {
                $cells = [];
                foreach ($this->columns as $key => $_heading) {
                    $cells[] = $key === $this->rowHeader
                        ? Html::element('th', ['scope' => 'row'], $row[$key])
                        : Html::element('td', [], $row[$key]);
                }
                $body[] = Html::element('tr', [], $cells);
            }
        }
        return Html::element('div', ['class' => 'jas-table-scroll', 'role' => 'region', 'aria-label' => $this->caption, 'tabindex' => '0'],
            Html::element('table', [],
                Html::element('caption', [], $this->caption),
                Html::element('thead', [], Html::element('tr', [], $headers)),
                Html::element('tbody', [], $body),
            ),
        );
    }
}
