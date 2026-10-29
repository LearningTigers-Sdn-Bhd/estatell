<?php

use App\Services\Rooms\RoomNumberGenerator;
use App\Services\Rooms\RoomNumberRanges;

test('a list reads ranges and labels in the typed order', function () {
    $result = (new RoomNumberGenerator)->parseList('203-205, 201 ,, Villa 3, 101A');

    expect($result['error'])->toBeNull()
        ->and($result['numbers'])->toBe(['203', '204', '205', '201', 'Villa 3', '101A']);
});

test('a range keeps its prefix and the zero padding of its from number', function (string $list, array $numbers) {
    expect((new RoomNumberGenerator)->parseList($list)['numbers'])->toBe($numbers);
})->with([
    'prefix on both ends' => ['G1-G3', ['G1', 'G2', 'G3']],
    'prefix on the from end' => ['G1-3', ['G1', 'G2', 'G3']],
    'spaced prefix' => ['Villa 1 - Villa 2', ['Villa 1', 'Villa 2']],
    'zero padding' => ['08-11', ['08', '09', '10', '11']],
]);

test('a list is refused with the part that caused it', function (string $list, string $error, ?string $part) {
    $result = (new RoomNumberGenerator)->parseList($list);

    expect($result['numbers'])->toBe([])
        ->and($result['error'])->toBe($error)
        ->and($result['part'])->toBe($part);
})->with([
    'backwards' => ['101-103, 134-102', '"134-102" runs backwards. Put the smaller number first.', '134-102'],
    'two prefixes' => ['G1-H4', '"G1-H4" has two different prefixes. Use one, like G1-G4.', 'G1-H4'],
    'repeat across parts' => ['205-212, 210', '210 is listed twice. Each number can be used once.', '210'],
    'repeat ignoring case' => ['Villa 3, villa 3', 'villa 3 is listed twice. Each number can be used once.', 'villa 3'],
    'over 200' => ['101-200, 201-340', 'That is 240 rooms. One group takes at most 200.', null],
    'huge range' => ['1-999999', 'That is 999999 rooms. One group takes at most 200.', null],
    'too many digits' => ['1-1234567', '"1-1234567" has a number longer than 6 digits.', '1-1234567'],
    'label too long' => [str_repeat('A', 31), '"'.str_repeat('A', 31).'" is too long. A room number takes at most 30 characters.', str_repeat('A', 31)],
]);

test('an empty list gives no numbers and no error', function () {
    expect((new RoomNumberGenerator)->parseList(' , '))->toBe(['numbers' => [], 'error' => null, 'part' => null]);
});

test('saved numbers join back into the shortest ranges', function (array $numbers, string $list) {
    expect((new RoomNumberRanges)->join($numbers))->toBe($list);
})->with([
    'runs and a gap' => [['102', '103', '104', '140'], '102-104, 140'],
    'typed order kept' => [['203', '204', '201'], '203-204, 201'],
    'prefix' => [['G1', 'G2', 'G3', 'Villa 3'], 'G1-G3, Villa 3'],
    'padding' => [['08', '09', '10'], '08-10'],
    'different padding does not join' => [['9', '010'], '9, 010'],
    'labels between runs' => [['101', '101A', '102'], '101, 101A, 102'],
    'nothing' => [[], ''],
]);

test('joined numbers parse back to the same numbers', function () {
    $numbers = ['202', '203', '204', '218', 'G1', 'G2', '01', '02', 'Villa 3', '101A'];

    $list = (new RoomNumberRanges)->join($numbers);

    expect((new RoomNumberGenerator)->parseList($list)['numbers'])->toBe($numbers);
});
