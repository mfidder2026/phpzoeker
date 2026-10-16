<?php

use App\Filament\Clusters\Settings\Pages\MailSettingsPage;
use App\Settings\MailSettings;
use Livewire\Livewire;

it('saves the mail settings with an empty signature', function () {
    $this->actingAs(beheerder());

    Livewire::test(MailSettingsPage::class)
        ->set('data.signature_html', null)
        ->set('data.signature_text', null)
        ->call('save')
        ->assertHasNoErrors();

    app()->forgetInstance(MailSettings::class);

    expect(app(MailSettings::class)->signature_html)->toBe('')
        ->and(app(MailSettings::class)->signature_text)->toBe('');
});
