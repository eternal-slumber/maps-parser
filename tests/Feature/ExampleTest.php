<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the organization import page', function () {
    $user = User::factory()->create();
    $organization = $user->organizations()->create([
        'source_url' => 'https://yandex.ru/maps/org/137381017899/',
        'business_id' => '137381017899',
        'name' => 'Тёрки',
        'sync_status' => 'completed',
    ]);

    $response = $this->actingAs($user)->get(route('home'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Index')
            ->where('initialOrganization.id', $organization->id)
            ->where('initialOrganization.name', 'Тёрки'));
});
