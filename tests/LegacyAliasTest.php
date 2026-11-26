<?php

declare(strict_types=1);

use Bliptrace\Php\Config;

it('resolves a legacy G8Scope\Php class name to the Bliptrace\Php class', function (): void {
    $deprecations = [];
    set_error_handler(function (int $no, string $msg) use (&$deprecations): bool {
        $deprecations[] = $msg;

        return true;
    }, E_USER_DEPRECATED);

    try {
        $legacy = 'G8Scope\\Php\\Config';
        $config = new $legacy(key: 'k', endpoint: 'http://x');
    } finally {
        restore_error_handler();
    }

    expect($config)->toBeInstanceOf(Config::class)
        ->and(class_exists('G8Scope\\Php\\DoesNotExist'))->toBeFalse()
        ->and($deprecations)->toHaveCount(1);
});
