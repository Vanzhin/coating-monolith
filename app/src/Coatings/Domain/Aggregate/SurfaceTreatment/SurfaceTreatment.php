<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

class SurfaceTreatment extends Aggregate
{
    private Uuid $id;

    private string $description;
    private ?string $code;
    private ?string $standardCode;
    /** @var list<Substrate> */
    private array $substrateScope;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<Substrate> $substrateScope
     */
    public function __construct(
        Uuid $id,
        string $description,
        ?string $code,
        ?string $standardCode,
        array $substrateScope,
    ) {
        $this->id = $id;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setDescription($description);
        $this->setCode($code);
        $this->setStandardCode($standardCode);
        $this->setSubstrateScope($substrateScope);
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getStandardCode(): ?string
    {
        return $this->standardCode;
    }

    /** @return list<Substrate> */
    public function getSubstrateScope(): array
    {
        return $this->substrateScope;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setDescription(string $description): void
    {
        if ('' === trim($description)) {
            throw new AppException('Описание подготовки поверхности не может быть пустым.');
        }
        if (mb_strlen($description) > 2000) {
            throw new AppException(sprintf('Описание подготовки поверхности не должно превышать 2000 символов, передано %d.', mb_strlen($description)));
        }
        $this->description = $description;
        $this->touch();
    }

    public function setCode(?string $code): void
    {
        if (null !== $code) {
            if ('' === trim($code)) {
                throw new AppException('Код подготовки поверхности не может быть пустой строкой; используйте null, если значение не задано.');
            }
            if (mb_strlen($code) > 30) {
                throw new AppException(sprintf('Код подготовки поверхности не должен превышать 30 символов, передано %d.', mb_strlen($code)));
            }
        }
        $this->code = $code;
        $this->touch();
    }

    public function setStandardCode(?string $standardCode): void
    {
        if (null !== $standardCode) {
            if ('' === trim($standardCode)) {
                throw new AppException('Код стандарта подготовки поверхности не может быть пустой строкой; используйте null, если значение не задано.');
            }
            if (mb_strlen($standardCode) > 100) {
                throw new AppException(sprintf('Код стандарта подготовки поверхности не должен превышать 100 символов, передано %d.', mb_strlen($standardCode)));
            }
        }
        $this->standardCode = $standardCode;
        $this->touch();
    }

    /**
     * @param list<Substrate> $substrateScope
     */
    public function setSubstrateScope(array $substrateScope): void
    {
        if ([] === $substrateScope) {
            throw new AppException('Область применения по подложке должна содержать хотя бы один элемент.');
        }
        $seen = [];
        foreach ($substrateScope as $substrate) {
            if (isset($seen[$substrate->value])) {
                throw new AppException(sprintf('Дублирующаяся подложка в области применения: %s.', $substrate->value));
            }
            $seen[$substrate->value] = true;
        }
        $this->substrateScope = array_values($substrateScope);
        $this->touch();
    }

    public function supportsSubstrate(Substrate $substrate): bool
    {
        return in_array($substrate, $this->substrateScope, true);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
