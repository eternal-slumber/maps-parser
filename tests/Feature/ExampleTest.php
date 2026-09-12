<?php

use Inertia\Testing\AssertableInertia as Assert;

test('shows the organization import page', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Organizations/Index'));
});
