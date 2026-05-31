<?php

namespace App\Domain\Payment\Support;

use Illuminate\Support\Str;

trait GeneratesPrefixedId
{
    protected static function bootGeneratesPrefixedId(): void
    {
        static::creating(function (self $model): void {
            if ($model->getKey() !== null) {
                return;
            }

            $externalIdColumn = $model->externalIdColumn();

            if ($externalIdColumn !== null && filled($model->getAttribute($externalIdColumn))) {
                $model->setAttribute($model->getKeyName(), $model->getAttribute($externalIdColumn));

                return;
            }

            $model->setAttribute(
                $model->getKeyName(),
                static::generatePrefixedId(static::idPrefix()),
            );
        });
    }

    abstract protected static function idPrefix(): string;

    protected function externalIdColumn(): ?string
    {
        return null;
    }

    public static function generatePrefixedId(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
