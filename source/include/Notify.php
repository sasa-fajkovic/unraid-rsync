<?php
/**
 * Notify.php - a thin, escapeshellarg-safe wrapper over Unraid's native
 * notification CLI (/usr/local/emhttp/webGui/scripts/notify). It is the single
 * place the plugin emits a webGui notification (the toast/bell + any configured
 * agent: e-mail, Pushover, Discord, ...).
 *
 * WHY a wrapper (and not exec'ing notify inline at the call site):
 *   - The `notify` script is a shell script with positional, dash-flagged
 *     arguments (-e/-s/-d/-i/-m/-l). It is NOT exposed as something we can drive
 *     via proc_open with an argv array the way rsync is - the only robust,
 *     portable way to invoke it is a single shell command line. That means EVERY
 *     argument we pass - the event name, subject, description, importance (-i),
 *     message and link - is attacker-influenceable (a job name can be anything
 *     the user typed) and MUST be escapeshellarg'd. This class guarantees that:
 *     buildCommand() quotes EVERY token, including the literal "-i" value, so a
 *     job named `; rm -rf /` becomes a harmless quoted string, never a second
 *     command.
 *   - It makes dispatch INJECTABLE for tests: set Notify::$runner to a callable
 *     `fn(string $command): int` and the class records the exact command line
 *     WITHOUT touching the real notify script (which does not exist off-Unraid).
 *   - It DEGRADES GRACEFULLY: if the notify binary is absent (e.g. a stripped
 *     box, or the unit-test host), send() is a silent no-op that returns false -
 *     it never throws. A failed notification must NEVER fail a backup run, so
 *     Runner::notifyHook calls this inside the run's `finally` and swallows any
 *     surprise.
 *
 * Importance values mirror the webGui's own vocabulary: `normal` | `warning` |
 * `alert` (the Unraid notify `-i` levels). The plugin maps run states onto these
 * in Runner::notifyHook.
 *
 * Overridable seams (mirroring Ssh/Rsync):
 *   - Notify::$notifyPath  the notify binary path (default: the webGui script).
 *                          Tests point it at a real-looking-but-fake path or ''
 *                          to exercise the missing-binary no-op.
 *   - Notify::$runner      fn(string $command): int. null => the live runner
 *                          (exec()). Tests capture the command and assert the
 *                          argv/escaping without spawning anything.
 */

class Notify
{
    /** The three webGui importance levels accepted by `notify -i`. */
    const IMPORTANCE_NORMAL  = 'normal';
    const IMPORTANCE_WARNING = 'warning';
    const IMPORTANCE_ALERT   = 'alert';

    /**
     * Path to Unraid's native notify CLI. Overridable for tests so the command
     * construction + missing-binary handling can be exercised off-Unraid.
     *
     * @var string
     */
    public static $notifyPath = '/usr/local/emhttp/webGui/scripts/notify';

    /**
     * Injectable command runner: fn(string $command): int returning the exit
     * code. null => the live runner (exec()). Tests set this to capture the
     * exact shell command WITHOUT invoking the real notify script.
     *
     * @var callable|null
     */
    public static $runner = null;

    /**
     * True when the notify binary is present + executable on this box (honouring
     * the test override). When false, send() is a graceful no-op.
     */
    public static function available(): bool
    {
        $path = (string) static::$notifyPath;
        if ($path === '') {
            return false;
        }
        return is_file($path) && is_executable($path);
    }

