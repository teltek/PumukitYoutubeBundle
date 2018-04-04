<?php

$rules = array(
    '@Symfony' => true
);

$config = PhpCsFixer\Config::create()
    ->setRules($rules)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('vendor')
            ->in(__DIR__)
    );

return $config;
