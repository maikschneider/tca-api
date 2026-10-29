<?php

declare(strict_types=1);

// Inline children of a versioned table must be versioned as well (article.related_items_inline).
$GLOBALS['TCA']['tx_myext_domain_model_color']['ctrl']['versioningWS'] = true;
