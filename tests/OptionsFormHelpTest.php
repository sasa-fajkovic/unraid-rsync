<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the native inline help on the shared rsync-options renderer
 * (source/pages/_options_form.php).
 *
 * The contract under test: EVERY whitelisted rsync option (the keys in
 * Config::defaultRsyncOptions()) has a non-empty, plain-English description, and
 * the shared renderer actually emits that description plus a "?" help affordance
 * for it that toggles a native blockquote.inline_help box. This guards the help
 * so that a future option added to the whitelist WITHOUT a matching description
 * fails CI here, in either place that includes the partial (Global Settings
 * defaults and the per-job options block), since both call the same renderer.
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
        // whitelisted key it emits both a "?" help affordance (with aria-controls
        // pointing at the help block) and the help blockquote itself, carrying the
        // (escaped) description text.
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

    public function testHelpAffordanceIsNativeIconNotAButton(): void
    {
        // Regression guard: the "?" affordance must be a lightweight native icon
        // (a <span role="button" class="ur-help">), NOT a <button>. The webGui base
        // stylesheet renders every <button> as a large bordered uppercase pill,
        // which is exactly what made the previous affordance look like a big
        // "orange HELP button". Keep it a span so it stays a subtle icon.
        $html = $this->renderOptions(Config::defaultRsyncOptions(), 'global[defaultRsyncOptions]', 'ur_t3');

        // The affordance is the native icon span...
        $this->assertMatchesRegularExpression(
            '/<span class="ur-help" role="button"[^>]*aria-controls="/',
            $html,
            'The "?" help affordance must render as <span class="ur-help" role="button">.'
        );
        // ...and the old heavy <button> affordance must be gone.
        $this->assertStringNotContainsString(
            'ur-help-toggle',
            $html,
            'The old <button class="ur-help-toggle"> affordance must not be emitted.'
        );
    }

    public function testHelpBoxUsesNativeInlineHelpBlockquote(): void
    {
        // The revealed help must use Unraid's native blue callout element: a
        // <blockquote class="inline_help …"> — the same markup Markdown.php emits
        // for a "> help" line — so it inherits the stock blue box styling instead
        // of any bespoke widget.
        $html = $this->renderOptions(Config::defaultRsyncOptions(), 'global[defaultRsyncOptions]', 'ur_t4');
        $this->assertMatchesRegularExpression(
            '/<blockquote class="inline_help ur-help-text" id="ur_t4_archive_help">/',
            $html,
            'Help text must render inside a native blockquote.inline_help box.'
        );
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

        // The descriptions render in the NATIVE Unraid help element: a
        // blockquote.inline_help blue box. The base dynamix stylesheet ships
        // `.inline_help { display:none }`, so each block starts collapsed without
        // any inline attribute; the "?" affordance reveals it by adding `ur-open`.
        // The blocks must therefore NOT carry the `ur-open` class at render time.
        $this->assertStringContainsString('class="inline_help ur-help-text"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<blockquote class="[^"]*\bur-open\b[^"]*"/',
            $html,
            'Help blockquotes must start collapsed (no ur-open class until clicked).'
        );
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
        // Count a selector that appears exactly once within the emitted CSS block
        // (the reveal rule). Counting `.ur-help {` would over-count because the
        // touch-device `@media (hover:none)` query repeats that selector.
        $this->assertSame(
            1,
            preg_match_all('/\.ur-help-text\.ur-open\s*\{/', $html),
            'Help affordance CSS must be emitted exactly once per page.'
        );
    }

    /**
     * Regression for the "help dead on a fresh install" bug: on the Jobs tab
     * with NO configured jobs, the help CSS/JS must be emitted in LIVE page DOM,
     * NOT trapped inside the hidden <script type="text/html"> job template.
     *
     * Previously the first ur_render_rsync_options() call on a fresh install was
     * the one INSIDE #ur-job-template (no live job cards rendered before it), so
     * the once-guarded assets were emitted only as inert text inside that
     * template — `window.urOptionHelpWired` never ran and the help CSS never
     * applied. The page body now emits the assets explicitly at the top, before
     * any template. This test renders the real jobs.php body (empty config) and
     * asserts the `urOptionHelpWired` marker appears OUTSIDE the template block.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJobsPageEmitsHelpAssetsLiveNotTrappedInTemplate(): void
    {
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/jobs.php');

        // The help JS marker must be present at all...
        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urOptionHelpWired\s*=\s*true/', $html),
            'jobs.php must emit the help toggle JS exactly once.'
        );
        // ...and it must appear BEFORE the hidden job template, i.e. in live DOM,
        // not only inside the inert <script type="text/html"> block.
        $marker = strpos($html, 'urOptionHelpWired');
        $tpl    = strpos($html, '<script type="text/html" id="ur-job-template">');
        $this->assertNotFalse($marker, 'help JS marker not found in jobs.php output.');
        $this->assertNotFalse($tpl, 'job template not found in jobs.php output.');
        $this->assertLessThan(
            $tpl,
            $marker,
            'Help JS must be emitted in live page DOM BEFORE the <script type="text/html"> template '
            . '(on a fresh install with no job cards it must not be trapped inside the inert template).'
        );
        // And the reveal CSS rule must be present in live DOM too.
        $cssPos = strpos($html, '.ur-help-text.ur-open');
        $this->assertNotFalse($cssPos, 'help reveal CSS (.ur-help-text.ur-open) not found in jobs.php output.');
        $this->assertLessThan($tpl, $cssPos, 'Help reveal CSS must be emitted in live DOM before the template.');
    }

    /**
     * The Global Settings tab body must also emit the help assets in live DOM
     * (it renders only one option block and no template, but the assets must be
     * present whether this body is rendered alone or after the Jobs tab).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSettingsPageEmitsHelpAssetsLive(): void
    {
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/settings.php');
        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urOptionHelpWired\s*=\s*true/', $html),
            'settings.php must emit the help toggle JS exactly once.'
        );
        $this->assertStringContainsString(
            '.ur-help-text.ur-open',
            $html,
            'settings.php must emit the help reveal CSS.'
        );
    }

    /**
     * The shared robust-fetch helpers (window.urAjax) must be emitted exactly once
     * per page by ur_emit_ajax_helpers(), mirroring the option-help once-guard.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAjaxHelpersEmittedExactlyOncePerPage(): void
    {
        require_once __DIR__ . '/../source/pages/_options_form.php';
        ob_start();
        ur_emit_ajax_helpers();
        ur_emit_ajax_helpers(); // second call must be a no-op (static guard)
        $html = (string) ob_get_clean();

        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urAjax\s*=/', $html),
            'Shared AJAX helpers (window.urAjax) must be emitted exactly once per page.'
        );
        // The helper surface the consumers rely on must be present.
        foreach (['postForm', 'postFormElement', 'errText', 'show', 'parseResponse'] as $fn) {
            $this->assertStringContainsString($fn, $html, "window.urAjax must expose $fn().");
        }
    }

    /**
     * The Jobs tab's two STATE-CHANGING AJAX handlers (the per-job run/dry/abort
     * action and the save-form submit — review finding #1's scope) must go through
     * the shared robust helpers so a non-JSON 403/500 surfaces WITH its HTTP
     * status instead of throwing in r.json() as a silent "Network error". (The
     * read-only GET pollers — status/log tails — are out of that finding's scope
     * and keep their simple r.json() reads, so we assert the POST path's helpers
     * are present rather than a blanket absence of r.json().)
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJobsPageUsesRobustAjaxHelpers(): void
    {
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/jobs.php');
        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urAjax\s*=/', $html),
            'jobs.php must emit the shared AJAX helpers exactly once.'
        );
        // The run/dry/abort action uses urAjax.postForm; the save submit uses
        // urAjax.postFormElement; both report failures via urAjax.errText.
        $this->assertStringContainsString('window.urAjax.postForm(', $html,
            'jobs.php run/dry/abort must POST via window.urAjax.postForm.');
        $this->assertStringContainsString('window.urAjax.postFormElement(', $html,
            'jobs.php save must POST via window.urAjax.postFormElement.');
        $this->assertStringContainsString('window.urAjax.errText', $html,
            'jobs.php must surface non-JSON errors via window.urAjax.errText.');
    }

    /**
     * The Global Settings tab must likewise emit + use the shared AJAX helpers and
     * drop the brittle r.json() parse. Regression guard for review finding #1.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSettingsPageUsesRobustAjaxHelpers(): void
    {
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/settings.php');
        $this->assertSame(
            1,
            preg_match_all('/window\s*\.\s*urAjax\s*=/', $html),
            'settings.php must emit the shared AJAX helpers exactly once.'
        );
        $this->assertStringContainsString('window.urAjax.postFormElement(', $html,
            'settings.php save must POST via window.urAjax.postFormElement.');
        $this->assertStringContainsString('window.urAjax.errText', $html,
            'settings.php must surface non-JSON errors via window.urAjax.errText.');
        // settings.php has only the one save handler, so the brittle r.json()
        // parse must be gone entirely from it.
        $this->assertDoesNotMatchRegularExpression(
            '/\.then\(\s*function\s*\(\s*r\s*\)\s*\{\s*return\s+r\.json\(\)/',
            $html,
            'settings.php must not parse responses with the brittle r.json() pattern.'
        );
    }

    // --- CQ-03: shared CSRF-token resolver ---------------------------------

    public function testRenderCsrfTokenReadsVarGlobalAndDefaultsEmpty(): void
    {
        $prev = $GLOBALS['var'] ?? null;
        try {
            $GLOBALS['var'] = ['csrf_token' => 'tok-123'];
            $this->assertSame('tok-123', ur_render_csrf_token());

            // Missing/empty -> '' (a bare preview where the front controller
            // never populated $var).
            $GLOBALS['var'] = ['csrf_token' => ''];
            $this->assertSame('', ur_render_csrf_token());
            unset($GLOBALS['var']);
            $this->assertSame('', ur_render_csrf_token());
        } finally {
            if ($prev === null) {
                unset($GLOBALS['var']);
            } else {
                $GLOBALS['var'] = $prev;
            }
        }
    }

    // --- SEC-05: script-context JSON hardening ------------------------------

    public function testUrJsEscapesScriptBreakingCharacters(): void
    {
        // A value that would otherwise close the <script> element or break out of
        // a JS string must be \u-escaped, never emitted raw.
        $out = ur_js('</script><svg onload=alert(1)>');
        $this->assertStringNotContainsString('</script>', $out);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);

        $out2 = ur_js('a"b\'c&d');
        $this->assertStringNotContainsString('"b', $out2); // the inner double-quote is hex-escaped
        $this->assertStringNotContainsString('&', $out2);
        $this->assertStringNotContainsString("'", $out2);

        // ...and a normal token still round-trips as valid JSON.
        $this->assertSame('plain-token', json_decode(ur_js('plain-token')));
    }

    public function testPageBodiesEmitCsrfViaUrJsNotRawJsonEncode(): void
    {
        // Defence-in-depth regression: the inline-script HANDLER/CSRF vars must go
        // through ur_js() (HEX flags), never a bare json_encode().
        foreach (['jobs.php', 'status.php', 'credentials.php'] as $page) {
            $src = (string) file_get_contents(__DIR__ . '/../source/pages/' . $page);
            $this->assertDoesNotMatchRegularExpression(
                '/=\s*json_encode\(\$(?:csrf|handlerUrl)\b/',
                $src,
                "$page must emit the CSRF/handler JS vars via ur_js(), not raw json_encode()."
            );
        }
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

    /**
     * Render a real .php page BODY to a string in this (separate) process.
     *
     * The page bodies hard-code their install-path require_once lines
     * (/usr/local/emhttp/plugins/unraid.rsync/...), which don't exist under the
     * test harness — and the include/ classes plus the shared partial are already
     * loaded by the bootstrap chain. We strip only those install-path requires
     * (keeping the rest of the body verbatim) and include the remainder, so the
     * test exercises the genuine page render ORDER (the thing the bug is about)
     * without a live webGui or the install directory.
     *
     * The transformed source is written to a temp .php file and include()d rather
     * than eval()'d: include keeps PHP parsing behaviour identical to a real file,
     * gives readable file/line numbers if the body ever errors, and sidesteps any
     * null-on-failure surprises from preg_replace().
     */
    private function renderPageBody(string $pageFile): string
    {
        require_once __DIR__ . '/../source/pages/_options_form.php';

        $src = file_get_contents($pageFile);
        $this->assertIsString($src, "could not read page body: $pageFile");
        // Remove the install-path require_once lines (already satisfied by
        // bootstrap). Keep the leading <?php so the temp file parses as a normal
        // PHP file. assertIsString guards against preg_replace() returning null.
        $src = preg_replace(
            "#^\\s*require_once\\s+'/usr/local/emhttp/plugins/unraid\\.rsync/[^']+';\\s*$#m",
            '',
            $src
        );
        $this->assertIsString($src, 'preg_replace() failed transforming page body.');

        // tempnam() makes a unique base file; give the include a .php name and
        // clean up BOTH the base and the .php variant in the finally.
        $base = tempnam(sys_get_temp_dir(), 'ur_page_');
        $this->assertIsString($base, 'could not create temp file for page body.');
        $tmp = $base . '.php';
        $this->assertNotFalse(file_put_contents($tmp, $src), "could not write temp page body: $tmp");
        try {
            ob_start();
            include $tmp;
            return (string) ob_get_clean();
        } finally {
            @unlink($tmp);
            @unlink($base);
        }
    }
}
