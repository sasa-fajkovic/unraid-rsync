<?php

/**
 * php-cs-fixer config: a PSR-12 formatting gate for the plugin's PHP.
 *
 * Scope: the pure-PHP backend (source/include, source/scripts) and the test
 * suite - the same backend PHPStan analyses. Deliberately EXCLUDED:
 *   - source/pages/*.php and *.page: mixed HTML/PHP templates where reflowing
 *     whitespace around `?>` could alter rendered output; their PHP is already
 *     linted by `php -l` in CI.
 *
 * Risky rules are allowed ONLY to enforce `declare_strict_types`; @PSR12 itself
 * is a non-risky set, so no other behaviour-changing fixer activates. Everything
 * else is purely cosmetic. binary_operator_spaces is relaxed to
 * "at_least_single_space" so the codebase's intentional, readable column
 * alignment of => / = is kept (PSR-12 does not mandate single-spacing, and
 * forcing it would be pure churn).
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/source/include')
    ->in(__DIR__ . '/source/scripts')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        // Modern PHP best practice: enforce strict scalar typing in every backend
        // file. The pages/*.page templates are out of scope (a declare can't
        // follow their header/markup). This is the ONLY risky rule enabled.
        'declare_strict_types' => true,
        // The code is already clean and CONSISTENT - it just isn't vanilla PSR-12
        // in a few deliberate, readable ways. Honour the existing house style so
        // the gate enforces standards (indentation, spacing, final newlines, no
        // unused imports, ...) and prevents drift WITHOUT a noisy one-time rewrite
        // of carefully-formatted code:
        //   - keep the intentional column alignment of => / = (PSR-12 doesn't
        //     mandate single-spacing);
        'binary_operator_spaces' => ['default' => 'at_least_single_space'],
        //   - keep aligned ternaries (the author aligns ? / : across lines);
        'ternary_operator_spaces' => false,
        //   - keep `fn(` with no space, used consistently throughout;
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        //   - keep the compact, column-aligned switch arms in handler.php
        //     (`case 'x': ur_action_x(); return;` on one line);
        'no_multiple_statements_per_line' => false,
        //   - don't re-flow the author's hand-wrapped multi-line calls;
        'method_argument_space' => ['on_multiline' => 'ignore'],
        //   - constants are written without an explicit `public` (the default);
        //     keep visibility enforcement to properties + methods only.
        //     (modifier_keywords is the non-deprecated successor of
        //     visibility_required that @PSR12 applies.)
        'modifier_keywords' => ['elements' => ['property', 'method']],
    ])
    ->setFinder($finder);
