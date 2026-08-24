<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Issuer;

use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Издатель документа: лаборатория / институт / орган, выдавший заключение
 * (напр. ГосНИИГА, НПЦ Самара, ЛКП, ЦНИИТС).
 */
class Issuer extends Aggregate
{
    private const MAX_TITLE_LENGTH = 255;

    public readonly Uuid $id;

    private string $title;

    public function __construct(Uuid $id, string $title, IssuerSpecification $specification)
    {
        $this->id = $id;
        $this->setTitle($title);
        $specification->uniqueTitle->satisfy($this);
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $trimmed = trim($title);
        if ('' === $trimmed) {
            throw new AppException('Название издателя не может быть пустым.');
        }
        if (mb_strlen($trimmed) > self::MAX_TITLE_LENGTH) {
            throw new AppException(sprintf('Название издателя не должно превышать %d символов.', self::MAX_TITLE_LENGTH));
        }
        $this->title = $trimmed;
    }
}
