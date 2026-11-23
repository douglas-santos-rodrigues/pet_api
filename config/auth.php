<?php

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'tutores'],
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'tutores']],
    'providers' => ['tutores' => ['driver' => 'eloquent', 'model' => App\Models\Tutor::class]],
    'passwords' => ['tutores' => ['provider' => 'tutores', 'table' => 'password_reset_tokens', 'expire' => 30, 'throttle' => 60]],
    'password_timeout' => 10800,
];
