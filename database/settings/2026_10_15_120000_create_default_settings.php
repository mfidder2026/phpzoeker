<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Standaardwaarden uit §16.5. Alles is daarna aan te passen in Instellingen (R6).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.organization_name', 'Fremen');
        $this->migrator->add('general.admin_email', 'michel@fremen.app');
        $this->migrator->add('general.default_deadline_time', '12:00');
        $this->migrator->add('general.countries', ['NL']);

        $this->migrator->add('mail.provider', 'smtp');
        $this->migrator->add('mail.sender_mailbox', 'michel@fremen.app');
        $this->migrator->add('mail.sender_name', 'Michel — Fremen');
        $this->migrator->add('mail.default_offer_cc', ['mark@fremen.app', 'michel@fremen.app']);
        $this->migrator->add('mail.skip_cc_to_sender', false);
        $this->migrator->add('mail.bcc_archive', null);
        $this->migrator->add('mail.signature_html', '');
        $this->migrator->add('mail.signature_text', '');
        $this->migrator->add('mail.require_candidate_consent', true);
        $this->migrator->add('mail.alerts_mailbox', 'opdrachten@fremen.app');

        $this->migrator->add('matching.strong_threshold', 75);
        $this->migrator->add('matching.possible_threshold', 60);
        $this->migrator->add('matching.weights', [
            'requirements' => 40,
            'wishes' => 15,
            'technology' => 15,
            'seniority' => 10,
            'rate' => 10,
            'location' => 5,
            'hours' => 5,
        ]);
        $this->migrator->add('matching.start_date_tolerance_days', 14);
        $this->migrator->add('matching.embedding_prefilter_threshold', 0.35);

        $this->migrator->add('register.default_response_term_days', 5);
        $this->migrator->add('register.follow_up_term_days', 3);
        $this->migrator->add('register.no_response_after_follow_ups', 2);
        $this->migrator->add('register.no_response_after_workdays', 10);
        $this->migrator->add('register.expected_response_time', '17:00');

        $this->migrator->add('notifications.digest_time', '08:00');
        $this->migrator->add('notifications.digest_recipients', ['michel@fremen.app']);
        $this->migrator->add('notifications.digest_to_candidates', false);
        $this->migrator->add('notifications.teams_webhook_url', null);

        $this->migrator->add('cv.contact_line', 'fremen');
        $this->migrator->add('cv.fremen_contact_name', 'Michel');
        $this->migrator->add('cv.fremen_contact_email', 'michel@fremen.app');
        $this->migrator->add('cv.fremen_contact_phone', '');
        $this->migrator->add('cv.show_birth_date', false);
        $this->migrator->add('cv.show_city', true);
        $this->migrator->add('cv.show_driving_license', 'onsite_hybrid');
        $this->migrator->add('cv.footer_text', '');

        $this->migrator->add('privacy.raw_postings_days', 90);
        $this->migrator->add('privacy.closed_assignments_months', 12);
        $this->migrator->add('privacy.matches_months', 12);
        $this->migrator->add('privacy.offers_months', 24);
        $this->migrator->add('privacy.contacts_months', 24);
        $this->migrator->add('privacy.ai_runs_months', 24);
        $this->migrator->add('privacy.web_research_days', 30);
    }
};
