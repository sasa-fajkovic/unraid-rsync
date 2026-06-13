<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the click-to-read help on the shared rsync-options renderer
 * (source/pages/_options_form.php).
 *
 * The contract under test: EVERY whitelisted rsync option (the keys in
 * Config::defaultRsyncOptions()) has a non-empty, plain-English description, and
 * the shared renderer actually emits that description plus a click-to-read "?"
 * affordance for it. This guards the help so that a future option added to the
 * whitelist WITHOUT a matching description fails CI here, in either place that
 * includes the partial (Global Settings defaults and the per-job options block),
 * since both call the same renderer.
 */
final class OptionsFormHelpTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The page partial is not loaded by the test bootstrap (it only loads the
        // include/ classes), so pull it in here. It is guarded by function_exists
        // wrappers, so requiring it is idempotent.
        require_once __DIR__ . '/../source/pages/_options_form.php';
    }

    public function testHelpMapCoversEveryWhitelistKey(): void
    {
        $help = ur_option_help();
        $whitelist = array_keys(Config::defaultRsyncOptions());

        foreach ($whitelist as $key) {
            $this->assertArrayHasKey(
                $key,
                $help,
                "Whitelisted rsync option '$key' has no help description in ur_option_help(). "
                . 'Every option control must have a click-to-read description; add one.'
            );
            $this->assertIsString($help[$key]);
            $this->assertNotSame(
                '',
                trim($help[$key]),
                "Help description for '$key' must not be empty."
            );
        }
    }

    public function testHelpMapHasNoUnknownKeys(): void
    {
        // The map must stay canonical: no stray keys that don't correspond to a
        // real whitelisted option (which would be dead help text).
        $help = ur_option_help();
        $whitelist = array_keys(Config::defaultRsyncOptions());

        foreach (array_keys($help) as $key) {
            $this->assertContains(
                $key,
                $whitelist,
                "ur_option_help() has a description for '$key', which is not a whitelisted option."
            );
        }
    }

    public function testHelpMapKeysMatchWhitelistExactly(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(Config::defaultRsyncOptions()),
            array_keys(ur_option_help())
        );
    }

    public function testEveryDescriptionNamesTheRsyncFlag(): void
    {
        // Each description states the actual rsync flag it maps to in parentheses,
        // e.g. "(-a)" or "(--delete)". A description missing that parenthetical
        // hint is probably incomplete, so assert the convention holds for all.
        foreach (ur_option_help() as $key => $text) {
            $this->assertMatchesRegularExpression(
                '/\((?:-{1,2}[A-Za-z][\w-]*(?:=[A-Z]+)?)\)/',
                $text,
                "Description for '$key' should name its rsync flag in parentheses, e.g. (-a) or (--delete=N)."
            );
        }
    }

    public function testDestructiveDescriptionsWarn(): void
    {
        // The two destructive options must caution the user (the word DELETE in
        // caps is the agreed cue used by the renderer/help).
        $help = ur_option_help();
        foreach (['delete', 'deleteExcluded'] as $key) {
            $this->assertStringContainsString(
                'DELETE',
                $help[$key],
                "Destructive option '$key' description should warn about deletion."
            );
        }
    }

    public function testRendererEmitsDescriptionAndAffordanceForEveryKey(): void
    {
        // End-to-end: render the shared partial and confirm that for every
        // whitelisted key it emits both a click-to-read "?" affordance (a button
        // with aria-controls pointing at the help block) and the help blockquote
        // itself, carrying the (escaped) description text.
        $html = $this->renderOptions(Config::defaultRsyncOptions(), 'global[defaultRsyncOptions]', 'ur_t1');
        $help = ur_option_help();

        foreach (array_keys(Config::defaultRsyncOptions()) as $key) {
            $helpId = 'ur_t1_' . $key . '_help';
            $this->assertStringContainsString(
                'aria-controls="' . $helpId . '"',
                $html,
                "Renderer did not emit a help affordance for option '$key'."
            );
            $this->assertStringContainsString(
                'id="' . $helpId . '"',
                $html,
                "Renderer did not emit a help block for option '$key'."
            );
            // The description text (HTML-escaped) is present in the output.
            $this->assertStringContainsString(
                htmlspecialchars($help[$key], ENT_QUOTES, 'UTF-8'),
                $html,
                "Renderer did not emit the description text for option '$key'."
            );
        }
    }

    public function testRendererPreservesFieldNamesAndRevealsHelpHidden(): void
    {
        // Purely-additive guarantee: the help UI must not change the form field
        // names/values, and the descriptions start hidden (revealed on click).
        $html = $this->renderOptions(Config::defaultRsyncOptions(), 'jobs[0][rsyncOptions]', 'ur_t2');

        // A representative boolean, a scalar, and a repeatable row keep their
        // exact POST names.
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][archive]"', $html);
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][bwlimit]"', $html);
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][excludes][]"', $html);

        // The help blockquotes are emitted collapsed (hidden) so we don't show
        // dozens of long descriptions at once.
        $this->assertStringContainsString('class="inline_help ur-help-text"', $html);
        $this->assertMatchesRegularExpression('/<blockquote class="inline_help ur-help-text"[^>]*\shidden>/', $html);
    }

    /**
     * The "emit once per page" guard is a per-request static, so it must run in a
     * fresh process to observe the first emit (other tests in this process would
     * otherwise have already consumed the guard). preserveGlobalState=false keeps
     * the child process clean; the partial is re-required by the bootstrap chain.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendererEmitsToggleAssetsExactlyOncePerPage(): void
    {
        require_once __DIR__ . '/../source/pages/_options_form.php';
        // The toggle CSS/JS is emitted once per page even when several option
        // blocks render (the Jobs tab renders one per card + the template).
        ob_start();
        ur_render_rsync_options(Config::defaultRsyncOptions(), 'jobs[0][rsyncOptions]', 'ur_p1');
        ur_render_rsync_options(Config::defaultRsyncOptions(), 'jobs[1][rsyncOptions]', 'ur_p2');
        $html = (string) ob_get_clean();

        // Count via whitespace-tolerant patterns so reformatting the emitted
        // CSS/JS (indentation, spacing around tokens) can't break the test as long
        // as the assets are still emitted exactly once.
        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urOptionHelpWired\s*=\s*true/', $html),
            'Help toggle JS must be emitted exactly once per page.'
        );
        $this->assertSame(
            1,
            preg_match_all('/\.ur-help-toggle\s*\{/', $html),
            'Help toggle CSS must be emitted exactly once per page.'
        );
    }

    /**
     * Render the shared options partial to a string.
     *
     * @param array<string,mixed> $opts
     */
    private function renderOptions(array $opts, string $prefix, string $idBase): string
    {
        ob_start();
        ur_render_rsync_options($opts, $prefix, $idBase);
        return (string) ob_get_clean();
    }
}
