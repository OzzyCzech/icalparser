<?php
declare(strict_types=1);

/**
 * Coding standard of the project: PER-CS with tabs and opening braces on the same line.
 */
$finder = (new PhpCsFixer\Finder())
	->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/example'])
	->name(['*.php', '*.phpt'])
	->exclude(['output']);

return (new PhpCsFixer\Config())
	->setRiskyAllowed(true)
	->setIndent("\t")
	->setLineEnding("\n")
	->setFinder($finder)
	->setRules([
		'@PER-CS2.0' => true,
		'braces_position' => [
			'classes_opening_brace' => 'same_line',
			'functions_opening_brace' => 'same_line',
			'anonymous_classes_opening_brace' => 'same_line',
			'anonymous_functions_opening_brace' => 'same_line',
		],
		'declare_strict_types' => true,
		'no_unused_imports' => true,
		'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
		'single_quote' => true,
		'no_extra_blank_lines' => true,
		'no_trailing_whitespace' => true,
		'no_whitespace_in_blank_line' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
		'concat_space' => ['spacing' => 'one'],
		'blank_line_after_opening_tag' => false,
		'linebreak_after_opening_tag' => false,
		'single_line_empty_body' => false,
		'method_argument_space' => ['on_multiline' => 'ignore'],
		'statement_indentation' => ['stick_comment_to_next_continuous_control_statement' => false],
	]);
