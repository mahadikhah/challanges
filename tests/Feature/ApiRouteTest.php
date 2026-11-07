<?php

it('serves the versioned API ping as JSON', function () {
    $this->getJson('/api/v1/ping')
        ->assertOk()
        ->assertExactJson(['ok' => true, 'surface' => 'api']);
});
