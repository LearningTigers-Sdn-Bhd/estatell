<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('pages share the English user docs link', function () {
    config(['estatell.docs_url' => 'https://docs.example.test/']);

    $this->actingAs(User::factory()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('docsUrl', 'https://docs.example.test/en/docs'));
});
