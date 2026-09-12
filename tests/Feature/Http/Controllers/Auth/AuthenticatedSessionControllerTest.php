<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests from the import page to login', function () {
    $this->get(route('home'))->assertRedirectToRoute('login');
});

it('shows the login page to guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('authenticates a user with valid credentials', function () {
    $this->seed();

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->post(route('login.store'), [
        'email' => 'test@example.com',
        'password' => 'password',
    ])->assertRedirectToRoute('home');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $this->from(route('login'))->post(route('login.store'), [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);

    $this->assertGuest();
});

it('logs out an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirectToRoute('login');

    $this->assertGuest();
});
