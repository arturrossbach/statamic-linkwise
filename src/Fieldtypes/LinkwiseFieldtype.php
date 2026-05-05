<?php

namespace Inkline\Linkwise\Fieldtypes;

use Statamic\Fields\Fieldtype;

class LinkwiseFieldtype extends Fieldtype
{
    protected static $handle = 'linkwise';

    protected $selectable = false;

    protected $icon = 'link';

    public function preProcess(mixed $data): mixed
    {
        return $data;
    }

    public function process(mixed $data): mixed
    {
        return $data;
    }
}
