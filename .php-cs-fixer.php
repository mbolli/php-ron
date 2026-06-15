<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

/*
 * Mirrors the moxiframework ruleset, with one deliberate difference:
 * `mb_str_functions` is DISABLED. This is a byte-oriented parser; rewriting
 * strlen/substr/strpos to their mb_* equivalents would change byte offsets to
 * character offsets and break the scanner and renderers.
 */
return (new Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@auto:risky' => true,
        '@autoPHPMigration:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'braces_position' => ['classes_opening_brace' => 'same_line', 'functions_opening_brace' => 'same_line'],
        'concat_space' => ['spacing' => 'one'],
        'control_structure_continuation_position' => ['position' => 'same_line'],
        'declare_strict_types' => true,
        'final_internal_class' => false,
        'mb_str_functions' => false,
        // PHPUnit 11+ deprecates doc-comment metadata; do not inject @internal/@coversNothing.
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'operator_linebreak' => true,
        'phpdoc_align' => ['align' => 'vertical', 'tags' => ['method', 'param', 'return', 'property', 'return', 'throws', 'type']],
        'phpdoc_to_comment' => ['allow_before_return_statement' => true, 'ignored_tags' => ['var', 'phpstan-ignore', 'phpstan-ignore-next-line', 'psalm-suppress']],
        'single_line_empty_body' => true,
        'static_lambda' => false,
        'string_implicit_backslashes' => ['double_quoted' => 'escape', 'single_quoted' => 'ignore', 'heredoc' => 'escape'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder(Finder::create()->exclude(['vendor', 'tests/corpus'])->in(__DIR__))
;
