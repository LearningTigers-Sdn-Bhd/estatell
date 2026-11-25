<?php

use App\Http\Requests\Pos\PosClockRequest;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Support\Facades\Validator;

it('has a readable sentence for every refusal code the POS actions can raise', function () {
    $root = dirname(__DIR__, 3);
    $codes = [];

    foreach ([$root.'/app/Actions/Pos', $root.'/app/Services/Pos'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/(?:PosRefusedException\(|::refused\()'([a-z][a-z_0-9]*[a-z0-9])'/", (string) file_get_contents($file->getPathname()), $found);
            array_push($codes, ...$found[1]);
        }
    }

    $unmapped = collect($codes)->unique()->sort()->filter(fn (string $code): bool => PosRefusalMessages::message($code) === $code)->values()->all();

    expect($codes)->not->toBeEmpty()
        ->and($unmapped)->toBe([]);
});

it('keeps the refusal codes of a room charge apart so staff can tell what to fix', function () {
    expect(PosRefusalMessages::message('charge_code_not_chargeable'))->not->toBe(PosRefusalMessages::message('folio_not_postable'))
        ->and(PosRefusalMessages::message('signature_required'))->toContain('sign');
});

it('asks for an outlet only when clocking in', function () {
    $rules = (new PosClockRequest)->rules();

    expect(Validator::make(['action' => 'out'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['action' => 'in'], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['action' => 'in', 'outlet_id' => 3], $rules)->passes())->toBeTrue();
});