    /**
     * Build the full shell command line for a notification. EVERY token is
     * individually escapeshellarg'd - the binary path, every flag literal
     * (including "-i"), and every value - so nothing the user typed (a job name,
     * an arbitrary state, a numeric exit code rendered as text) can break out of
     * its argument and inject a second command. Empty optional fields are simply
     * omitted (we never emit a flag with an empty value).
     *
     * @param array{event?:string,subject?:string,description?:string,importance?:string,message?:string,link?:string} $opts
     * @return string the ready-to-exec command line
     */
    public static function buildCommand(array $opts): string
    {
        $path = (string) static::$notifyPath;

        // Map of flag => provided value; only non-empty values are appended.
        // Importance is normalised to a known level (defaults to normal) so a
        // bogus value can never reach the CLI.
        $flags = [
            '-e' => isset($opts['event'])       ? (string) $opts['event']       : '',
            '-s' => isset($opts['subject'])     ? (string) $opts['subject']     : '',
            '-d' => isset($opts['description']) ? (string) $opts['description'] : '',
            '-i' => static::normalizeImportance(isset($opts['importance']) ? (string) $opts['importance'] : ''),
            '-m' => isset($opts['message'])     ? (string) $opts['message']     : '',
            '-l' => isset($opts['link'])        ? (string) $opts['link']        : '',
        ];

        $tokens = [escapeshellarg($path)];
        foreach ($flags as $flag => $value) {
            if ($value === '') {
                continue;
            }
            // Quote the FLAG LITERAL too (incl. "-i"), per the safety contract.
            $tokens[] = escapeshellarg($flag);
            $tokens[] = escapeshellarg($value);
        }

        return implode(' ', $tokens);
    }

    /**
     * Coerce an importance value to one of the three webGui levels. An unknown
     * or empty value falls back to `normal` so the CLI always receives a valid
     * level (and never a user-influenced raw string as the importance).
     */
    public static function normalizeImportance(string $importance): string
    {
        switch ($importance) {
            case self::IMPORTANCE_ALERT:
                return self::IMPORTANCE_ALERT;
            case self::IMPORTANCE_WARNING:
                return self::IMPORTANCE_WARNING;
            case self::IMPORTANCE_NORMAL:
            default:
                return self::IMPORTANCE_NORMAL;
        }
    }

    /**
     * Send a notification. Returns true when the notify command ran and exited 0,
     * false on any failure (binary missing, build failure, non-zero exit, or an
     * unexpected throw). NEVER throws - a failed notification must not be able to
     * fail the caller (the runner dispatches this inside its `finally`).
     *
     * @param array{event?:string,subject?:string,description?:string,importance?:string,message?:string,link?:string} $opts
     */
    public static function send(array $opts): bool
    {
        try {
            if (!static::available()) {
                // No notify binary on this box - silent, harmless no-op.
                return false;
            }
            $command = static::buildCommand($opts);
            $code    = static::dispatch($command);
            return $code === 0;
        } catch (Throwable $e) {
            // Swallow: notification is best-effort and must never propagate.
            return false;
        }
    }

    /**
     * Build the `notify init` command line - the one-time idempotent call that
     * initialises the notification subsystem's permissions on install/upgrade.
     * The binary path is escapeshellarg'd and "init" is a fixed literal.
     */
    public static function buildInitCommand(): string
    {
        return escapeshellarg((string) static::$notifyPath) . ' init';
    }

    /**
     * Run `notify init` (initialise the notification subsystem - idempotent).
     * Graceful no-op + false when the binary is absent; never throws. Wired into
     * the .plg install path; exposed here so the same escaping/runner seam is
     * unit-tested rather than duplicated in shell.
     */
    public static function init(): bool
    {
        try {
            if (!static::available()) {
                return false;
            }
            return static::dispatch(static::buildInitCommand()) === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Run the built command. Delegates to the injectable $runner in tests; the
     * live path uses exec() (the notify script is a positional-arg shell script,
     * not an argv-friendly binary). The command is fully escapeshellarg'd by
     * buildCommand(), so this is the one intentional shell invocation and it only
     * ever sees already-quoted tokens.
     */
    private static function dispatch(string $command): int
    {
        if (static::$runner !== null) {
            return (int) (static::$runner)($command);
        }
        $output = [];
        $code   = 0;
        // stderr folded into the (discarded) capture so a failing notify is quiet.
        @exec($command . ' 2>/dev/null', $output, $code);
        return (int) $code;
    }
}
