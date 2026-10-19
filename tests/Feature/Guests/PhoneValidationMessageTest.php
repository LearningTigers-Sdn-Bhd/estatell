<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Propaganistas\LaravelPhone\Rules\Phone;

test('a phone number that is not valid gets a readable message, not a translation key', function (string $locale, string $message) {
    App::setLocale($locale);

    $errors = Validator::make(['phone' => '601129292'], ['phone' => [(new Phone)->country('MY')->international()]])->errors();

    expect($errors->first('phone'))->toBe($message);
})->with([
    'english' => ['en', 'Enter a valid phone number, for example +60 12 345 6789.'],
    'malay' => ['ms', 'Masukkan nombor telefon yang sah, contohnya +60 12 345 6789.'],
]);

test('a valid Malaysian number passes', function () {
    expect(Validator::make(['phone' => '+60123456789'], ['phone' => [(new Phone)->country('MY')->international()]])->passes())->toBeTrue();
});
