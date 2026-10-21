<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Form -> handler -> stored shape. Each test renders the real Jobs or
 * Connections page body, submits its form the way a browser does (successful
 * controls only: disabled ones and unchecked boxes are left out), runs the save
 * handler on that POST and inspects what reached disk.
 *
 * The snapshot switch is flipped in the DOM exactly as syncSnapshot() in
 * jobs.php does it (testTheJsSnapshotSwitchDoesWhatTheseTestsSimulate keeps
 * the two in step).
 *
 * Separate processes: a page body declares its render functions globally.
 */
final class FormRoundTripTest extends TestCase
{
    private const ROOT   = '/mnt/backup/snapshots/appdata';
    private const MARKER = '/mnt/backup/.unraid-rsync-target';

    protected function setUp(): void
    {
        if (!defined('UR_HANDLER_TESTING')) {
            define('UR_HANDLER_TESTING', true);
        }
        require_once __DIR__ . '/../source/include/handler.php';
        require_once __DIR__ . '/../source/pages/_options_form.php';
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        $GLOBALS['ur_last_response_code'] = 200;
        $_POST = [];

        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'backup host', 'host' => 'backup.example', 'username' => 'root',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-2', 'name' => 'sudo host', 'host' => 'backup2.example', 'username' => 'backup', 'useSudo' => true,
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-d', 'name' => 'daemon', 'transport' => 'DAEMON', 'host' => 'nas.example', 'username' => 'mod', 'keyFilePath' => '',
        ]);
        Credentials::save($creds);
    }

    /** @param array<int,array<string,mixed>> $jobs raw jobs, normalised and saved as the handler would */
    private function seedJobs(array $jobs): void
    {
        $config = Config::defaults();
        foreach ($jobs as $job) {
            $config['jobs'][] = Job::normalize($job);
        }
        Config::save($config);
    }

    /** @return array<string,mixed> */
    private function mirrorJob(): array
    {
        return [
            'id' => 'j-appdata', 'name' => 'Appdata', 'transport' => 'SSH', 'direction' => 'PUSH', 'connectionId' => 'c-1',
            'schedule' => '15 2 * * *', 'useGlobalDefaults' => false, 'logLevel' => 'summary', 'notifyMode' => 'always',
            'preHook' => "docker stop app\necho 'stopped'", 'postHook' => 'docker start app',
            'targetMarker' => self::MARKER, 'targetMarkerContent' => '3f1c2a9e-0000-4000-8000-000000000001',
            'pairs' => [
                ['local' => '/mnt/user/appdata/', 'remote' => '/mnt/backup/mirror/appdata/'],
                ['local' => '/mnt/user/docs/', 'remote' => '/mnt/backup/mirror/docs/'],
            ],
            'rsyncOptions' => Config::mergeRsyncOptions([
                'archive' => true, 'times' => true, 'delete' => true, 'maxDelete' => '50', 'bwlimit' => '5000',
                'filters' => [['type' => 'include', 'pattern' => 'keep/'], ['type' => 'exclude', 'pattern' => '*.tmp']],
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotJob(): array
    {
        $job = $this->mirrorJob();
        $job['rsyncOptions']['delete'] = false;
        $job['rsyncOptions']['maxDelete'] = '';
        $job['pairs'] = [
            ['local' => '/mnt/user/appdata/', 'remote' => '', 'sub' => 'appdata'],
            ['local' => '/mnt/user/docs/', 'remote' => '', 'sub' => 'docs', 'seed' => true],
        ];
        $job['snapshot'] = ['enabled' => true, 'root' => self::ROOT, 'keepDaily' => 10, 'keepMonthly' => 2, 'pruneDryRun' => false];
        return $job;
    }

    private function localJob(): array
    {
        return [
            'id' => 'j-local', 'name' => 'Local', 'transport' => 'LOCAL', 'manualOnly' => true,
            'pairs' => [['local' => '/mnt/user/photos/', 'remote' => '/mnt/disk2/photos/']],
        ];
    }

    /** Render a page body as the webGui would (minus its install-path requires). */
    private function render(string $page): Dom\HTMLDocument
    {
        $src = (string) file_get_contents(__DIR__ . '/../source/pages/' . $page);
        $src = (string) preg_replace("#^\\s*require_once\\s+'/usr/local/emhttp/plugins/unraid\\.rsync/[^']+';[ \\t]*(?://[^\\n]*)?$#m", '', $src);
        $tmp = tempnam(sys_get_temp_dir(), 'ur_rt_') . '.php';
        file_put_contents($tmp, $src);
        try {
            ob_start();
            include $tmp;
            $html = (string) ob_get_clean();
        } finally {
            @unlink($tmp);
        }
        return Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    }

    /**
     * What URLSearchParams(new FormData(form)) sends, parsed the way PHP does.
     *
     * @return array<string,mixed>
     */
    private function submit(Dom\HTMLDocument $doc, string $formId): array
    {
        $form = $doc->getElementById($formId);
        $this->assertNotNull($form, "form #$formId not rendered");
        $pairs = [];
        foreach ($form->querySelectorAll('input[name], select[name], textarea[name]') as $el) {
            if ($el->hasAttribute('disabled')) {
                continue;
            }
            $name = (string) $el->getAttribute('name');
            if ($el->localName === 'select') {
                $chosen = $el->querySelector('option[selected]') ?? $el->querySelector('option');
                if ($chosen === null) {
                    continue;
                }
                $value = $chosen->hasAttribute('value') ? (string) $chosen->getAttribute('value') : (string) $chosen->textContent;
            } elseif ($el->localName === 'textarea') {
                $value = (string) $el->textContent;
            } else {
                $type = strtolower((string) ($el->getAttribute('type') ?? 'text'));
                if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)
                    || (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked'))) {
                    continue;
                }
                $value = $el->hasAttribute('value') ? (string) $el->getAttribute('value') : ($type === 'checkbox' ? 'on' : '');
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $post);
        $post['action'] = (string) $form->getAttribute('data-ur-action');
        return $post;
    }

    /** syncSnapshot() from jobs.php, applied to a rendered card. */
    private function flipSnapshot(Dom\Element $card, bool $on): void
    {
        $toggle = $card->querySelector('.ur-snap-toggle');
        $this->assertNotNull($toggle);
        $toggle->toggleAttribute('checked', $on);
        foreach ($card->querySelectorAll('.ur-snap-field') as $el) {
            $el->toggleAttribute('disabled', !$on);
        }
        foreach ($card->querySelectorAll('.ur-pair-sub') as $el) {
            $el->toggleAttribute('required', $on);
        }
        foreach ($card->querySelectorAll('.ur-pair-remote') as $el) {
            $el->toggleAttribute('disabled', $on);
            $el->toggleAttribute('required', !$on);
        }
    }

    private function type(Dom\Element $card, string $nameSuffix, string $value): void
    {
        $el = null;
        foreach ($card->querySelectorAll('input[name]') as $candidate) {
            if (str_ends_with((string) $candidate->getAttribute('name'), $nameSuffix)) {
                $el = $candidate;
            }
        }
        $this->assertNotNull($el, "no input named *$nameSuffix");
        $el->setAttribute('value', $value);
    }

    private function check(Dom\Element $card, string $nameSuffix, bool $on): void
    {
        foreach ($card->querySelectorAll('input[type="checkbox"][name]') as $el) {
            if (str_ends_with((string) $el->getAttribute('name'), $nameSuffix)) {
                $el->toggleAttribute('checked', $on);
                return;
            }
        }
        $this->fail("no checkbox named *$nameSuffix");
    }

    private function card(Dom\HTMLDocument $doc, int $i): Dom\Element
    {
        $card = $doc->querySelector('#ur-jobs-container .ur-job-card[data-index="' . $i . '"]');
        $this->assertNotNull($card);
        return $card;
    }

    /** @return array{0:array<string,mixed>|null,1:int} */
    private function save(callable $action, array $post): array
    {
        $_POST = $post + ['csrf_token' => 'test-token'];
        ob_start();
        $action();
        $body = json_decode((string) ob_get_clean(), true);
        return [is_array($body) ? $body : null, (int) $GLOBALS['ur_last_response_code']];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMirrorJobsReSaveByteIdentical(): void
    {
        $this->seedJobs([$this->mirrorJob(), $this->localJob()]);
        $before = (string) file_get_contents(Config::path());

        $post = $this->submit($this->render('jobs.php'), 'ur-jobs-form');
        // A mirror job posts only the switch, and no sub or seed.
        $this->assertSame(['enabled' => '0'], $post['jobs'][0]['snapshot']);
        $this->assertSame(['local', 'remote'], array_keys($post['jobs'][0]['pairs'][0]));

        [$body, $code] = $this->save('ur_action_save_config', $post);
        $this->assertSame(200, $code, (string) json_encode($body));
        $this->assertSame($before, file_get_contents(Config::path()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSnapshotJobReSavesByteIdentical(): void
    {
        $this->seedJobs([$this->snapshotJob(), $this->localJob()]);
        $before = (string) file_get_contents(Config::path());
        $this->assertSame(3, json_decode($before, true)['schemaVersion']);

        $post = $this->submit($this->render('jobs.php'), 'ur-jobs-form');
        $this->assertArrayNotHasKey('remote', $post['jobs'][0]['pairs'][0], 'the hidden remote is not posted');

        [$body, $code] = $this->save('ur_action_save_config', $post);
        $this->assertSame(200, $code, (string) json_encode($body));
        $this->assertSame($before, file_get_contents(Config::path()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnablingSnapshotModeStoresTheBlockAndTheSubsAndSeeds(): void
    {
        $job = $this->mirrorJob();
        $job['rsyncOptions']['delete'] = false;
        $job['rsyncOptions']['maxDelete'] = '';
        $this->seedJobs([$job]);
        $doc  = $this->render('jobs.php');
        $card = $this->card($doc, 0);
        $this->flipSnapshot($card, true);
        $this->type($card, '[snapshot][root]', self::ROOT);
        $this->type($card, '[pairs][0][sub]', 'appdata');
        $this->type($card, '[pairs][1][sub]', 'docs');
        $this->check($card, '[pairs][0][seed]', true);
        $this->check($card, '[pairs][1][seed]', true);

        [$body, $code] = $this->save('ur_action_save_config', $this->submit($doc, 'ur-jobs-form'));
        $this->assertSame(200, $code, (string) json_encode($body));

        $raw = json_decode((string) file_get_contents(Config::path()), true);
        $this->assertSame(3, $raw['schemaVersion']);
        $this->assertSame(
            ['enabled' => true, 'root' => self::ROOT, 'keepDaily' => 7, 'keepWeekly' => 4, 'keepMonthly' => 0, 'maxPrunePerRun' => 3, 'pruneDryRun' => true],
            $raw['jobs'][0]['snapshot'],
            'the defaults, with prune dry run on'
        );
        $this->assertSame([
            ['local' => '/mnt/user/appdata/', 'remote' => '', 'sub' => 'appdata', 'seed' => true],
            ['local' => '/mnt/user/docs/', 'remote' => '', 'sub' => 'docs', 'seed' => true],
        ], $raw['jobs'][0]['pairs']);
        $this->assertSame('snapshot', array_key_last($raw['jobs'][0]), 'appended after the existing keys');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisablingSnapshotModeRemovesTheBlockAndTheSubsAndSeeds(): void
    {
        $this->seedJobs([$this->snapshotJob()]);
        $doc  = $this->render('jobs.php');
        $card = $this->card($doc, 0);
        $this->flipSnapshot($card, false);
        $this->type($card, '[pairs][0][remote]', '/mnt/backup/mirror/appdata/');
        $this->type($card, '[pairs][1][remote]', '/mnt/backup/mirror/docs/');

        $post = $this->submit($doc, 'ur-jobs-form');
        $this->assertSame(['enabled' => '0'], $post['jobs'][0]['snapshot']);
        [$body, $code] = $this->save('ur_action_save_config', $post);
        $this->assertSame(200, $code, (string) json_encode($body));

        $raw = json_decode((string) file_get_contents(Config::path()), true);
        $this->assertSame(2, $raw['schemaVersion']);
        $this->assertArrayNotHasKey('snapshot', $raw['jobs'][0]);
        $this->assertSame([
            ['local' => '/mnt/user/appdata/', 'remote' => '/mnt/backup/mirror/appdata/'],
            ['local' => '/mnt/user/docs/', 'remote' => '/mnt/backup/mirror/docs/'],
        ], $raw['jobs'][0]['pairs']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSnapshotValidationErrorsComeBackNamingTheJob(): void
    {
        $job = $this->mirrorJob();
        $job['rsyncOptions']['delete'] = false;
        $job['rsyncOptions']['maxDelete'] = '';
        $this->seedJobs([$job]);
        $before = (string) file_get_contents(Config::path());
        $doc  = $this->render('jobs.php');
        $card = $this->card($doc, 0);
        $this->flipSnapshot($card, true);
        $this->type($card, '[snapshot][root]', '/srv/elsewhere/snaps');
        $this->type($card, '[snapshot][keepDaily]', 'seven');
        $this->type($card, '[pairs][0][sub]', 'latest');
        $this->type($card, '[pairs][1][sub]', 'docs');

        [$body, $code] = $this->save('ur_action_save_config', $this->submit($doc, 'ur-jobs-form'));
        $this->assertSame(422, $code);
        $this->assertContains("Job \"Appdata\": Snapshot root '/srv/elsewhere/snaps' must be the target marker's directory ('/mnt/backup') or lie inside it.", $body['errors']);
        $this->assertContains('Job "Appdata": Snapshot setting keepDaily must be a whole number from 0 to 366.', $body['errors']);
        $this->assertContains("Job \"Appdata\": Pair #1: snapshot sub-directory name 'latest' is reserved.", $body['errors']);
        $this->assertSame($before, file_get_contents(Config::path()), 'nothing saved');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectionsReSaveByteIdentical(): void
    {
        $before = (string) file_get_contents(Credentials::path());
        $this->assertStringContainsString('"useSudo": true', $before);
        $post = $this->submit($this->render('connections.php'), 'ur-conns-form');
        [$body, $code] = $this->save('ur_action_save_credentials', $post);
        $this->assertSame(200, $code, (string) json_encode($body));
        $this->assertSame($before, file_get_contents(Credentials::path()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUseSudoIsStoredOnlyWhileOn(): void
    {
        $doc = $this->render('connections.php');
        $cards = $doc->querySelectorAll('#ur-conns-container .ur-conn-card');
        $this->check($cards->item(0), '[useSudo]', true);
        $this->check($cards->item(1), '[useSudo]', false);
        // Even ticked by hand on a daemon card, it is never stored there.
        $this->check($cards->item(2), '[useSudo]', true);

        [$body, $code] = $this->save('ur_action_save_credentials', $this->submit($doc, 'ur-conns-form'));
        $this->assertSame(200, $code, (string) json_encode($body));
        $conns = json_decode((string) file_get_contents(Credentials::path()), true)['connections'];
        $this->assertTrue($conns[0]['useSudo']);
        $this->assertArrayNotHasKey('useSudo', $conns[1]);
        $this->assertArrayNotHasKey('useSudo', $conns[2]);
    }

    public function testTheJsSnapshotSwitchDoesWhatTheseTestsSimulate(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../source/pages/jobs.php');
        $fn = substr($js, (int) strpos($js, 'function syncSnapshot(card)'), 1200);
        foreach ([
            "card.querySelector('.ur-snap-toggle')",
            "card.querySelectorAll('.ur-snap-only'), function (el) { el.style.display = on ? '' : 'none'; }",
            "card.querySelectorAll('.ur-snap-field'), function (el) { el.disabled = !on; }",
            "card.querySelectorAll('.ur-pair-sub'), function (el) { el.required = on; }",
            "el.style.display = on ? 'none' : '';",
            'el.disabled = on;',
            'el.required = !on;',
        ] as $needle) {
            $this->assertStringContainsString($needle, $fn);
        }
        // Wired to the switch, to a cloned card and to an added pair row.
        $this->assertStringContainsString("t.classList.contains('ur-snap-toggle')", $js);
        $this->assertStringContainsString("syncSnapshot(rowsEl.closest('.ur-job-card'));", $js);
        $this->assertStringContainsString('syncSnapshot(card);', $js);
        $this->assertStringContainsString('syncAllSnapshot();', $js);
    }
}
