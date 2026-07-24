<?php

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->get(route('dashboard'))
        ->assertRedirect(route('org.dashboard', $ctx['organization']));
});
