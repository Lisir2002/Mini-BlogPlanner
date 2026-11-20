<?php

declare(strict_types=1);

use Core\Database\ConnectionInterface;
use Core\Database\Migration;

return new Migration(
    scope: 'core',
    version: 2026112007,
    name: 'add_post_search_index',
    reversible: true,
    irreversible: false,
    up: static function (ConnectionInterface $db): void {
        $db->execute(
            'ALTER TABLE posts ADD FULLTEXT KEY ft_posts_search (title, excerpt, body) WITH PARSER ngram'
        );
    },
    down: static function (ConnectionInterface $db): void {
        $db->execute('ALTER TABLE posts DROP INDEX ft_posts_search');
    },
);
