<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests\Recorder\Redactor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Recorder\Redactor\DefaultRedactor;

class DefaultRedactorTest extends TestCase
{
    public function testRedactUrlMasksDenyListedQueryParamsOnly()
    {
        $redactor = new DefaultRedactor();

        $this->assertSame(
            'https://example.com/path?foo=bar&token=%5BREDACTED%5D',
            $redactor->redactUrl('https://example.com/path?foo=bar&token=secret')
        );
    }

    public function testRedactUrlLeavesUrlWithoutQueryUntouched()
    {
        $redactor = new DefaultRedactor();

        $this->assertSame('https://example.com/path', $redactor->redactUrl('https://example.com/path'));
    }

    public function testRedactUrlIsDeterministic()
    {
        $redactor = new DefaultRedactor();
        $url = 'https://example.com/path?token=secret&foo=bar';

        $this->assertSame($redactor->redactUrl($url), $redactor->redactUrl($url));
    }

    public function testRedactHeadersMasksDenyListedHeadersOnly()
    {
        $redactor = new DefaultRedactor();

        $redacted = $redactor->redactHeaders([
            'authorization' => ['Bearer secret'],
            'x-custom' => ['keep-me'],
        ]);

        $this->assertSame(['[REDACTED]'], $redacted['authorization']);
        $this->assertSame(['keep-me'], $redacted['x-custom']);
    }

    public function testRedactBodyMasksDenyListedJsonFieldsOnly()
    {
        $redactor = new DefaultRedactor();

        $redacted = $redactor->redactBody(json_encode(['username' => 'bob', 'password' => 'hunter2']));
        $decoded = json_decode($redacted, true);

        $this->assertSame('bob', $decoded['username']);
        $this->assertSame('[REDACTED]', $decoded['password']);
    }

    public function testRedactBodyLeavesNonJsonBodyUntouched()
    {
        $redactor = new DefaultRedactor();

        $this->assertSame('not-json=1', $redactor->redactBody('not-json=1'));
    }

    public function testRedactBodyLeavesNullAndEmptyUntouched()
    {
        $redactor = new DefaultRedactor();

        $this->assertNull($redactor->redactBody(null));
        $this->assertSame('', $redactor->redactBody(''));
    }
}
