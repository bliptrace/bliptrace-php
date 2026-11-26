<?php

declare(strict_types=1);

/*
 * Legacy shim: 1.x shipped under the G8Scope\Php namespace. Any G8Scope\Php\*
 * class is aliased to its Bliptrace\Php\* replacement on first use, with one
 * E_USER_DEPRECATED per process. Removed in 3.0.
 */
spl_autoload_register(static function (string $class): void {
    static $warned = false;

    if (! str_starts_with($class, 'G8Scope\\Php\\')) {
        return;
    }

    $target = 'Bliptrace\\Php\\'.substr($class, strlen('G8Scope\\Php\\'));
    if (! class_exists($target) && ! interface_exists($target) && ! trait_exists($target) && ! enum_exists($target)) {
        return;
    }

    if (! $warned) {
        $warned = true;
        @trigger_error('The G8Scope\\Php namespace is deprecated since bliptrace/php 2.0 and will be removed in 3.0; use Bliptrace\\Php.', E_USER_DEPRECATED);
    }

    class_alias($target, $class);
});
