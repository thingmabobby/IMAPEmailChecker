<?php

declare(strict_types=1);

namespace IMAPEmailChecker\Tests;

use IMAPEmailChecker\IMAPEmailChecker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class HelpersTest extends TestCase
{
    private IMAPEmailChecker $checker;
    private ReflectionClass $ref;


    protected function setUp(): void
    {
        // 1) Reflect the IMAPEmailChecker class
        $this->ref = new ReflectionClass(IMAPEmailChecker::class);

        // 2) Instantiate WITHOUT invoking the constructor since it was problematic mocking a resource or IMAP\Connection depending on PHP version
        $this->checker = $this->ref->newInstanceWithoutConstructor();

        // 3) Manually initialize the private $debug property (so it's not uninitialized)
        $pDebug = $this->ref->getProperty('debug');
        $pDebug->setAccessible(true);
        $pDebug->setValue($this->checker, false);

        // 4) Manually initialize the private $bidRegex property (some helpers reference it)
        $pRegex = $this->ref->getProperty('bidRegex');
        $pRegex->setAccessible(true);
        $pRegex->setValue($this->checker, '/#\s*(\d+)/');
    }


    /**
     * Helper to invoke private/protected methods
     *
     * @param string $methodName
     * @return callable
     */
    private function getInvoker(string $methodName): callable
    {
        $m = $this->ref->getMethod($methodName);
        $m->setAccessible(true);
        return fn (...$args) => $m->invoke($this->checker, ...$args);
    }


    public function testGetMimeTypeString(): void
    {
        $invoke = $this->getInvoker('getMimeTypeString');

        $this->assertSame('text/html', $invoke(0, 'html'));
        $this->assertSame('image/png', $invoke(5, 'PNG'));
        $this->assertSame('application/octet-stream', $invoke(3, ''));
        $this->assertSame('multipart', $invoke(1, ''));
        $this->assertSame('application/octet-stream', $invoke(999, ''));
    }


    public function testFormatAddress(): void
    {
        $invoke = $this->getInvoker('formatAddress');

        $valid = new \stdClass();
        $valid->mailbox = 'joe';
        $valid->host    = 'example.com';
        $this->assertSame('joe@example.com', $invoke($valid));

        $missingMailbox = new \stdClass();
        $missingMailbox->mailbox = '';
        $missingMailbox->host    = 'ex.com';
        $this->assertSame('', $invoke($missingMailbox));

        $missingHost = new \stdClass();
        $missingHost->mailbox = 'joe';
        $missingHost->host    = '';
        $this->assertSame('', $invoke($missingHost));
    }


    public function testFormatAddressList(): void
    {
        $invoke = $this->getInvoker('formatAddressList');

        // Null input → empty array
        $this->assertSame([], $invoke(null));

        // Mixed valid/invalid
        $ok  = new \stdClass(); $ok->mailbox = 'a'; $ok->host = 'b.com';
        $bad = new \stdClass(); $bad->mailbox = '';  $bad->host = '';
        $this->assertSame(['a@b.com'], $invoke([$ok, $bad]));
    }


    public function testEmbedInlineImagesNoChangeWhenNoCid(): void
    {
        $invoke = $this->getInvoker('embedInlineImages');
        $html   = '<img src="foo.png">';
        $this->assertSame($html, $invoke($html, []));
    }


    public function testEmbedInlineImagesReplacesCid(): void
    {
        $invoke = $this->getInvoker('embedInlineImages');
        $html   = '<img src="cid:123">';
        $attachments = [
            [
                'content_id' => '123',
                'disposition'=> 'inline',
                'mime_type'  => 'image/png',
                'content'    => 'raw-bytes',
            ],
            [
                'content_id' => '999',
                'disposition'=> 'inline',
                'mime_type'  => 'image/jpeg',
                'content'    => 'jpeg-bytes',
            ],
        ];

        $out = $invoke($html, $attachments);
        $this->assertStringStartsWith('<img src="data:image/png;base64,', $out);
        $this->assertStringContainsString(base64_encode('raw-bytes'), $out);
        $this->assertStringEndsWith('">', $out);
    }


    public function testDecodeHeaderValue(): void
    {
        $invoke = $this->getInvoker('decodeHeaderValue');

        // Null or empty
        $this->assertSame('', $invoke(null));
        $this->assertSame('', $invoke(''));

        // Plain ASCII
        $plain = 'Hello World';
        $this->assertSame($plain, $invoke($plain));

        // MIME-encoded (UTF-8 base64)
        $encoded = '=?UTF-8?B?SGVsbG8gw6TDtsO8?='; // "Hello àô"
        $decoded = $invoke($encoded);
        $this->assertStringStartsWith('Hello ', $decoded);
        $this->assertStringContainsString('ä', $decoded);
        $this->assertStringContainsString('ö', $decoded);
        $this->assertStringContainsString('ü', $decoded);
    }
}