<?php

test('sends a visitor to the installer of the configured MyKad Reader version', function (string $platform, string $filename) {
    config(['estatell.mykad_reader_version' => '9.8.7']);

    $this->get(route('mykad-reader.download', $platform))
        ->assertRedirect("https://github.com/LearningTigers-Sdn-Bhd/estatell-mykad-reader/releases/download/v9.8.7/{$filename}");
})->with([
    'windows' => ['windows', 'estatell-mykad-reader-setup-v9.8.7.exe'],
    'mac' => ['mac', 'estatell-mykad-reader-v9.8.7.pkg'],
]);

test('has no download for other systems', function () {
    $this->get('/downloads/mykad-reader/linux')->assertNotFound();
});
