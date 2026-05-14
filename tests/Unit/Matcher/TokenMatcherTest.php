<?php

declare(strict_types=1);

namespace Sprog\Tests\Unit\Matcher;

use PHPUnit\Framework\TestCase;
use Sprog\Matcher\TokenMatcher;

final class TokenMatcherTest extends TestCase
{
    public function testReplaceInBodyWrapsMatchedTriggers(): void
    {
        $matcher = new TokenMatcher(
            ['HTTP' => 'Hypertext Transfer Protocol'],
            static fn (string $m, mixed $payload): string => sprintf('<abbr title="%s">%s</abbr>', (string) $payload, $m),
        );

        $html = '<html><body><p>HTTP rocks.</p></body></html>';

        self::assertStringContainsString(
            '<abbr title="Hypertext Transfer Protocol">HTTP</abbr>',
            $matcher->replaceInBody($html),
        );
    }

    public function testReplaceInBodyDoesNotTouchHead(): void
    {
        $matcher = new TokenMatcher(
            ['HTTP' => 'Hypertext Transfer Protocol'],
            static fn (string $m, mixed $payload): string => 'REPLACED',
        );

        $html = '<html><head><meta name="x" content="HTTP"></head><body><p>HTTP</p></body></html>';

        $result = $matcher->replaceInBody($html);

        // <head>-Content darf nicht verändert sein.
        self::assertStringContainsString('<meta name="x" content="HTTP">', $result);
        // <body>-Content schon.
        self::assertStringContainsString('<p>REPLACED</p>', $result);
    }

    public function testReplaceInBodyWithoutBodyTagReturnsInputUnchanged(): void
    {
        $matcher = new TokenMatcher(
            ['foo' => 'bar'],
            static fn (string $m, mixed $payload): string => 'CHANGED',
        );

        $fragment = '<div>foo bar</div>';

        // Kein <body>-Tag = AJAX-Fragment, wir lassen alles unverändert.
        self::assertSame($fragment, $matcher->replaceInBody($fragment));
    }

    public function testReplaceInBodyWithEmptyPatternsReturnsInputUnchanged(): void
    {
        $matcher = new TokenMatcher(
            [],
            static fn (string $m, mixed $payload): string => 'NEVER',
        );

        $html = '<html><body><p>egal</p></body></html>';
        self::assertSame($html, $matcher->replaceInBody($html));
    }

    public function testLongerTriggerWinsOverShortPrefix(): void
    {
        // Ohne Längen-Sortierung würde "WHO" matchen, bevor "WHOM" zum Zug käme.
        $matcher = new TokenMatcher(
            [
                'WHO'  => 'World Health Organization',
                'WHOM' => 'Werner Heisenbergs Old Mansion',
            ],
            static fn (string $m, mixed $payload): string => "[$m=$payload]",
        );

        $html = '<html><body>WHOM</body></html>';
        $out  = $matcher->replaceInBody($html);

        self::assertStringContainsString('[WHOM=Werner Heisenbergs Old Mansion]', $out);
        self::assertStringNotContainsString('[WHO=', $out);
    }

    public function testTriggerInsideTagAttributeIsNotMatched(): void
    {
        $matcher = new TokenMatcher(
            ['foo' => 'bar'],
            static fn (string $m, mixed $payload): string => 'REPLACED',
        );

        $html = '<html><body><a href="https://example.com/foo">Link</a></body></html>';
        $out  = $matcher->replaceInBody($html);

        // foo steckt im href-Attribut innerhalb eines Tags — darf nicht angefasst werden.
        self::assertStringContainsString('href="https://example.com/foo"', $out);
        self::assertStringNotContainsString('REPLACED', $out);
    }

    public function testTriggerMatchesOnlyAsWholeWordNotAsSubstring(): void
    {
        // Wortgrenzen-Pattern (\b…\b) ist by design — Trigger "API" matched
        // "API rocks", aber nicht "APIs", "RAPID" oder "SOAPI".
        $matcher = new TokenMatcher(
            ['API' => 'Application Programming Interface'],
            static fn (string $m, mixed $payload): string => "[$m]",
        );

        $html = '<html><body>API rocks, APIs differ, SOAPIs too.</body></html>';
        $out  = $matcher->replaceInBody($html);

        // Das Wort "API" allein wurde ersetzt …
        self::assertStringContainsString('[API] rocks', $out);
        // … "APIs" und "SOAPIs" als Bestandteil längerer Wörter aber nicht.
        self::assertStringContainsString('APIs differ', $out);
        self::assertStringContainsString('SOAPIs', $out);
        self::assertStringNotContainsString('[API]s', $out);
    }

    public function testReplaceOnEmptyContentIsNoOp(): void
    {
        $matcher = new TokenMatcher(
            ['foo' => 'bar'],
            static fn (string $m, mixed $payload): string => 'REPLACED',
        );

        self::assertSame('', $matcher->replace(''));
    }
}
