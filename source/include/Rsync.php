<?php
/**
 * Rsync.php - build the rsync invocation as an ARGV ARRAY (never a shell
 * string) from a job's whitelisted options, and map the resulting exit code to
 * a TrueNAS-style run state.
 *
 * Why argv, never a string: a shell string would let any path/flag/secret be
 * re-parsed by /bin/sh, which is the classic rsync command-injection footgun
 * (CVE-2019-3463 and friends). We compose a plain string[] and hand it to
 * proc_open WITHOUT a shell, so nothing the user typed is ever word-split,
 * glob-expanded, or treated as a flag by the shell.
 *
 * This class is split into:
 *   - PURE composition (buildArgv, optionTokens, logLevelFlags, exitToState):
 *     no I/O, no live system, unit-tested by asserting on the returned arrays.
 *   - A thin run() seam that spawns the process; the spawn is injectable
 *     ($runner) so tests can supply a fake rsync and assert on argv + drive the
 *     exit code without a real binary.
 *
 * SCOPE (Phase 4): only the whitelisted, structured options object is mapped to
 * tokens. There is deliberately no free-form flag string anywhere in the
 * plugin, so an unlisted flag simply cannot be emitted - the map below is the
 * single source of truth, and it mirrors the keys Job.php normalises and
 * _options_form.php renders.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/Ssh.php';

class Rsync
{
    /**
     * Whitelisted BOOLEAN option key -> rsync flag. Mirrors
     * Job::BOOL_OPTION_KEYS exactly; a key present here but absent there (or
     * vice versa) would be a bug. The test suite asserts the two stay aligned
     * (RsyncTest::testWhitelistKeysMatchJobModel).
     *
     * @var array<string,string>
     */
    const BOOL_FLAGS = [
        'archive'         => '-a',
        'compress'        => '-z',
        'humanReadable'   => '-h',
        'times'           => '-t',
        'perms'           => '-p',
        'xattrs'          => '-X',
        'acls'            => '-A',
        'symlinks'        => '-l',
        'hardlinks'       => '-H',
        'sparse'          => '-S',
        'numericIds'      => '--numeric-ids',
        'partial'         => '--partial',
        'inplace'         => '--inplace',
        'checksum'        => '-c',
        'update'          => '-u',
        'wholeFile'       => '-W',
        'sizeOnly'        => '--size-only',
        'ignoreExisting'  => '--ignore-existing',
        'delete'          => '--delete',
        'deleteExcluded'  => '--delete-excluded',
    ];

    /**
     * Whitelisted SCALAR value key -> rsync long-flag stem. Each emits
     * `<flag>=<value>` only when the stored value is a non-empty string.
     * `backupDir` additionally pulls in a bare `--backup` (rsync requires
     * --backup for --backup-dir to take effect).
     *
     * @var array<string,string>
     */
    const SCALAR_FLAGS = [
        'maxDelete'      => '--max-delete',
        'bwlimit'        => '--bwlimit',
        'timeout'        => '--timeout',
        'contimeout'     => '--contimeout',
        'maxSize'        => '--max-size',
        'minSize'        => '--min-size',
        'chmod'          => '--chmod',
        'tempDir'        => '--temp-dir',
        'backupDir'      => '--backup-dir',
        'compressLevel'  => '--compress-level',
        'modifyWindow'   => '--modify-window',
    ];

    /**
     * Whitelisted LIST value key -> repeatable rsync flag. Each non-empty entry
     * emits one `<flag>=<entry>` token.
     *
     * @var array<string,string>
     */
    const LIST_FLAGS = [
        'excludes' => '--exclude',
        'includes' => '--include',
    ];

    /**
     * The canonical rsync binary location. rsync ships in Unraid's BASE OS at
     * /usr/bin/rsync, so it is ALWAYS present on a healthy system - the plugin
     * deliberately does NOT install rsync (there is no clean Slackware artifact
     * to pin, and a bundled copy would shadow the base binary). We only guard
     * against a broken/misconfigured system with a defensive presence check
     * (rsyncAvailable() below), mirroring how sshpass is detect-and-degrade -
     * except here the expected state is "present", not "optional".
     *
     * Overridable for tests via $rsyncPathOverride so the run path can be driven
     * with a present/absent binary without touching the real /usr/bin/rsync.
     */
    const RSYNC_PATH = '/usr/bin/rsync';

    /**
     * Optional explicit rsync path override (tests set this; '' simulates a
     * missing binary). When null, rsyncPath() returns the RSYNC_PATH constant.
     *
     * @var string|null
     */
    public static $rsyncPathOverride = null;

    /** Resolve the rsync binary path to check/run (honours the test override). */
    public static function rsyncPath(): string
    {
        if (static::$rsyncPathOverride !== null) {
            return (string) static::$rsyncPathOverride;
        }
        return self::RSYNC_PATH;
    }

    /**
     * True when the rsync binary is present and executable. rsync is part of
     * Unraid's base OS, so this should normally always be true; a false here
     * means the system is misconfigured. PURE-ish: a single is_executable()
     * stat, no process spawned. Overridable via $rsyncPathOverride / the
     * isExecutable() seam.
     */
    public static function rsyncAvailable(): bool
    {
        $path = static::rsyncPath();
        if ($path === '') {
            return false;
        }
        return static::isExecutable($path);
    }

    /** The user-facing message logged when rsync is missing from the base OS. */
    public static function rsyncMissingMessage(): string
    {
        return 'rsync not found at ' . static::rsyncPath()
            . ' - it is normally part of Unraid; your system may be misconfigured.';
    }

    /**
     * The first line of `rsync --version` (e.g. "rsync  version 3.2.7 ...") for
     * display on the Status tab, or '' when rsync is absent / the probe fails.
     * The version probe is injectable via the runVersionProbe() seam so the UI
     * helper is testable without spawning a process.
     */
    public static function rsyncVersionLine(): string
    {
        if (!static::rsyncAvailable()) {
            return '';
        }
        $out = static::runVersionProbe(static::rsyncPath());
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    /**
     * is_executable() seam (overridable in tests). Kept protected so a test
     * subclass can simulate present/absent without touching the filesystem.
     */
    protected static function isExecutable(string $path): bool
    {
        return is_executable($path);
    }

    /**
     * Run `<rsync> --version` (no shell) and return its STDOUT (rsync prints its
     * version banner to stdout). stderr is fully drained too - even though the
     * version banner goes to stdout, leaving stderr unread risks the child
     * blocking if it ever writes there, so we read both before proc_close. Live
     * implementation uses proc_open with the argv ARRAY. Overridable in tests.
     */
    protected static function runVersionProbe(string $rsyncPath): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open([$rsyncPath, '--version'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return '';
        }
        // Drain BOTH pipes so the child can never block writing to a full stderr
        // buffer while we wait on stdout. We return stdout (the version banner).
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // drain + discard stderr
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return is_string($stdout) ? $stdout : '';
    }

    // --- TrueNAS-style state names (also used in the run summary on /boot). ---
    const STATE_SUCCESS = 'SUCCESS';
    const STATE_WARNING = 'WARNING';
    const STATE_PARTIAL = 'PARTIAL';
    const STATE_TIMEOUT = 'TIMEOUT';
    const STATE_ABORTED = 'ABORTED';
    const STATE_FAILED  = 'FAILED';
    const STATE_PENDING = 'PENDING';   // never run; represented by NO summary.

    /**
     * Resolve the EFFECTIVE rsync options for a job: when useGlobalDefaults is
     * on, the job uses the global default options; otherwise its own options.
     * Both are run through Config::mergeRsyncOptions so the shape is always the
     * full, canonical whitelist (no missing keys).
     *
     * @param array<string,mixed> $job    a (normalised) job
     * @param array<string,mixed> $global the config's `global` block
     * @return array<string,mixed>
     */
    public static function effectiveOptions(array $job, array $global): array
    {
        $useGlobal = !empty($job['useGlobalDefaults']);
        if ($useGlobal) {
            $opts = (isset($global['defaultRsyncOptions']) && is_array($global['defaultRsyncOptions']))
                ? $global['defaultRsyncOptions']
                : [];
        } else {
            $opts = (isset($job['rsyncOptions']) && is_array($job['rsyncOptions']))
                ? $job['rsyncOptions']
                : [];
        }
        return Config::mergeRsyncOptions($opts);
    }

    /**
     * Map a (canonical) options object to its rsync flag tokens, in a stable
     * order: booleans (in BOOL_FLAGS order), then scalars (SCALAR_FLAGS order),
     * then list flags (excludes, then includes). PURE.
     *
     * @param array<string,mixed> $opts a canonical whitelist options object
     * @return array<int,string>
     */
    public static function optionTokens(array $opts): array
    {
        $tokens = [];

        foreach (self::BOOL_FLAGS as $key => $flag) {
            if (!empty($opts[$key])) {
                $tokens[] = $flag;
            }
        }

        foreach (self::SCALAR_FLAGS as $key => $flag) {
            $val = isset($opts[$key]) ? trim((string) $opts[$key]) : '';
            if ($val === '') {
                continue;
            }
            // backup-dir only works in tandem with --backup; add it once, just
            // before the --backup-dir token, so rsync actually keeps backups.
            if ($key === 'backupDir') {
                $tokens[] = '--backup';
            }
            $tokens[] = $flag . '=' . $val;
        }

        foreach (self::LIST_FLAGS as $key => $flag) {
            if (!isset($opts[$key]) || !is_array($opts[$key])) {
                continue;
            }
            foreach ($opts[$key] as $entry) {
                $val = trim((string) $entry);
                if ($val !== '') {
                    $tokens[] = $flag . '=' . $val;
                }
            }
        }

        return $tokens;
    }

    /**
     * The verbosity flags for a log level. PURE. Always paired with
     * --log-file=<runlog> by buildArgv(); these are only the verbosity/info
     * tokens, per the plan's "Log levels -> flags" table.
     *
     * @return array<int,string>
     */
    public static function logLevelFlags(string $logLevel): array
    {
        switch ($logLevel) {
            case 'quiet':
                return ['-q'];
            case 'verbose':
                return ['-vv', '--info=progress2,stats2', '--itemize-changes'];
            case 'debug':
                return ['-vvv', '--debug=all', '--stderr=all'];
            case 'normal':
            default:
                return ['-v', '--info=stats2,progress2'];
        }
    }

    /**
     * Compose the FULL rsync argv array for a single source->dest pair.
     *
     * Order:
     *   [sshpassPrefix...]                         (PASSWORD auth only; [] otherwise)
     *   <rsyncPath()>                              (the resolved binary, default /usr/bin/rsync)
     *   <whitelisted option tokens>
     *   <log-level verbosity flags>
     *   --log-file=<runLog>
     *   [--dry-run]                                (when $dryRun)
     *   -e <dashE>                                 (SSH transport only)
     *   --
     *   <src> <dest>
     *
     * The leading `--` ends rsync option parsing so a path beginning with '-'
     * can never be read as a flag (option-injection guard on top of Job.php's
     * path guardrails). The sshpass prefix wraps the WHOLE rsync process (it is
     * the program rsync runs under), so it is prepended to the argv, NOT folded
     * into -e (see Ssh.php).
     *
     * @param array<string,mixed> $opts    canonical whitelist options
     * @param string              $logLevel one of Job::LOG_LEVELS
     * @param string              $runLog   absolute path of the per-run log file
     * @param string              $src      source operand (already direction-resolved)
     * @param string              $dest     destination operand
     * @param array{dashE?:string,sshpassPrefix?:array<int,string>}|null $ssh
     *        SSH transport pieces from Ssh::materialize(); null/[] for LOCAL.
     * @param bool                $dryRun
     * @return array<int,string>
     */
    public static function buildArgv(
        array $opts,
        string $logLevel,
        string $runLog,
        string $src,
        string $dest,
        ?array $ssh = null,
        bool $dryRun = false
    ): array {
        $sshpassPrefix = [];
        $dashE         = '';
        if (is_array($ssh)) {
            if (isset($ssh['sshpassPrefix']) && is_array($ssh['sshpassPrefix'])) {
                $sshpassPrefix = $ssh['sshpassPrefix'];
            }
            if (isset($ssh['dashE']) && is_string($ssh['dashE'])) {
                $dashE = $ssh['dashE'];
            }
        }

        $argv = [];
        foreach ($sshpassPrefix as $tok) {
            $argv[] = (string) $tok;
        }

        // Use the SAME resolved binary the presence check validates
        // (rsyncPath(), default /usr/bin/rsync) as argv[0], rather than the bare
        // name "rsync" resolved via PATH. This closes a gap where rsyncAvailable()
        // could pass for /usr/bin/rsync while a different "rsync" earlier on PATH
        // actually ran (a PATH-hijack vector), and makes $rsyncPathOverride affect
        // the real run, not just the check. proc_open is fed the argv array
        // without a shell, so this absolute path is exec'd directly.
        $argv[] = self::rsyncPath();

        foreach (self::optionTokens($opts) as $tok) {
            $argv[] = $tok;
        }
        foreach (self::logLevelFlags($logLevel) as $tok) {
            $argv[] = $tok;
        }

        $argv[] = '--log-file=' . $runLog;

        if ($dryRun) {
            $argv[] = '--dry-run';
        }

        if ($dashE !== '') {
            $argv[] = '-e';
            $argv[] = $dashE;
        }

        $argv[] = '--';
        $argv[] = $src;
        $argv[] = $dest;

        return $argv;
    }

    /**
     * Map a process exit code (or terminating signal, encoded as 128+signal by
     * the shell convention) to a TrueNAS-style run state. PURE.
     *
     *   0          -> SUCCESS
     *   24, 25     -> WARNING   (24: files vanished; 25: --max-delete limit hit)
     *   23         -> PARTIAL   (partial transfer due to some files/attrs)
     *   30, 35     -> TIMEOUT   (30: I/O timeout; 35: --contimeout reached)
     *   20, 143    -> ABORTED   (20: SIGUSR1/SIGINT/SIGTERM seen by rsync;
     *                            143 = 128+15, killed by SIGTERM - our abort path)
     *   anything else -> FAILED
     *
     * @return string one of the STATE_* constants (never STATE_PENDING).
     */
    public static function exitToState(int $exitCode): string
    {
        switch ($exitCode) {
            case 0:
                return self::STATE_SUCCESS;
            case 24:
            case 25:
                return self::STATE_WARNING;
            case 23:
                return self::STATE_PARTIAL;
            case 30:
            case 35:
                return self::STATE_TIMEOUT;
            case 20:
            case 143: // 128 + SIGTERM(15): the abort path SIGTERMs rsync.
                return self::STATE_ABORTED;
            default:
                return self::STATE_FAILED;
        }
    }

    /**
     * Reduce a set of per-pair exit codes to the single WORST state for the run.
     * "Worst" follows a severity ordering so a run that had one FAILED pair and
     * one SUCCESS pair is reported FAILED. ABORTED outranks everything (the user
     * stopped the run), then FAILED, TIMEOUT, PARTIAL, WARNING, SUCCESS.
     *
     * @param array<int,int> $exitCodes per-pair exit codes (in run order)
     * @return array{state:string,exitCode:int} the worst state + the exit code
     *         that produced it (0 with SUCCESS when no pairs ran).
     */
    public static function worstOutcome(array $exitCodes): array
    {
        // Higher rank = worse.
        $rank = [
            self::STATE_SUCCESS => 0,
            self::STATE_WARNING => 1,
            self::STATE_PARTIAL => 2,
            self::STATE_TIMEOUT => 3,
            self::STATE_FAILED  => 4,
            self::STATE_ABORTED => 5,
        ];

        $worstState = self::STATE_SUCCESS;
        $worstCode  = 0;
        $worstRank  = -1;

        foreach ($exitCodes as $code) {
            $code  = (int) $code;
            $state = self::exitToState($code);
            $r     = $rank[$state] ?? 0;
            if ($r > $worstRank) {
                $worstRank  = $r;
                $worstState = $state;
                $worstCode  = $code;
            }
        }

        return ['state' => $worstState, 'exitCode' => $worstCode];
    }

    /**
     * Run an rsync argv array to completion, streaming combined stdout+stderr
     * to a callback line-buffer, and return the exit code. proc_open is fed the
     * ARGV ARRAY directly (no shell), so nothing is re-parsed.
     *
     * The spawn is injectable for tests: set Rsync::$runner to a callable
     * `fn(array $argv, callable $onOutput): int` and this method delegates to it
     * instead of touching a real binary. The default $runner uses proc_open.
     *
     * @param array<int,string>          $argv     full rsync argv (incl. any sshpass prefix)
     * @param callable(string):void|null $onOutput called with each output chunk
     * @return int the process exit code (128+signal when terminated by a signal)
     */
    public static function run(array $argv, ?callable $onOutput = null): int
    {
        $sink = $onOutput ?? static function (string $_chunk): void {
            // default: discard (the run log is fed by rsync's own --log-file).
        };

        if (self::$runner !== null) {
            return (self::$runner)($argv, $sink);
        }
        return self::defaultRun($argv, $sink);
    }

    /**
     * Injectable process runner. null => use defaultRun (real proc_open).
     * Signature: fn(array $argv, callable(string):void $onOutput): int
     *
     * @var callable|null
     */
    public static $runner = null;

    /**
     * The live process runner: proc_open with the argv array (no shell), pumping
     * combined child output to $onOutput and returning the exit code. A negative
     * proc_close result (signalled child) is normalised to 128+signal so the
     * SIGTERM abort path maps cleanly to 143 -> ABORTED.
     *
     * @param array<int,string>   $argv
     * @param callable(string):void $onOutput
     */
    private static function defaultRun(array $argv, callable $onOutput): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc  = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $onOutput("Failed to start rsync.\n");
            return 127;
        }

        // Non-blocking reads so a slow/long transfer streams to the log rather
        // than buffering until exit.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while (!empty($open)) {
            $read   = array_values($open);
            $write  = null;
            $except = null;
            // Block up to 1s waiting for output on either pipe.
            $n = @stream_select($read, $write, $except, 1);
            if ($n === false) {
                break;
            }
            foreach ($open as $fd => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }
                    continue;
                }
                $onOutput($chunk);
            }
        }

        // Close any pipe stream_select left open (e.g. on a select error that
        // broke the loop above before EOF). Leaking the fd would not hang the
        // run - proc_close + the group SIGTERM still tear the child down - but
        // closing them keeps the fd table tidy across a long-lived runner.
        // Mirrors KeyTools::runArgv. [ROB-01]
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Capture the final status BEFORE proc_close. proc_close only returns
        // the exit CODE and gives -1 once the child has already been reaped, so
        // a process killed by a signal (our SIGTERM abort) would otherwise be
        // lost. proc_get_status reports `signaled` + `termsig`, which we encode
        // as 128+signal (the shell convention) so SIGTERM(15) -> 143 -> ABORTED.
        $status = @proc_get_status($proc);
        $code   = proc_close($proc);

        if (is_array($status)) {
            if (!empty($status['signaled']) && isset($status['termsig'])) {
                return 128 + (int) $status['termsig'];
            }
            // While `running` is false the reported exitcode is authoritative;
            // proc_close may already have returned -1 by the time we get here.
            if (array_key_exists('exitcode', $status) && (int) $status['exitcode'] >= 0) {
                return (int) $status['exitcode'];
            }
        }

        // Fallback to proc_close's value; a negative (already-reaped) result has
        // no usable code, so treat it as a generic failure.
        return $code < 0 ? 1 : $code;
    }
}
