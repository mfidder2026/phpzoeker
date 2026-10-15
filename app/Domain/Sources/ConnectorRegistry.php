<?php

namespace App\Domain\Sources;

use App\Domain\Sources\Connectors\FeedConnector;
use App\Domain\Sources\Connectors\MailboxAlertConnector;
use App\Domain\Sources\Connectors\ManualConnector;
use App\Domain\Sources\Contracts\SourceConnector;
use App\Domain\Sources\Enums\SourceType;
use RuntimeException;

/**
 * Koppelt een brontype aan zijn connector. Nieuwe bron = één connectorklasse + configuratie (§20).
 */
class ConnectorRegistry
{
    public function for(SourceType $type): SourceConnector
    {
        return match ($type) {
            SourceType::Feed => app(FeedConnector::class),
            SourceType::MailboxAlert => app(MailboxAlertConnector::class),
            SourceType::Manual => app(ManualConnector::class),
            default => throw new RuntimeException("Voor brontype '{$type->getLabel()}' is nog geen connector gebouwd."),
        };
    }
}
