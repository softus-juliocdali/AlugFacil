<?php

declare(strict_types=1);

return [
    'DB_DRIVER' => getenv('DB_DRIVER') !== false ? getenv('DB_DRIVER') : '',
    'DB_HOST' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : '',
    'DB_PORT' => getenv('DB_PORT') !== false ? getenv('DB_PORT') : '',
    'DB_NAME' => getenv('DB_NAME') !== false ? getenv('DB_NAME') : '',
    'DB_USER' => getenv('DB_USER') !== false ? getenv('DB_USER') : '',
    'DB_PASS' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'DB_CHARSET' => getenv('DB_CHARSET') !== false ? getenv('DB_CHARSET') : '',
];
