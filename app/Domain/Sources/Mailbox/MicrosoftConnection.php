<?php

namespace App\Domain\Sources\Mailbox;

/**
 * Een plek waar een Microsoft 365-verbinding (vernieuwingstoken) wordt bewaard: een bron (de
 * aanvragen-mailbox, lezen) of de afzendermailbox (versturen). {@see MicrosoftLogin} doet de
 * inlogstroom; de verbinding bepaalt de rechten (scopes) en de opslag.
 */
interface MicrosoftConnection
{
    /** Unieke sleutel voor caches, bijv. "source:5" of "sender". */
    public function key(): string;

    /** Gevraagde rechten, gescheiden door spaties. */
    public function scopes(): string;

    public function refreshToken(): ?string;

    public function tenant(): string;

    public function account(): ?string;

    /** Mailbox waarvoor de verbinding dient (voor de uitleg in het inlogvenster). */
    public function mailbox(): string;

    public function store(string $refreshToken, string $tenant, string $account): void;

    public function forget(): void;
}
