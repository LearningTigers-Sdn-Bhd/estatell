<?php

use App\Models\PosItem;
use App\Models\User;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\QrMenu;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Storage::fake(config('filesystems.public_media')));

it('keeps one square photo per item and shows it on the register and guest menus', function () {
    $s = posOrderSetup();
    $item = $s['steak']->item;
    $url = route('settings.pos.items.image.store', [$s['property']->code, $item->id]);

    $this->actingAs($s['manager'])->post($url, ['image' => UploadedFile::fake()->image('first.jpg', 900, 600)])->assertSessionHasNoErrors();
    $this->actingAs($s['manager'])->post($url, ['image' => UploadedFile::fake()->image('second.png', 500, 800)])->assertSessionHasNoErrors();

    $media = $item->refresh()->getMedia($item::IMAGE);
    $size = getimagesize($media->sole()->getPath('thumb'));

    expect([$size[0], $size[1], $size['mime']])->toBe([400, 400, 'image/webp']);

    $categories = app(OutletMenuSnapshot::class)($s['outlet'])['categories'];
    $find = fn (array $list): ?string => collect($list)->flatMap(fn (array $category): array => $category['items'])->firstWhere('id', $item->id)['imageUrl'];

    expect($find($categories))->toBe($item->imageUrl())->not->toBeNull()
        ->and($find(app(QrMenu::class)($categories)))->toBe($item->imageUrl());

    $this->actingAs($s['manager'])->delete(route('settings.pos.items.image.destroy', [$s['property']->code, $item->id]));

    expect($item->refresh()->imageUrl())->toBeNull();
});

it('refuses files that are not small enough images, other staff and other properties', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $url = route('settings.pos.items.image.store', [$s['property']->code, $s['steak']->item->id]);
    $photo = fn () => UploadedFile::fake()->image('a.jpg', 100, 100);

    $this->actingAs($s['manager'])->post($url, ['image' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')])->assertSessionHasErrors('image');
    $this->actingAs($s['manager'])->post($url, ['image' => $photo()->size(6000)])->assertSessionHasErrors('image');
    $this->actingAs($s['manager'])->post(route('settings.pos.items.image.store', [$s['property']->code, $other['steak']->item->id]), ['image' => $photo()])->assertNotFound();
    $this->actingAs(User::factory()->for($s['property'])->withPermissions(['pos.sell'])->create())->post($url, ['image' => $photo()])->assertForbidden();

    expect($s['steak']->item->refresh()->imageUrl())->toBeNull();
});

it('saves the photo sent with a new item, and saves nothing when that photo is refused', function () {
    $s = posOrderSetup();
    $code = $s['property']->code;
    $item = fn (string $name, UploadedFile $image): array => [
        'name' => $name, 'pos_category_id' => $s['category']->id, 'price_includes_tax' => '0', 'service_charge_exempt' => '0', 'active' => '1',
        'variants' => [['name' => 'Regular', 'pricing_type' => 'fixed', 'price' => '9.00', 'active' => '1']],
        'image' => $image,
    ];

    $this->actingAs($s['manager'])->post(route('settings.pos.items.store', $code), $item('Teh Tarik', UploadedFile::fake()->image('teh.jpg', 600, 600)))->assertSessionHasNoErrors();

    expect(PosItem::query()->where('name', 'Teh Tarik')->sole()->imageUrl())->not->toBeNull();

    $this->actingAs($s['manager'])->post(route('settings.pos.items.store', $code), $item('Kopi', UploadedFile::fake()->create('kopi.pdf', 10, 'application/pdf')))->assertSessionHasErrors('image');

    expect(PosItem::query()->where('name', 'Kopi')->exists())->toBeFalse();
});
