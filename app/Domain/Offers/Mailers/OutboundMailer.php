<?php

namespace App\Domain\Offers\Mailers;

use App\Domain\Offers\Models\OutboundEmail;
use RuntimeException;

/**
 * Verstuurt een goedgekeurde uitgaande mail (§13.2).
 */
interface OutboundMailer
{
    /**
     * @return array{provider: string, provider_message_id: string|null, internet_message_id: string, conversation_id: string, raw_mime: string}
     *
     * @throws RuntimeException
     */
    public function send(OutboundEmail $email): array;
}
