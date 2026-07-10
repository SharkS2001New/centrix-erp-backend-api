<?php

namespace Tests\Unit;

use App\Support\SalesChannelLabels;
use Tests\TestCase;

class SalesChannelLabelsTest extends TestCase
{
    public function test_backend_and_backoffice_normalize_to_backoffice_metric(): void
    {
        $this->assertSame('backoffice', SalesChannelLabels::metricKey('backend'));
        $this->assertSame('backoffice', SalesChannelLabels::metricKey('backoffice'));
        $this->assertSame('backoffice', SalesChannelLabels::metricKey('BACKEND'));
    }

    public function test_backoffice_channel_label(): void
    {
        $this->assertSame('Backoffice', SalesChannelLabels::label('backend'));
        $this->assertSame('Backoffice', SalesChannelLabels::label('backoffice'));
        $this->assertSame('Backoffice', SalesChannelLabels::label('erp'));
    }

    public function test_other_channel_labels(): void
    {
        $this->assertSame('POS', SalesChannelLabels::label('pos'));
        $this->assertSame('Mobile', SalesChannelLabels::label('mobile'));
        $this->assertSame('WhatsApp', SalesChannelLabels::label('whatsapp'));
    }
}
