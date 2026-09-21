<?php

/**
 * This file is part of the Sprog package.
 *
 * @author (c) Thomas Blum <thomas@addoff.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sprog\Filter;

use Sprog\Filter;
use ValueError;

class Format extends Filter
{
    public function name(): string
    {
        return 'format';
    }

    public function fire(string $value, string $arguments): string
    {
        if ('' === $arguments) {
            return $value;
        }

        $args = self::splitArguments($arguments);

        // Mehr Platzhalter als Argumente (z.B. ein in der Inbox nachträglich
        // ergänztes %s) darf das Frontend nicht mit einem ValueError abschießen.
        // Auffüllen mit Leerstrings: jede Konvertierung enthält mindestens ein
        // '%', substr_count ist damit eine sichere Obergrenze; überzählige
        // Argumente ignoriert vsprintf. Was dann noch scheitert (z.B. nur
        // '%3$s' oder ein unbekannter Spezifizierer wie in '50%-Rabatt'),
        // fällt auf den unformatierten Wert zurück.
        $args = array_pad($args, substr_count($value, '%'), '');

        try {
            return vsprintf($value, $args);
        } catch (ValueError) {
            return $value;
        }
    }
}
