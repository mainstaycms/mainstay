<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Attribute;
use Mainstay\Fields\Field;

#[Attribute(Attribute::TARGET_PROPERTY)]
class SeoMeta extends Field
{
    protected string $viewNamespace = 'seo';

    public function column(): array
    {
        return ['string', 160];
    }

    protected function json(): array
    {
        return ['type' => 'string'];
    }
}
