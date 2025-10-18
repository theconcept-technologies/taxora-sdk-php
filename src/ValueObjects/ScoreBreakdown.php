<?php
declare(strict_types=1);

namespace Taxora\Sdk\ValueObjects;

/**
 * Lightweight value object describing how a VAT score was composed.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $stepName,
        public float $scoreContribution,
        public array $metadata = []
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $stepName = (string) ($data['stepName'] ?? $data['step_name'] ?? $data['type'] ?? '');
        $score = (float) ($data['scoreContribution'] ?? $data['score'] ?? 0.0);

        $metadata = $data;
        unset($metadata['stepName'], $metadata['step_name'], $metadata['type'], $metadata['scoreContribution'], $metadata['score']);

        return new self($stepName, $score, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stepName' => $this->stepName,
            'scoreContribution' => $this->scoreContribution,
            'metadata' => $this->metadata,
        ];
    }
}
