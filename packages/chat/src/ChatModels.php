<?php

namespace Mainstay\Chat;

use Mainstay\Facades\Mainstay;

/*
 | The models the owner picked on the Settings screen, both on the site's
 | default text provider and its key. Read as plain text and checked again
 | here, since a stored value can be anything:
 |
 |   neither set -> the provider's default model, nothing to hand over to
 |   one set     -> that model for everything, nothing to hand over to
 |   both set    -> every message starts on the everyday model, which can hand
 |                  the turn over to the stronger one
 */
final class ChatModels
{
    /* The same rule as the connector's model field: letters, digits and the punctuation model ids use. */
    public const PATTERN = '/\A[\w.:\/@~+-]+\z/';

    public const MAX_LENGTH = 255;

    public static function starting(): ?string
    {
        return self::everyday() ?? self::stronger();
    }

    /* The model a hand-over goes to, or null where there is none to hand over to. */
    public static function handOverTarget(): ?string
    {
        $everyday = self::everyday();
        $stronger = self::stronger();

        return $everyday !== null && $stronger !== null && $everyday !== $stronger ? $stronger : null;
    }

    private static function everyday(): ?string
    {
        return self::stored(ChatPlugin::EVERYDAY_MODEL);
    }

    private static function stronger(): ?string
    {
        return self::stored(ChatPlugin::STRONGER_MODEL);
    }

    private static function stored(string $key): ?string
    {
        $value = Mainstay::setting($key);

        if (! is_string($value)) {
            return null;
        }

        $model = trim($value);

        return $model !== '' && strlen($model) <= self::MAX_LENGTH && preg_match(self::PATTERN, $model) === 1 ? $model : null;
    }
}
