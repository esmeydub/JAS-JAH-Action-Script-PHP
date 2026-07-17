<?php

declare(strict_types=1);

return [
    'domain' => 'Feeds',
    'name' => 'feed.read',
    'input' => 'FeedQuery',
    'output' => 'FeedResult',
    'capability' => 'feeds.read',
    'audit' => true,
];
