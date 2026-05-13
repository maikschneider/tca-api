<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TCA API',
    'description' => 'REST API based on TYPO3 TCA — exposes database tables as Hydra JSON-LD resources.',
    'category' => 'misc',
    'author' => 'Maik Schneider',
    'author_email' => 'schneider.maik@me.com',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'frontend' => '',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
