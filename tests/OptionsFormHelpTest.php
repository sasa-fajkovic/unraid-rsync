<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

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

        // A representative boolean, a scalar, and a filter row keep their exact
        // POST names. The filter row posts as PARALLEL type[]/pattern[] arrays
        // (paired by index) so the reorder buttons never renumber field names -
        // see Config::normalizeFilters().
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][archive]"', $html);
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][bwlimit]"', $html);
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][filters][type][]"', $html);
        $this->assertStringContainsString('name="jobs[0][rsyncOptions][filters][pattern][]"', $html);

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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
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

        // The HEX flags are actually in effect: a bare json_encode would leave
        // & and ' raw and emit the plain escape for the double-quote. Assert the
        // uXXXX hex escapes appear (matched without a backslash) + a round-trip.
        $val  = 'a"b' . "'" . 'c&d';
        $out2 = ur_js($val);
        $this->assertStringContainsString('u0022', $out2); // double-quote -> hex (JSON_HEX_QUOT)
        $this->assertStringContainsString('u0027', $out2); // apostrophe  -> hex (JSON_HEX_APOS)
        $this->assertStringContainsString('u0026', $out2); // ampersand   -> hex (JSON_HEX_AMP)
        $this->assertStringNotContainsString('&', $out2);  // never raw
        $this->assertSame($val, json_decode($out2));       // escapes are valid

        // ...and a normal token still round-trips as valid JSON.
        $this->assertSame('plain-token', json_decode(ur_js('plain-token')));
    }

    public function testPageBodiesEmitCsrfViaUrJsNotRawJsonEncode(): void
    {
        // Defence-in-depth regression: the inline-script HANDLER/CSRF vars must go
        // through ur_js() (HEX flags), never a bare json_encode().
        foreach (['jobs.php', 'status.php', 'credentials.php'] as $page) {
            $src = file_get_contents(__DIR__ . '/../source/pages/' . $page);
            $this->assertIsString($src, "could not read $page");
            $this->assertDoesNotMatchRegularExpression(
                '/=\s*json_encode\(\$(?:csrf|handlerUrl)\b/',
                $src,
                "$page must emit the CSRF/handler JS vars via ur_js(), not raw json_encode()."
            );
        }
    }

    public function testHistoryPageRendersPaginatedTableForConfiguredJob(): void
    {
        // Seed one job, render the History tab body, and confirm it wires the
        // job filter + paginated table + listHistory poller + log modal. Clean up
        // the temp config afterwards so other tests see a fresh default.
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'id'        => 'j-hist-ui',
            'name'      => 'My History Job',
            'transport' => 'LOCAL',
            'pairs'     => [['local' => '/mnt/user/x/', 'remote' => '/mnt/disk1/x/']],
        ]);
        Config::save($cfg);
        try {
            $html = $this->renderPageBody(__DIR__ . '/../source/pages/history.php');
            // Job filter carries the configured job (escaped name present).
            $this->assertStringContainsString('id="ur-hist-job"', $html);
            $this->assertStringContainsString('My History Job', $html);
            // Paginated table + the read-only listHistory poller + pager controls.
            $this->assertStringContainsString('id="ur-hist-rows"', $html);
            $this->assertStringContainsString('action=listHistory', $html);
            $this->assertStringContainsString('id="ur-hist-prev"', $html);
            $this->assertStringContainsString('id="ur-hist-next"', $html);
            // Log modal opens a run's log via getJobLog?run=.
            $this->assertStringContainsString('action=getJobLog', $html);
            $this->assertStringContainsString('id="ur-hist-modal"', $html);
        } finally {
            @unlink(Config::path());
        }
    }

    public function testHistoryPageShowsEmptyStateWithNoJobs(): void
    {
        @unlink(Config::path()); // ensure no jobs
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/history.php');
        $this->assertStringContainsString('No jobs configured yet', $html);
        // The table/poller must NOT render in the empty state.
        $this->assertStringNotContainsString('action=listHistory', $html);
    }

    public function testOverviewPageRendersJobRowsAndPoller(): void
    {
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'id'        => 'j-ov',
            'name'      => 'Overview Job',
            'transport' => 'LOCAL',
            'pairs'     => [['local' => '/mnt/user/x/', 'remote' => '/mnt/disk1/x/']],
        ]);
        Config::save($cfg);
        try {
            $html = $this->renderPageBody(__DIR__ . '/../source/pages/overview.php');
            // One skeleton row per job, keyed by id, plus the live getStatus poll.
            $this->assertStringContainsString('data-jobid="j-ov"', $html);
            $this->assertStringContainsString('Overview Job', $html);
            $this->assertStringContainsString('action=getStatus', $html);
            $this->assertStringContainsString('id="ur-ov-rows"', $html);
        } finally {
            @unlink(Config::path());
        }
    }

    public function testOverviewPageShowsEmptyStateWithNoJobs(): void
    {
        @unlink(Config::path());
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/overview.php');
        $this->assertStringContainsString('No jobs configured yet', $html);
        $this->assertStringNotContainsString('action=getStatus', $html);
    }

    // --- global.secretsDir must stay visible on page load (not just writes) ---
    //
    // Regression for: a custom Secrets directory (global.secretsDir) makes the
    // handler write keys/connections to the configured /mnt path (handler.php
    // pushes Credentials::$secretsDirOverride before every AJAX action - see
    // handler.php's ur_dispatch(), around the "$urSecretsDir = Config::
    // secretsDir()" line), but a plain page load previously never pushed that
    // override, so Credentials::load() silently fell back to reading the
    // (empty) /boot credentials.json and the Credentials/Connections/Jobs tabs
    // looked empty even though the data was safely on disk at the configured
    // path.
    //
    // Config::sanitizeSecretsDir() confines the value to /mnt/<top>/<leaf> (see
    // Config.php), which doesn't exist on a dev/CI machine - like
    // HandlerCredentialsTest's secretsDir-migration tests, we can't drive a
    // real file move/read under /mnt here, only validate the push mechanism
    // itself (asserting Credentials::$secretsDirOverride ends up matching the
    // configured path after the page renders). The corresponding "override
    // correctly selects which file loads" half is already covered by
    // CredentialsTest::testSaveAndLoadUseOverrideDirNotBoot(); combined, the two
    // fully cover the reported symptom. RunInSeparateProcess isolates the
    // Credentials::$secretsDirOverride static mutation from other tests.

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCredentialsPagePushesConfiguredSecretsDirOverride(): void
    {
        $secretsDir = '/mnt/user/rsync';
        $cfg = Config::defaults();
        $cfg['global']['secretsDir'] = $secretsDir;
        Config::save($cfg);

        Credentials::$secretsDirOverride = null; // simulate a fresh request
        $this->renderPageBody(__DIR__ . '/../source/pages/credentials.php');

        $this->assertSame(
            $secretsDir,
            Credentials::$secretsDirOverride,
            'credentials.php must push the configured global.secretsDir onto '
            . 'Credentials::$secretsDirOverride before loading keys, the same way '
            . "handler.php's ur_dispatch() does for every AJAX action."
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectionsPagePushesConfiguredSecretsDirOverride(): void
    {
        $secretsDir = '/mnt/user/rsync';
        $cfg = Config::defaults();
        $cfg['global']['secretsDir'] = $secretsDir;
        Config::save($cfg);

        Credentials::$secretsDirOverride = null; // simulate a fresh request
        $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $this->assertSame(
            $secretsDir,
            Credentials::$secretsDirOverride,
            'connections.php must push the configured global.secretsDir onto '
            . 'Credentials::$secretsDirOverride before loading keys/connections '
            . "(feeds every 'SSH key' <select>), the same way handler.php's "
            . 'ur_dispatch() does for every AJAX action.'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testJobsPagePushesConfiguredSecretsDirOverride(): void
    {
        $secretsDir = '/mnt/user/rsync';
        $cfg = Config::defaults();
        $cfg['global']['secretsDir'] = $secretsDir;
        Config::save($cfg);

        Credentials::$secretsDirOverride = null; // simulate a fresh request
        $this->renderPageBody(__DIR__ . '/../source/pages/jobs.php');

        $this->assertSame(
            $secretsDir,
            Credentials::$secretsDirOverride,
            'jobs.php must push the configured global.secretsDir onto '
            . 'Credentials::$secretsDirOverride before loading connections '
            . '(feeds the per-job Connection <select>), the same way '
            . "handler.php's ur_dispatch() does for every AJAX action."
        );
    }

    // --- issue #139: the rsync DAEMON transport ----------------------------

    /**
     * D7 / F1: rsync's main.c:1558 rejects --contimeout outright (exit 1,
     * RERR_SYNTAX) on EVERY non-daemon transfer - remote-shell AND local - so
     * Rsync::buildArgv now drops the key off-daemon. The help must say so
     * instead of the old, actively wrong "Leave blank to use the SSH/rsync
     * default", which implied the flag worked on an SSH job.
     *
     * The reword must keep the "(--contimeout=SECONDS)" parenthetical, because
     * testEveryDescriptionNamesTheRsyncFlag matches every description against
     * the flag regex - asserted here directly so a future reword that drops it
     * fails with a message that says WHY.
     */
    public function testContimeoutHelpSaysItIsRsyncDaemonOnly(): void
    {
        $text = ur_option_help()['contimeout'];

        $this->assertSame(
            'Give up if the connection cannot be established within this many seconds '
            . '(--contimeout=SECONDS). rsync accepts this ONLY when connecting to an rsync '
            . 'daemon (rsyncd) - it rejects it outright on SSH and Local transfers, so it is '
            . 'dropped for those. Leave blank for no connect timeout.',
            $text
        );
        $this->assertStringContainsString('(--contimeout=SECONDS)', $text);
        $this->assertMatchesRegularExpression(
            '/\((?:-{1,2}[A-Za-z][\w-]*(?:=[A-Z]+)?)\)/',
            $text,
            'The contimeout reword must keep a flag parenthetical or '
            . 'testEveryDescriptionNamesTheRsyncFlag breaks.'
        );
        // The old copy claimed a fallback that does not exist: rsync EXITS on
        // this flag off-daemon, it does not fall back to an SSH default.
        $this->assertStringNotContainsString('Leave blank to use the SSH/rsync default', $text);
    }

    /**
     * --port and --password-file are carried in the transport-pieces bag that
     * Rsync::buildArgv receives, NEVER in the closed option whitelist: a
     * user-editable --password-file would be an arbitrary-file-read primitive
     * aimed at a remote daemon, and a whitelisted --port would split the source
     * of truth with the Connection's own port. Guard both the help map and the
     * rendered form so neither can grow a control for them.
     */
    public function testDaemonTransportFlagsNeverBecomeWhitelistOptions(): void
    {
        $helpKeys = array_keys(ur_option_help());
        foreach (['port', 'daemonPort', 'passwordFile', 'password'] as $forbidden) {
            $this->assertNotContains($forbidden, $helpKeys);
        }
        // The whitelist itself is unchanged, so the help map is unchanged.
        // (Canonicalizing, like testHelpMapKeysMatchWhitelistExactly: the help
        // map is grouped for reading, not stored in whitelist order.)
        $this->assertEqualsCanonicalizing(array_keys(Config::defaultRsyncOptions()), $helpKeys);
        $this->assertCount(40, $helpKeys);

        $html = $this->renderOptions(Config::defaultRsyncOptions(), 'global[defaultRsyncOptions]', 'ur_t139');
        foreach (['[port]', '[daemonPort]', '[passwordFile]', '--password-file', '--port='] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "The options form must never render a control for '$needle'."
            );
        }
    }

    /**
     * The Connections tab must render the per-card Transport select, seed it
     * from the stored value, and show the "rsyncd is not encrypted" warning on
     * a daemon card without the page Help toggle (display:block, not the usual
     * blockquote.inline_help default of hidden).
     *
     * Also covers the two things a legacy install depends on: a stored record
     * with NO transport key at all backfills to SSH on port 22, and a daemon
     * record defaults to port 873 - which is mergeConnection's clamp-transport-
     * BEFORE-port ordering, visible here end to end.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectionsPageRendersTransportSelectAndDaemonWarning(): void
    {
        $this->seedConnections();
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        // One transport select per card, plus one in the clone template.
        $this->assertSame(
            3,
            preg_match_all('/class="ur-conn-transport"/', $html),
            'Every connection card AND the #ur-conn-template must carry a Transport select.'
        );
        $this->assertStringContainsString(
            '<select id="ur_conn_0_transport" class="ur-conn-transport" '
            . 'name="connections[0][transport]" data-idb="ur_conn_0">',
            $html
        );
        $this->assertStringContainsString(
            '<select id="ur_conn___CIDX___transport" class="ur-conn-transport" '
            . 'name="connections[__CIDX__][transport]" data-idb="ur_conn___CIDX__">',
            $html
        );

        // Both options exist, and each card's stored transport is pre-selected:
        // card 0 (legacy, no transport key) SSH, card 1 DAEMON.
        $this->assertSame(
            [
                ['SSH', ' selected'], ['DAEMON', ''],       // card 0 - legacy
                ['SSH', ''], ['DAEMON', ' selected'],       // card 1 - daemon
                ['SSH', ' selected'], ['DAEMON', ''],       // the clone template
            ],
            $this->transportOptions($html)
        );

        // The unencrypted warning: present on every card, forced visible only on
        // the daemon one. It must NOT be behind the page Help toggle.
        $this->assertSame(
            ['display:none', 'display:block', 'display:none'],
            $this->attrList($html, '/<blockquote class="inline_help ur-daemon-warn" style="([^"]*)"/')
        );
        $this->assertStringContainsString(
            'the rsync daemon protocol is NOT encrypted. Only a challenge/response (MD4 with '
            . 'old peers) protects the module secret, and file names and file contents travel in '
            . 'clear over the network. Use SSH transport on any untrusted network.',
            $html
        );

        // mergeConnection clamps the transport BEFORE choosing the port default,
        // so a legacy record lands on 22 and a daemon record on 873.
        $this->assertStringContainsString(
            'id="ur_conn_0_port" name="connections[0][port]" value="22" placeholder="22"',
            $html
        );
        $this->assertStringContainsString(
            'id="ur_conn_1_port" name="connections[1][port]" value="873" placeholder="873"',
            $html
        );

        // The summary table gains a Transport column, and a daemon row's Auth
        // cell reads the literal "rsyncd" rather than a meaningless authMethod.
        $tbody = $this->collapse($this->between($html, '<tbody>', '</tbody>'));
        $this->assertStringContainsString(
            '<tr> <td>Legacy NAS</td> <td>SSH</td> <td>nas.local:22</td> <td>root</td> <td>KEYFILE</td> </tr>',
            $tbody
        );
        $this->assertStringContainsString(
            '<tr> <td>Daemon NAS</td> <td>DAEMON</td> <td>nas2.local:873</td> <td>moduser</td> <td>rsyncd</td> </tr>',
            $tbody
        );
        $this->assertStringContainsString(
            '<th>Name</th> <th>Transport</th> <th>Host</th> <th>User</th> <th>Auth</th>',
            $this->collapse($this->between($html, '<thead>', '</thead>'))
        );
    }

    /**
     * Every SSH-only control on a DAEMON card must be seeded hidden SERVER-side
     * (before any JS runs) and every daemon-only control shown - and the exact
     * reverse on an SSH card. Asserted as an invariant over the whole card
     * rather than a list of ids, so a row added later is covered too.
     *
     * The one shared control is the password row: it is the SAME single input
     * on both transports (a second input with the same name would also submit,
     * PHP would keep the last, and the blank-preserves-the-stored-value rule
     * would make an SSH password unchangeable), so it must be visible on the
     * daemon card and must NOT carry ur-ssh-only.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectionsPageHidesSshOnlyControlsOnADaemonCard(): void
    {
        $this->seedConnections();
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $daemonCard = $this->between($html, 'data-conn-id="c-daemon"', '<script type="text/html"');
        $this->assertNotSame('', $daemonCard, 'daemon card not found in connections.php output.');

        $sshOnly = $this->tagsWithClass($daemonCard, 'ur-ssh-only');
        $this->assertGreaterThanOrEqual(10, count($sshOnly), 'expected the SSH-only rows on the card.');
        foreach ($sshOnly as $tag) {
            $this->assertStringContainsString(
                'style="display:none"',
                $tag,
                "A .ur-ssh-only element must be seeded hidden on a DAEMON card: $tag"
            );
        }
        foreach ($this->tagsWithClass($daemonCard, 'ur-daemon-only') as $tag) {
            $this->assertStringNotContainsString(
                'display:none',
                $tag,
                "A .ur-daemon-only element must be visible on a DAEMON card: $tag"
            );
        }
        // The single shared password row: visible, relabelled, not SSH-only.
        $this->assertStringContainsString('<dt class="ur-auth-pass" id="ur_conn_1_passrow_dt">', $daemonCard);
        $this->assertStringContainsString('<dd class="ur-auth-pass" id="ur_conn_1_passrow_dd">', $daemonCard);
        $this->assertSame(
            1,
            preg_match_all('/name="connections\[1\]\[password\]"/', $daemonCard),
            'There must be exactly ONE password input per card, relabelled - never a second one.'
        );
        $this->assertStringContainsString('>Module secret</span>', $daemonCard);

        // ...and the mirror image on the SSH card: daemon-only help hidden, the
        // transport-driven SSH rows visible. (The ur-auth-* rows carry their own
        // auth-method hiding, which is a different toggle, so they are excluded.)
        $sshCard = $this->between($html, 'data-conn-id="c-legacy"', 'data-conn-id="c-daemon"');
        $this->assertNotSame('', $sshCard, 'legacy SSH card not found in connections.php output.');
        foreach ($this->tagsWithClass($sshCard, 'ur-daemon-only') as $tag) {
            $this->assertStringContainsString(
                'style="display:none"',
                $tag,
                "A .ur-daemon-only element must be seeded hidden on an SSH card: $tag"
            );
        }
        foreach ($this->tagsWithClass($sshCard, 'ur-ssh-only') as $tag) {
            if (strpos($tag, 'ur-auth-') !== false) {
                continue; // hidden by the auth-method toggle, not the transport one
            }
            $this->assertStringNotContainsString(
                'display:none',
                $tag,
                "A .ur-ssh-only element must be visible on an SSH card: $tag"
            );
        }
    }

    /**
     * The at-rest warning under the password field is transport-specific ADVICE
     * wrapped around a shared fact. The fact (obfuscated, not encrypted, on a
     * world-readable flash) is true for a module secret and must stay; the advice
     * "Prefer key auth" is unactionable on rsyncd, which has no key auth at all,
     * so the blockquote is class-toggled like every other transport-specific box
     * on the card rather than left saying the wrong thing.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPasswordAdviceIsTransportSpecific(): void
    {
        $this->seedConnections();
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $daemonCard = $this->between($html, 'data-conn-id="c-daemon"', '<script type="text/html"');
        $this->assertNotSame('', $daemonCard, 'daemon card not found in connections.php output.');
        $sshCard = $this->between($html, 'data-conn-id="c-legacy"', 'data-conn-id="c-daemon"');
        $this->assertNotSame('', $sshCard, 'legacy SSH card not found in connections.php output.');

        // Both variants are rendered on both cards (the toggle is display-only),
        // so look inside the password row and pick the one that is SHOWN there.
        $shownAdvice = static function (string $card): string {
            // NB the needle stops at the quote, not '">': on an SSH card whose
            // auth is not PASSWORD the same <dd> also carries style="display:none".
            $row = (string) strstr((string) strstr($card, '_passrow_dd"'), '</dd>', true);
            $m   = [];
            preg_match_all(
                '/<blockquote class="inline_help (ur-(?:ssh|daemon)-only)"(?: style="([^"]*)")?><p>(.*?)<\/p>/s',
                $row,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $bq) {
                if (($bq[2] ?? '') !== 'display:none') {
                    return $bq[3];
                }
            }
            return '';
        };

        $daemonAdvice = $shownAdvice($daemonCard);
        $this->assertStringContainsString('Module secrets are stored OBFUSCATED', $daemonAdvice);
        $this->assertStringContainsString('world-readable USB flash', $daemonAdvice, 'the at-rest fact must stay');
        $this->assertStringContainsString('rsyncd has no key auth', $daemonAdvice);
        $this->assertStringNotContainsString(
            'Prefer key auth',
            $daemonAdvice,
            'rsyncd has no key auth, so that advice must not be the visible one'
        );

        // The SSH variant is still rendered on the daemon card, just seeded hidden.
        $this->assertStringContainsString(
            '<blockquote class="inline_help ur-ssh-only" style="display:none"><p><strong>Warning:</strong> '
            . 'Passwords are stored OBFUSCATED',
            $daemonCard
        );

        // ...and the exact mirror on the SSH card.
        $sshAdvice = $shownAdvice($sshCard);
        $this->assertStringContainsString('Passwords are stored OBFUSCATED', $sshAdvice);
        $this->assertStringContainsString('Prefer key auth', $sshAdvice);
        $this->assertStringContainsString(
            '<blockquote class="inline_help ur-daemon-only" style="display:none"><p><strong>Warning:</strong> '
            . 'Module secrets are stored OBFUSCATED',
            $sshCard
        );
    }

    /**
     * The transport toggle is entirely CLIENT-side: nothing in the rendered HTML
     * proves that switching a card's select actually re-hides anything. The
     * server-seeded state is asserted above; this pins the JS that keeps it in
     * step, against the SOURCE TEXT, exactly as testPagesWireTheJobTransportIntoThePreview
     * pins _options_form.php's listener. Deleting any one of these lines leaves a
     * card that looks right on load and lies the moment the user touches it -
     * and the `el.required = false` line is what stops a HIDDEN required field
     * blocking Apply with an unfocusable-control error.
     */
    public function testConnectionsPageJsTogglesEveryTransportClassAndClearsHiddenRequired(): void
    {
        $js = file_get_contents(__DIR__ . '/../source/pages/connections.php');
        $this->assertIsString($js);

        foreach ([
            ".ur-ssh-only'),    function (el) { el.style.display = isDaemon ? 'none'  : '';      }",
            ".ur-daemon-only'), function (el) { el.style.display = isDaemon ? ''      : 'none';  }",
            ".ur-daemon-warn'), function (el) { el.style.display = isDaemon ? 'block' : 'none';  }",
        ] as $needle) {
            $this->assertStringContainsString($needle, $js, 'syncConnTransport must toggle every class, in the right direction');
        }
        $this->assertStringContainsString(
            "var el = document.getElementById(idb + s); if (el) { el.required = false; }",
            $js,
            'a hidden `required` control must be de-required or the browser refuses to submit'
        );
        $this->assertStringContainsString(
            "if (passInput) { passInput.required = false; }",
            $js,
            'an anonymous module needs no secret, so the shared password input must not stay required'
        );
        $this->assertStringContainsString(
            "if (authSel) { syncAuthRequired(authSel); }",
            $js,
            'switching back to SSH must re-apply the auth-specific hiding'
        );
    }

    public function testConnectionsPageOnlyRewritesAPortStillHoldingTheOtherTransportsDefault(): void
    {
        $js = file_get_contents(__DIR__ . '/../source/pages/connections.php');
        $this->assertIsString($js);

        // Both assignments exist and BOTH are guarded on the field still holding
        // the other transport's default, so a user-chosen 8730 survives a toggle.
        $this->assertStringContainsString(
            "if (isDaemon) { if (v === '' || v === '22')  { port.value = '873'; } }",
            $js
        );
        $this->assertStringContainsString(
            "else          { if (v === '' || v === '873') { port.value = '22';  } }",
            $js
        );
        // ...and the whole rewrite is skipped unless the transport CHANGED under
        // the user's hands: the on-load seeder runs this for every card, and an
        // SSH connection stored on 873 is exactly the misconfiguration the plugin
        // warns about - silently rewriting it to 22 on mere page view would
        // persist a value nobody typed on the next Apply.
        $this->assertStringContainsString(
            "if (prev === undefined || prev === sel.value) { return; }",
            $js,
            'the seeding pass must touch no stored port'
        );
        $this->assertSame(
            2,
            preg_match_all('/port\\.value = \'(?:873|22)\';/', $js),
            'there must be no unguarded port assignment'
        );
    }

    public function testConnectionsPageSeedsTransportAfterAuthAndListensForTransportChanges(): void
    {
        $js = file_get_contents(__DIR__ . '/../source/pages/connections.php');
        $this->assertIsString($js);

        // Ordering constraint: syncConnTransport re-invokes syncAuthRequired on
        // its SSH branch, so it must run LAST or an SSH card's auth-specific
        // hiding is immediately overwritten. Both seeders, both call sites.
        $pairs = [];
        $m     = [];
        preg_match_all('/syncAll(AuthRequired|ConnTransport)\(\);/', $js, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as $hit) {
            $pairs[] = $hit[0];
        }
        $this->assertSame(
            ['AuthRequired', 'ConnTransport', 'AuthRequired', 'ConnTransport'],
            $pairs,
            'both seeders must call syncAllAuthRequired() BEFORE syncAllConnTransport()'
        );

        // And the delegated change listener must actually route the select.
        $this->assertStringContainsString(
            "} else if (t && t.classList && t.classList.contains('ur-conn-transport')) {",
            $js
        );
        $this->assertStringContainsString('syncConnTransport(t);', $js);
    }

    public function testTestConnectionResultIsWrittenWithTextContentNeverInnerHtml(): void
    {
        // A daemon module listing is answered PRE-AUTH (clientserver.c:1420-1424),
        // so anyone who can reach port 873 controls the text in b.message. It is
        // filtered and capped server-side, but the DOM write is the last line of
        // defence and nothing else pins it.
        $js = file_get_contents(__DIR__ . '/../source/pages/connections.php');
        $this->assertIsString($js);

        $this->assertSame(0, preg_match_all('/resultEl\.innerHTML/', $js));
        $this->assertStringContainsString("resultEl.textContent = b.message || 'OK';", $js);
        $this->assertStringContainsString(
            "resultEl.textContent = b.message + (b.reason ? ' [' + b.reason + ']' : '');",
            $js
        );
        $this->assertStringContainsString("resultEl.textContent = errText(res, 'Connection test failed.');", $js);

        // Nothing anywhere on the page may put a RESPONSE value into innerHTML.
        // The three innerHTML sites that exist are page-authored markup (the
        // discover progress bar) and the clone template - all static, none
        // server-fed. A fourth needs a look, so the count is pinned.
        $m = [];
        preg_match_all('/^.*\.innerHTML\b.*$/m', $js, $m);
        $this->assertCount(3, $m[0], 'a new innerHTML site needs a look: ' . implode(' | ', $m[0]));
        foreach ($m[0] as $line) {
            foreach (['b.message', 'b.reason', 'res.', 'body'] as $fromServer) {
                $this->assertStringNotContainsString(
                    $fromServer,
                    $line,
                    "daemon output is untrusted and must never reach innerHTML: $line"
                );
            }
        }
    }

    /**
     * The daemon-only help bodies and the reworded SSH ones are asserted to EXIST
     * and be visible elsewhere; this pins their CONTENT, so a blank <p> in the
     * right place cannot pass. Same treatment the password advice already gets.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDaemonOnlyHelpCopyIsPresentVerbatim(): void
    {
        $this->seedConnections();
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $this->assertStringContainsString(
            'The remote host&#039;s SSH port (usually 22). This is NOT the &quot;Rsync Server&quot; / '
            . 'rsyncd port 873 that NAS appliances expose - to talk to that, set Transport to '
            . '&quot;rsync daemon (rsyncd)&quot; instead.',
            $html
        );
        $this->assertStringContainsString(
            'The rsync daemon (rsyncd) port - 873 unless the NAS was configured otherwise. '
            . 'This is NOT an SSH port.',
            $html
        );
        $this->assertStringContainsString(
            'The rsyncd module user, from the daemon&#039;s &quot;auth users&quot; setting or the NAS '
            . '&quot;Rsync Server&quot; page - not an SSH account. If the module has no auth users, '
            . 'any value works and no secret is needed.',
            $html
        );
        // The pre-daemon wording claimed the plugin cannot speak rsyncd at all.
        $this->assertStringNotContainsString('a different protocol which this plugin does not speak', $html);
    }

    /**
     * Connect timeout drives exactly one thing - `ssh -o ConnectTimeout=N` in
     * Ssh::buildSshArgv - so on a daemon card it configures nothing and must be
     * hidden with the rest of the SSH-only rows. Showing it promises a bound the
     * daemon transport does not have (that is the --contimeout rsync option).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectTimeoutIsAnSshOnlyRow(): void
    {
        $this->seedConnections();
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $daemonCard = $this->between($html, 'data-conn-id="c-daemon"', '<script type="text/html"');
        $this->assertNotSame('', $daemonCard);
        $this->assertStringContainsString(
            '<dt class="ur-ssh-only" style="display:none"><label for="ur_conn_1_timeout">',
            $daemonCard
        );
        $this->assertStringContainsString(
            '<dd class="ur-ssh-only" style="display:none"><input type="text" id="ur_conn_1_timeout"',
            $daemonCard
        );

        $sshCard = $this->between($html, 'data-conn-id="c-legacy"', 'data-conn-id="c-daemon"');
        $this->assertStringContainsString(
            '<dt class="ur-ssh-only"><label for="ur_conn_0_timeout">',
            $sshCard
        );
    }

    /**
     * The live options preview renders from Rsync::optionTokens(), and its own
     * note says these flags are what runs - so it must be told the transport.
     * --contimeout is emitted only on rsync daemon transport, so an SSH job that
     * sets it previewed a flag the run then dropped.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptionsPreviewIsRenderedForTheJobsOwnTransport(): void
    {
        $opts = Config::mergeRsyncOptions(['contimeout' => '30']);

        $preview = static function (?string $transport) use ($opts): string {
            ob_start();
            ur_render_rsync_options($opts, 'jobs[0][rsyncOptions]', 'ur_j0', $transport);
            $html = (string) ob_get_clean();
            $m = [];
            preg_match('/<code class="ur-preview-out" aria-live="polite">(.*?)<\/code>/s', $html, $m);
            return $m[1] ?? '';
        };

        $this->assertStringContainsString('--contimeout=30', $preview('DAEMON'));
        $this->assertStringNotContainsString('--contimeout', $preview('SSH'));
        $this->assertStringNotContainsString('--contimeout', $preview('LOCAL'));
        // The Global Settings block passes no transport: shared by jobs of every
        // transport, so it shows the flag (and the global save warns about it).
        $this->assertStringContainsString('--contimeout=30', $preview(null));
    }

    public function testPreviewNoteNamesTheTransportFlagsGenerically(): void
    {
        // The note is the preview's own claim about what else the run adds. It
        // used to say "the SSH transport", which is wrong now: a daemon job's
        // added pieces are --port and --password-file, not -e.
        ob_start();
        ur_render_rsync_options(Config::defaultRsyncOptions(), 'jobs[0][rsyncOptions]', 'ur_j0');
        $html = (string) ob_get_clean();

        $note = $this->between($html, '<div class="ur-preview-note">', '</div>');
        $this->assertStringContainsString(
            'These option flags only. The log-level flags, --log-file, the transport flags and the '
            . 'source/destination paths are added when the job runs.',
            $note
        );
        $this->assertStringNotContainsString('the SSH transport', $html);
    }

    public function testSettingsSecretsHelpNamesTheModuleSecretAsAStoredSecret(): void
    {
        // credentials.json now holds a fourth kind of secret; the secretsDir help
        // is where a user reads what is at rest on the world-readable flash.
        $settings = file_get_contents(__DIR__ . '/../source/pages/settings.php');
        $this->assertIsString($settings);
        $this->assertStringContainsString(
            'your SSH keys, obfuscated passwords and rsync daemon module secrets, and saved host keys',
            $settings
        );
    }

    /**
     * The two halves of that: the job card must hand its transport to the
     * renderer, and the client must send it when it re-previews after an edit -
     * including when the transport select itself changes, which happens OUTSIDE
     * the options block the other listeners are delegated on.
     */
    public function testPagesWireTheJobTransportIntoThePreview(): void
    {
        $jobs = file_get_contents(__DIR__ . '/../source/pages/jobs.php');
        $this->assertIsString($jobs);
        $this->assertStringContainsString(
            "ur_render_rsync_options(\$opts, \$p . '[rsyncOptions]', \$idb . '_opts_fields', \$transport);",
            $jobs,
            'the job card must pass its own transport to the options renderer'
        );

        $form = file_get_contents(__DIR__ . '/../source/pages/_options_form.php');
        $this->assertIsString($form);
        $this->assertStringContainsString("params.append('transport', transport);", $form);
        $this->assertStringContainsString("card.querySelector('.ur-transport-select')", $form);
        $this->assertStringContainsString(
            "if (!t || !t.classList || !t.classList.contains('ur-transport-select')) { return; }",
            $form,
            'changing the transport must re-run the preview'
        );

        // The Global Settings block genuinely has no transport to pass.
        $settings = file_get_contents(__DIR__ . '/../source/pages/settings.php');
        $this->assertIsString($settings);
        $this->assertStringContainsString(
            "ur_render_rsync_options(\$defaultOpts, 'global[defaultRsyncOptions]', 'ur_global');",
            $settings
        );
    }

    /**
     * With no saved connections the empty-state row must span the table's new
     * width (the Transport column took it from 4 columns to 5), and the clone
     * template must still seed a fresh card as SSH on port 22.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectionsPageEmptyStateSpansTheTransportColumn(): void
    {
        $html = $this->renderPageBody(__DIR__ . '/../source/pages/connections.php');

        $this->assertStringContainsString('<td colspan="5">No connections yet', $html);
        $this->assertStringNotContainsString('colspan="4"', $html);
        // Only the clone template renders; a new card defaults to SSH on 22.
        $this->assertSame(
            1,
            preg_match_all('/class="ur-conn-transport"/', $html),
            'With no connections only #ur-conn-template should render a Transport select.'
        );
        $this->assertSame([['SSH', ' selected'], ['DAEMON', '']], $this->transportOptions($html));
        $this->assertStringContainsString(
            'id="ur_conn___CIDX___port" name="connections[__CIDX__][port]" value="22" placeholder="22"',
            $html
        );
    }

    /**
     * The Jobs tab must offer DAEMON as a third transport, require a Connection
     * for it, and explain that a daemon pair's right-hand box is a MODULE
     * reference.
     *
     * The Connection <select> is fed from the RAW (unmerged) credentials array,
     * so a pre-daemon record has no 'transport' key at all: the label suffix
     * must be read with ?? 'SSH' and must NEVER filter or group the options - a
     * dropped option would let the next save silently clear the job's
     * connectionId. Both branches are asserted.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testJobsPageOffersDaemonTransportAndModuleGuidance(): void
    {
        $this->seedConnections();
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'id'           => 'j-daemon',
            'name'         => 'Daemon Job',
            'transport'    => 'DAEMON',
            'connectionId' => 'c-daemon',
            'direction'    => 'PULL',
            'pairs'        => [['local' => '/mnt/user/photos/', 'remote' => 'rsync_bkp/photos']],
        ]);
        Config::save($cfg);

        $html = $this->renderPageBody(__DIR__ . '/../source/pages/jobs.php');

        // All three transports, with the stored DAEMON pre-selected on the card.
        $card = $this->between($html, 'id="ur_job_0_transport"', '</select>');
        $this->assertSame(
            '<option value="SSH">SSH (remote host)</option>'
            . '<option value="LOCAL">Local (this server)</option>'
            . '<option value="DAEMON" selected>rsync daemon (host::module)</option>',
            trim($this->between($card, '>', '')),
            'The job Transport select must offer SSH, LOCAL and DAEMON in that order.'
        );

        // A daemon job needs a Connection: both the `required` attribute and the
        // visual marker are seeded server-side ($connRequired = transport !== LOCAL).
        $this->assertStringContainsString(
            'id="ur_job_0_conn" class="ur-conn-select" data-transport="ur_job_0_transport" '
            . 'name="jobs[0][connectionId]" required>',
            $html
        );
        $this->assertStringNotContainsString(
            '<abbr class="ur-required ur-conn-required" title="Required" style="display:none">',
            $html,
            'The Connection required marker must be shown for a DAEMON job.'
        );
        // ...and the client rule matches the server rule (not LOCAL), so a
        // daemon job is never silently un-required in the browser.
        $this->assertStringContainsString("var needsConn = (transportSel.value !== 'LOCAL');", $html);

        // Pair guidance + the reworded direction/connection help.
        $this->assertStringContainsString(
            'For an rsync daemon job the right box is a MODULE reference on the daemon - just '
            . '&quot;rsync_bkp&quot;, or &quot;rsync_bkp/photos&quot; for a folder inside it. No host '
            . 'and no leading slash: the host, port, username and module secret all come from the Connection.',
            $html
        );
        $this->assertStringContainsString(
            'Direction applies to SSH and rsync daemon transports; a Local job always copies left to right.',
            $html
        );
        // The old SSH-only direction copy is gone.
        $this->assertStringNotContainsString('Direction only applies to SSH transport.', $html);
    }

    /**
     * The per-job Connection <select> must LIST every saved connection, daemon
     * ones included, and use the transport only to append a " [rsyncd]" label
     * suffix - never to filter or to group into <optgroup>s. A connection
     * dropped from this select would let the next save silently clear the job's
     * connectionId, and the suffix must be read with ?? 'SSH' so a record that
     * predates the transport field still renders.
     *
     * Driven through ur_render_job_card() directly rather than the whole page:
     * the card renderer reads `global $urConnections`, and renderPageBody()
     * include()s the body inside a METHOD, so the page's own top-level
     * assignment lands in a function-local instead of the global. (On a real
     * webGui page the body is included at global scope, so this is a harness
     * artifact only - the page's own load path is covered by
     * testJobsPagePushesConfiguredSecretsDirOverride.) Feeding the global
     * directly also lets us hand the renderer a record with NO transport key at
     * all, which Credentials::save() would otherwise backfill.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testJobsPageListsDaemonConnectionsWithALabelSuffixAndNoFiltering(): void
    {
        // Defines ur_render_job_card() (function declarations are global no
        // matter where the file is included from).
        $this->renderPageBody(__DIR__ . '/../source/pages/jobs.php');

        $GLOBALS['urConnections'] = [
            ['id' => 'c-legacy', 'name' => 'Legacy NAS'],                            // no transport key at all
            ['id' => 'c-ssh',    'name' => 'SSH NAS',    'transport' => 'SSH'],
            ['id' => 'c-daemon', 'name' => 'Daemon NAS', 'transport' => 'DAEMON'],
            ['id' => 'c-lower',  'name' => 'Lower NAS',  'transport' => 'daemon'],   // hand-edited casing
        ];

        ob_start();
        ur_render_job_card(
            Job::normalize([
                'id'           => 'j-daemon',
                'name'         => 'Daemon Job',
                'transport'    => 'DAEMON',
                'connectionId' => 'c-daemon',
                'pairs'        => [['local' => '/mnt/user/photos/', 'remote' => 'rsync_bkp/photos']],
            ]),
            0
        );
        $card = (string) ob_get_clean();

        $select = $this->between($card, 'name="jobs[0][connectionId]"', '</select>');
        $this->assertNotSame('', $select, 'the Connection select did not render.');
        preg_match_all('/<option value="[^"]*"(?: selected)?>[^<]*<\/option>/', $select, $m);
        $this->assertSame([
            '<option value="">(none)</option>',
            '<option value="c-legacy">Legacy NAS</option>',
            '<option value="c-ssh">SSH NAS</option>',
            '<option value="c-daemon" selected>Daemon NAS [rsyncd]</option>',
            '<option value="c-lower">Lower NAS [rsyncd]</option>',
        ], $m[0]);
        // No grouping, and nothing filtered out.
        $this->assertStringNotContainsString('<optgroup', $select);

        // The help under the select names both remote transports now (this
        // branch only renders when connections exist, which is why it lives
        // here rather than in the whole-page test).
        $this->assertStringContainsString(
            'Used for SSH and rsync daemon transports. Manage connections in the Connections tab.',
            $card
        );
        $this->assertStringNotContainsString('Used for SSH transport.', $card);
    }

    /**
     * Seed one PRE-DAEMON connection record (no 'transport' key at all, exactly
     * as an existing install has it on disk) and one daemon record.
     */
    private function seedConnections(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = [
            'id'          => 'c-legacy',
            'name'        => 'Legacy NAS',
            'host'        => 'nas.local',
            'username'    => 'root',
            'authMethod'  => 'KEYFILE',
            'keyFilePath' => '/root/.ssh/id_ed25519',
        ];
        $creds['connections'][] = [
            'id'        => 'c-daemon',
            'name'      => 'Daemon NAS',
            'host'      => 'nas2.local',
            'username'  => 'moduser',
            'transport' => 'DAEMON',
        ];
        Credentials::save($creds);
    }

    /**
     * Every rendered transport <option> as [value, selectedAttr] pairs, in
     * document order.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function transportOptions(string $html): array
    {
        preg_match_all(
            '/<option value="(SSH|DAEMON)"( selected)?>(?:SSH \(rsync over SSH\)|rsync daemon \(rsyncd, port 873\))<\/option>/',
            $html,
            $m,
            PREG_SET_ORDER
        );
        return array_map(static fn(array $x): array => [$x[1], $x[2] ?? ''], $m);
    }

    /**
     * Capture group 1 of every match of $pattern, in document order.
     *
     * @return array<int,string>
     */
    private function attrList(string $html, string $pattern): array
    {
        preg_match_all($pattern, $html, $m);
        return $m[1];
    }

    /**
     * Every opening tag in $html whose class attribute contains $class.
     *
     * @return array<int,string>
     */
    private function tagsWithClass(string $html, string $class): array
    {
        preg_match_all('/<[a-z]+[^>]*\b' . preg_quote($class, '/') . '\b[^>]*>/', $html, $m);
        return $m[0];
    }

    /** The slice of $html strictly between $start and $end ('' when not found). */
    private function between(string $html, string $start, string $end): string
    {
        $i = strpos($html, $start);
        if ($i === false) {
            return '';
        }
        $i += strlen($start);
        if ($end === '') {
            return substr($html, $i);
        }
        $j = strpos($html, $end, $i);
        return $j === false ? '' : substr($html, $i, $j - $i);
    }

    /** Collapse every run of whitespace to a single space, trimmed. */
    private function collapse(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
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
        // A trailing `// comment` after the semicolon (e.g. credentials.php's
        // `// ur_h / ur_t`) is tolerated so those lines still match.
        $src = preg_replace(
            "#^\\s*require_once\\s+'/usr/local/emhttp/plugins/unraid\\.rsync/[^']+';[ \\t]*(?://[^\\n]*)?$#m",
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
