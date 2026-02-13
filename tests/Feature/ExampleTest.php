<?php

test('home redirects to dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/dashboard');
});
