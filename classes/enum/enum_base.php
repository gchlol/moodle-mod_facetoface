<?php

namespace mod_facetoface\enum;

use ReflectionClass;

abstract class enum_base {

    /**
     * Get all available constant values indexed by name.
     *
     * @return string[] Class constants.
     */
    public static function options(): array {
        $reflection = new ReflectionClass(static::class);

        return $reflection->getConstants();
    }
}