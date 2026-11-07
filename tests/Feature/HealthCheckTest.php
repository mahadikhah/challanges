<?php

it('boots the application and serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});
