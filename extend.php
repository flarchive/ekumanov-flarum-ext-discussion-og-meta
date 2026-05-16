<?php

use Ekumanov\DiscussionOgMeta\AddOgMetaTags;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->content(AddOgMetaTags::class),
];
