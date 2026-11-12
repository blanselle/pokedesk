<?php

namespace App\Catalog;

/** Counters of one import run, mergeable across sets and languages. */
final class ImportReport
{
    private const MAX_ERRORS = 50;

    public int $processed = 0;
    public int $setsExcluded = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $imagesDownloaded = 0;
    public int $imagesSkipped = 0;
    public int $imagesMissing = 0;
    public int $obsolete = 0;
    public int $errorCount = 0;

    /** @var list<string> only the first errors are kept */
    private array $errors = [];

    public function error(string $message): void
    {
        ++$this->errorCount;

        if (\count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = $message;
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    public function merge(self $other): void
    {
        $this->processed += $other->processed;
        $this->setsExcluded += $other->setsExcluded;
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->imagesDownloaded += $other->imagesDownloaded;
        $this->imagesSkipped += $other->imagesSkipped;
        $this->imagesMissing += $other->imagesMissing;
        $this->obsolete += $other->obsolete;
        $this->errorCount += $other->errorCount;

        foreach ($other->errors as $error) {
            if (\count($this->errors) >= self::MAX_ERRORS) {
                break;
            }
            $this->errors[] = $error;
        }
    }
}
