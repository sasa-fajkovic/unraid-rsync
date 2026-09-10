<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards ONE markup invariant that only bites at runtime, in someone else's
 * JavaScript.
 *
 * An input named "action" inside a <form> becomes a NAMED PROPERTY of the form
 * element, so `form.action` returns that input instead of the URL string.
 * Unraid's own layout JS runs
 *
 *     $('form').each(function(){ $(this).prop('action').actionName(); ... })
 *
 * over every form on the page (BodyInlineJS, the escapeQuotes form parser,
 * with String.prototype.actionName defined in HeadInlineJS). With the property
 * shadowed that throws "$(...).prop(...).actionName is not a function" and
 * aborts the rest of Unraid's ready handler - which is where its
 * leave-confirmation guard is installed. Observed live on Unraid 7.3.2.
 *
 * So: the handler action travels in a data-ur-action attribute on the form
 * (postFormElement copies it into the POST body, keeping the wire protocol
 * identical) and never as an input named "action".
 */
final class FormActionTest extends TestCase
{
    /** @return list<string> */
    private function pageBodies(): array
    {
        $files = glob(__DIR__ . '/../source/pages/*.php');
        $this->assertNotEmpty($files, 'no page bodies found');
        return $files ?: [];
    }

    public function testNoPageFormCarriesAnInputNamedAction(): void
    {
        foreach ($this->pageBodies() as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]*\bname="action"/',
                (string) file_get_contents($file),
                basename($file) . ': an input named "action" shadows form.action and breaks Unraid\'s layout JS'
            );
        }
    }

    public function testEveryPostFormDeclaresItsHandlerActionAsADataAttribute(): void
    {
        $found = 0;
        foreach ($this->pageBodies() as $file) {
            // Match the whole LINE: these tags embed a short-echo tag for the
            // action URL, and its closing angle bracket ends a [^>]* scan early.
            // (Careful: a literal PHP close tag inside even a // comment really
            // does close the block - which is what broke this file once.)
            $src   = (string) file_get_contents($file);
            $lines = preg_grep('/<form\b[^\n]*method="POST"/i', explode("\n", $src));
            if (!$lines) {
                continue;
            }
            foreach ($lines as $tag) {
                $found++;
                $this->assertMatchesRegularExpression(
                    '/data-ur-action="[a-zA-Z]+"/',
                    $tag,
                    basename($file) . ': a POST form must carry data-ur-action'
                );
            }
        }
        $this->assertGreaterThanOrEqual(4, $found, 'expected the jobs/settings/connections/credentials forms');
    }

    public function testPostFormElementSendsTheDataAttributeAsTheActionField(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../source/pages/_options_form.php');
        $this->assertStringContainsString("form.getAttribute('data-ur-action')", $src);
        $this->assertStringContainsString("params.set('action', act)", $src);
        // The URL must come from the attribute too, for the same shadowing reason.
        $this->assertStringContainsString("form.getAttribute('action')", $src);
    }
}
