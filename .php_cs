<?php

namespace PhpCsFixer;

$finder = Finder::create()
    ->exclude('bootstrap/cache')
    ->exclude('public')
    ->exclude('resources')
    ->exclude('storage')
    ->exclude('vendor')
    ->exclude('_ide_helper.php')
    ->in(__DIR__)
;

return Config::create()
    ->setUsingCache(true)
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_multiline_whitespace_before_semicolons' => true,
        'no_short_echo_tag' => true,
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => true,
        'ordered_imports' => true,
        'phpdoc_order' => true,
    ])
    ->setFinder($finder)
;