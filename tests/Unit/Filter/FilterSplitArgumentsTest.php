<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sprog\Filter;

final class FilterSplitArgumentsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideArguments')]
    public function testSplitArguments(string $arguments, array $expected): void
    {
        self::assertSame($expected, Filter::splitArguments($arguments));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideArguments(): iterable
    {
        yield 'README-Beispiel, Leerzeichen nach dem Komma' => ['5, Baum', ['5', 'Baum']];
        yield 'Whitespace rundum' => ['  a ,b  , c ', ['a', 'b', 'c']];
        yield 'einzelnes Argument' => ['Baum', ['Baum']];
        yield 'leerer String' => ['', ['']];
        yield 'leere Argumente bleiben erhalten' => ['a,,b', ['a', '', 'b']];
        yield 'leeres erstes Argument' => [',x', ['', 'x']];
        yield 'leeres letztes Argument' => ['a,', ['a', '']];
        yield 'Komma in doppelten Anführungszeichen' => ['a title="x, y"', ['a title="x, y"']];
        yield 'Komma in Klammern' => ['f(1, 2), 3', ['f(1, 2)', '3']];
        yield 'verschachtelte Klammern' => ['(a, (b, c)), d', ['(a, (b, c))', 'd']];
        yield 'HTML mit onclick aus Issue #101' => [
            '<a href="/x" onclick="window.open(\'/x\',\'popup\',\'width=760,height=600\'); return false;">, </a>',
            ['<a href="/x" onclick="window.open(\'/x\',\'popup\',\'width=760,height=600\'); return false;">', '</a>'],
        ];
        yield 'unbalanciertes Anführungszeichen (Zollzeichen) ist Literal' => ['5" Rohr, 3', ['5" Rohr', '3']];
        yield 'Apostroph ist kein Quoting' => ["Tom's, Baum", ["Tom's", 'Baum']];
        yield 'unbalancierte Klammer ist Literal' => ['5 (Stück, 3', ['5 (Stück', '3']];
        yield 'schließende vor öffnender Klammer' => ['a), (b', ['a)', '(b']];
        yield 'Multibyte-Inhalt bleibt unangetastet' => ['Äpfel, Bäume', ['Äpfel', 'Bäume']];
    }
}
