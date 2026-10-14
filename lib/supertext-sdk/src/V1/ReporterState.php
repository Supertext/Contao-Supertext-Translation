<?php

declare(strict_types=1);

namespace Supertext\ContaoTranslation\Sdk\V1;

/**
 * What a plugin stores between requests for the InstallationReporter (spec/reporting.json → scenarios).
 *
 * Store json_encode($state) as it is and read it back with ReporterState::from(): the JSON is
 * {"reportedVersions", "reportedKeyStatus", "lastFailureAt", "keyCheck": {...}}, the same in every SDK.
 */
final class ReporterState implements \JsonSerializable
{
    /**
     * @param string|null      $reportedVersions  "<plugin>|<system>|<runtime>" versions last reported successfully
     * @param string|null      $reportedKeyStatus the key status last reported successfully
     * @param string|null      $lastFailureAt     when the last report failed (no new attempt for an hour)
     * @param ApiKeyCheck|null $keyCheck          the latest key check
     */
    public function __construct(
        public readonly ?string $reportedVersions = null,
        public readonly ?string $reportedKeyStatus = null,
        public readonly ?string $lastFailureAt = null,
        public readonly ?ApiKeyCheck $keyCheck = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * A stored state: a JSON string, a decoded array or object, or a ReporterState. Anything unknown or
     * broken becomes the empty state, so a lost or corrupted setting just means "never reported".
     */
    public static function from(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (\is_string($value)) {
            try {
                $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $value = null;
            }
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value)) {
            return new self();
        }
        $string = static fn (mixed $v): ?string => \is_string($v) ? $v : null;

        return new self(
            $string($value['reportedVersions'] ?? null),
            $string($value['reportedKeyStatus'] ?? null),
            $string($value['lastFailureAt'] ?? null),
            ApiKeyCheck::fromArray($value['keyCheck'] ?? null),
        );
    }

    /**
     * @return array{reportedVersions: string|null, reportedKeyStatus: string|null, lastFailureAt: string|null, keyCheck: array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null, fingerprint: string}|null}
     */
    public function toArray(): array
    {
        return [
            'reportedVersions' => $this->reportedVersions,
            'reportedKeyStatus' => $this->reportedKeyStatus,
            'lastFailureAt' => $this->lastFailureAt,
            'keyCheck' => $this->keyCheck?->toArray(),
        ];
    }

    /**
     * @return array{reportedVersions: string|null, reportedKeyStatus: string|null, lastFailureAt: string|null, keyCheck: array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null, fingerprint: string}|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
