<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Storage;

/** Informe de una importación: una fila por entrada del archivo, con avisos y errores. */
class ImportReport
{
    public const IMPORTED = 'importado';
    public const SKIPPED = 'omitido';
    public const ERROR = 'error';
    public const DRY_RUN = 'simulado';

    /** @var list<array{wp_id:string,title:string,status:string,public_id:string,old_url:string,new_url:string,notes:string}> */
    public array $rows = [];

    /** @var array<string,int> */
    public array $counts = [];

    /** @param list<string> $notes */
    public function add(string $wpId, string $title, string $status, string $publicId = '', string $oldUrl = '', string $newUrl = '', array $notes = []): void
    {
        $this->rows[] = [
            'wp_id' => $wpId,
            'title' => $title,
            'status' => $status,
            'public_id' => $publicId,
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'notes' => implode(' | ', $notes),
        ];

        $this->counts[$status] = ($this->counts[$status] ?? 0) + 1;
    }

    public function count(string $status): int
    {
        return $this->counts[$status] ?? 0;
    }

    public function warnings(): int
    {
        return count(array_filter($this->rows, fn ($r) => $r['notes'] !== '' && $r['status'] === self::IMPORTED));
    }

    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM: Excel abre el UTF-8 con los acentos bien
        fputcsv($handle, ['id_wordpress', 'titulo', 'estado', 'id_publiblog', 'url_anterior', 'url_nueva', 'avisos'], ';', '"', '\\');

        foreach ($this->rows as $row) {
            fputcsv($handle, array_values($row), ';', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** Guarda el CSV en el almacenamiento privado del CMS y devuelve su ruta relativa. */
    public function save(int $blogId): string
    {
        $path = sprintf('imports/blog-%d-%s.csv', $blogId, now()->format('Ymd-His'));
        Storage::disk('local')->put($path, $this->toCsv());

        return $path;
    }
}
