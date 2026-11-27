<?php

namespace App\Services\Group;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports a group page's table as CSV or as a landscape A4 PDF. Both take the same headings and rows the page builds
 * for its CSV, so the two files always match. The PDF has no property letterhead: it names the group, the page and its
 * dates, and prints the time it was made. Total rows (`$totalRows`, by row index) print bold.
 * See docs/03-staff/05-group-mode.md#exports.
 */
class GroupTableExport
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function csv(string $filename, array $headings, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }

            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $totalRows
     */
    public function pdf(string $filename, string $group, string $title, string $subtitle, array $headings, array $rows, array $totalRows = []): Response
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $numeric = [];

        foreach (array_keys($headings) as $index) {
            $values = array_filter(array_column($rows, $index), fn ($value): bool => $value !== null && $value !== '');
            $numeric[$index] = $values !== [] && array_reduce($values, fn (bool $all, $value): bool => $all && is_numeric(str_replace([',', '%', '+'], '', (string) $value)), true);
        }

        return Pdf::loadView('pdf.group-table', [
            'group' => $group,
            'title' => $title,
            'subtitle' => $subtitle,
            'headings' => $headings,
            'rows' => $rows,
            'totalRows' => array_flip($totalRows),
            'numeric' => $numeric,
            'printedAt' => now()->format('j M Y, H:i'),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true)
            ->stream("{$filename}.pdf");
    }
}
