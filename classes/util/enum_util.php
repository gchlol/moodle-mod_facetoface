<?php

namespace mod_facetoface\util;

use coding_exception;
use mod_facetoface\enum\enum_base;

class enum_util {

    /**
     * Create a list of menu options from a given enum class.
     *
     * @param class-string<enum_base> $class Enum class. Must extend {@link enum_base}.
     * @return array<mixed, string> List of menu options indexed by enum value.
     * @throws coding_exception
     */
    public static function menu_options(string $class, ?string $lang_identifier = null): array {
        if ($lang_identifier === null) {
            $class_parts = explode('\\', $class);

            $lang_identifier = str_replace('_', '', strtolower(end($class_parts)));
        }

        $menu_options = [];
        foreach ($class::options() as $value) {
            $menu_options[$value] = get_string("$lang_identifier:$value", 'mod_facetoface');
        }

        return $menu_options;
    }
}