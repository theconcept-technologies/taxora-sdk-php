<?php

declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * The company's opt-in e-reporting / e-invoicing emails — result of
 * EReportingEndpoint::getNotificationSettings() / updateNotificationSettings().
 */
final readonly class ComplianceNotificationSettings
{
    /**
     * @param list<string> $recipients up to 5 email addresses (lower-cased by the server)
     * @param string $language "de" or "en" — language of the emails
     * @param bool $notOnPeppol email (at most hourly) listing new invoices held back because the buyer is not on Peppol
     * @param string $digest "off", "daily" (08:00, for the day before) or "weekly" (Mondays, for the last 7 days)
     * @param string|null $digestSentAt ISO 8601 timestamp of the last digest sent, null when none was sent yet
     */
    public function __construct(
        public array $recipients,
        public string $language,
        public bool $notOnPeppol,
        public string $digest,
        public ?string $digestSentAt,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $recipients = [];
        if (isset($data['recipients']) && is_array($data['recipients'])) {
            foreach ($data['recipients'] as $recipient) {
                if (is_string($recipient) && $recipient !== '') {
                    $recipients[] = $recipient;
                }
            }
        }

        return new self(
            recipients: $recipients,
            language: isset($data['language']) ? (string) $data['language'] : 'de',
            notOnPeppol: (bool) ($data['not_on_peppol'] ?? false),
            digest: isset($data['digest']) ? (string) $data['digest'] : 'off',
            digestSentAt: isset($data['digest_sent_at']) ? (string) $data['digest_sent_at'] : null,
        );
    }

    /** @return array{recipients:list<string>,language:string,not_on_peppol:bool,digest:string,digest_sent_at:?string} */
    public function toArray(): array
    {
        return [
            'recipients' => $this->recipients,
            'language' => $this->language,
            'not_on_peppol' => $this->notOnPeppol,
            'digest' => $this->digest,
            'digest_sent_at' => $this->digestSentAt,
        ];
    }
}
