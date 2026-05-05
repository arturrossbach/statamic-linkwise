<?php

namespace Arturrossbach\Linkwise\Subscribers;

use Illuminate\Events\Dispatcher;
use Statamic\Events\EntryBlueprintFound;

class EntryBlueprintSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntryBlueprintFound::class, self::class.'@addLinkwiseField');
    }

    public function addLinkwiseField(EntryBlueprintFound $event): void
    {
        $event->blueprint->ensureFieldInTab('linkwise', [
            'type' => 'linkwise',
            'display' => 'Linkwise',
            'hide_display' => true,
            'listable' => false,
        ], 'sidebar');
    }
}
