<?php

namespace App\Services\Reservations;

use App\Enums\SpreadsheetFormat;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads an arrival import file into raw rows: the eZee Arrival List CSV export as it comes, or the estatell template.
 * Nothing is matched or saved here; `ArrivalImportPlanner` does that. See docs/12-reservations/13-arrival-import.md#files.
 */
class ArrivalImportReader
{
    /**
     * Most rows one import takes.
     */
    public const int MAX_ROWS = 300;

    /**
     * The template columns a file must have. A file without one of them is refused before any row is read.
     *
     * @var list<string>
     */
    public const array REQUIRED_COLUMNS = ['res_no', 'guest', 'arrival', 'departure', 'adults', 'total'];

    /**
     * The estatell template columns, in order.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['res_no', 'guest', 'room', 'room_type', 'arrival', 'departure', 'adults', 'children', 'total', 'deposit', 'company', 'source', 'status', 'boat_in', 'boat_out', 'notes'];

    /**
     * @return list<array{line: int, res_no: string, guest: string, room: string, room_type: string, arrival: string, departure: string, adults: string, children: string, total: string, deposit: string, company: string, source: string, status: string, boat_in: string, boat_out: string, notes: string}>
     */
    public function read(UploadedFile $file): array
    {
        $format = SpreadsheetFormat::from(Str::lower($file->getClientOriginalExtension()));

        $reader = SimpleExcelReader::create($file->getRealPath(), $format->value)
            ->formatHeadersUsing(fn (string $header): string => $this->header($header))
            ->trimValues();

        $headers = array_values($reader->getHeaders() ?? []);
        $isEzee = in_array('res. no', $headers, true);

        $missing = $isEzee ? [] : array_values(array_diff(self::REQUIRED_COLUMNS, $headers));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => __('This file is not an arrival list: it has no :columns column. Use the template (Template ▾) or an arrival list export from your old PMS.', ['columns' => implode(', ', $missing)]),
            ]);
        }

        $rows = [];
        $line = 1;

        foreach ($reader->getRows() as $values) {
            $line++;
            $values = array_map(fn (mixed $value): string => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i') : trim((string) $value), $values);

            if (implode('', $values) === '') {
                continue;
            }

            if (count($rows) === self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS]),
                ]);
            }

            $rows[] = ['line' => $line, ...($isEzee ? $this->ezeeRow($values) : $this->templateRow($values))];
        }

        return $rows;
    }

    /**
     * Lower case, single spaces, so `Res. No` and ` res. no ` match.
     */
    private function header(string $header): string
    {
        return Str::of($header)->lower()->squish()->toString();
    }

    /**
     * @param  array<string, string>  $values
     * @return array{res_no: string, guest: string, room: string, room_type: string, arrival: string, departure: string, adults: string, children: string, total: string, deposit: string, company: string, source: string, status: string, boat_in: string, boat_out: string, notes: string}
     */
    private function templateRow(array $values): array
    {
        $column = fn (string $name): string => $values[$name] ?? '';

        return [
            'res_no' => $column('res_no'),
            'guest' => $column('guest'),
            'room' => $column('room'),
            'room_type' => $column('room_type'),
            'arrival' => $column('arrival'),
            'departure' => $column('departure'),
            'adults' => $column('adults'),
            'children' => $column('children'),
            'total' => $column('total'),
            'deposit' => $column('deposit'),
            'company' => $column('company'),
            'source' => $column('source'),
            'status' => $column('status'),
            'boat_in' => $column('boat_in'),
            'boat_out' => $column('boat_out'),
            'notes' => $column('notes'),
        ];
    }

    /**
     * One eZee Arrival List row. `Room` holds the number and the type (`A10 - Deluxe Room(King)`), `Pax` adults and
     * children (`2 / 0`), `Rate(RM)` the price of one night. Boat times are read from the remarks columns.
     *
     * @param  array<string, string>  $values
     * @return array{res_no: string, guest: string, room: string, room_type: string, arrival: string, departure: string, adults: string, children: string, total: string, deposit: string, company: string, source: string, status: string, boat_in: string, boat_out: string, notes: string}
     */
    private function ezeeRow(array $values): array
    {
        [$room, $roomType] = $this->ezeeRoom($values['room'] ?? '');
        $pax = array_map('trim', explode('/', $values['pax'] ?? ''));
        $remarks = implode(' ', array_filter($values, fn (string $value, string $key): bool => str_ends_with($key, 'remarks'), ARRAY_FILTER_USE_BOTH));

        return [
            'res_no' => $values['res. no'] ?? '',
            'guest' => $values['guest'] ?? '',
            'room' => $room,
            'room_type' => $roomType,
            'arrival' => $values['arrival'] ?? '',
            'departure' => $values['departure'] ?? '',
            'adults' => $pax[0],
            'children' => $pax[1] ?? '0',
            'total' => $this->ezeeTotal($values['rate(rm)'] ?? '', $values['nights'] ?? ''),
            'deposit' => $values['deposit(rm)'] ?? '',
            'company' => $values['company'] ?? '',
            'source' => $values['business source'] ?? '',
            'status' => $values['res.type'] ?? '',
            'boat_in' => $this->boatTime($remarks, 'IN'),
            'boat_out' => $this->boatTime($remarks, 'OUT'),
            'notes' => $values['reservation remarks'] ?? '',
        ];
    }

    /**
     * @return array{0: string, 1: string} Room number (empty when unassigned) and room type name.
     */
    private function ezeeRoom(string $cell): array
    {
        if (preg_match('/^\s*(\S+)\s+-\s+(.+)$/', $cell, $match) === 1) {
            return [$match[1], trim($match[2])];
        }

        return ['', trim($cell, " -\t")];
    }

    /**
     * Rate x nights, or empty when either cannot be read.
     */
    private function ezeeTotal(string $rate, string $nights): string
    {
        try {
            return (string) BigDecimal::of(str_replace(',', '', $rate))->multipliedBy((int) $nights)->toScale(2);
        } catch (MathException) {
            return '';
        }
    }

    /**
     * `BOAT IN: 1030`, `BOAT IN TIME : 10:30` or `BOAT IN: OWN BOAT`; empty when absent.
     */
    private function boatTime(string $remarks, string $direction): string
    {
        if (preg_match('/BOAT\s*'.$direction.'(?:\s*TIME)?\s*:\s*(OWN\s*BOAT|\d{1,2}[:.]?\d{2})/i', $remarks, $match) === 1) {
            return Str::upper(Str::squish($match[1]));
        }

        return '';
    }
}
