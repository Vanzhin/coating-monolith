<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\Specification\ProfileSpecification;
use App\Personnel\Domain\ValueObject\Reference;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Ulid;

/**
 * Профиль сотрудника — ядро домена Personnel. Хранит идентичность (ФИО, связь с пользователем
 * платформы через userUlid) и снапшоты-ссылки на должность/организацию/отдел (Reference {id,title}):
 * историчность — переименование/удаление справочника не переписывает уже сохранённые профили.
 *
 * Инвариант «отдел принадлежит организации» здесь НЕ проверяется — для него нужен фетч Department
 * по id, это оркестрация Application (ProfileMaker/CommandHandler, T8). Агрегат лишь хранит уже
 * готовые снапшоты, которые ему передали.
 *
 * id передаётся в конструктор (генерация — в Maker/handler), как Position/Department.
 */
class Profile extends Aggregate
{
    private const MAX_PERSONNEL_NUMBER = 50;

    private readonly string $id;
    private readonly string $userUlid;
    private FullName $fullName;
    private Reference $position;
    private Reference $organization;
    private Reference $department;
    private Sizes $sizes;
    private ?string $personnelNumber;
    private ?\DateTimeImmutable $hiredAt;
    private readonly \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    public function __construct(
        string $id,
        string $userUlid,
        FullName $fullName,
        Reference $position,
        Reference $organization,
        Reference $department,
        Sizes $sizes,
        ?string $personnelNumber,
        ?\DateTimeImmutable $hiredAt,
        ProfileSpecification $specification,
        \DateTimeImmutable $now,
    ) {
        $this->id = $id;
        $this->userUlid = $this->normalizeUserUlid($userUlid);
        $this->fullName = $fullName;
        $this->position = $position;
        $this->organization = $organization;
        $this->department = $department;
        $this->sizes = $sizes;
        $this->personnelNumber = $this->normalizePersonnelNumber($personnelNumber);
        $this->hiredAt = $hiredAt;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $specification->uniqueUser->satisfy($this);
    }

    public function changeFullName(FullName $fullName, \DateTimeImmutable $now): void
    {
        $this->fullName = $fullName;
        $this->updatedAt = $now;
    }

    public function changePosition(Reference $position, \DateTimeImmutable $now): void
    {
        $this->position = $position;
        $this->updatedAt = $now;
    }

    public function changeOrganization(Reference $organization, \DateTimeImmutable $now): void
    {
        $this->organization = $organization;
        $this->updatedAt = $now;
    }

    public function changeDepartment(Reference $department, \DateTimeImmutable $now): void
    {
        $this->department = $department;
        $this->updatedAt = $now;
    }

    public function changeSizes(Sizes $sizes, \DateTimeImmutable $now): void
    {
        $this->sizes = $sizes;
        $this->updatedAt = $now;
    }

    public function changePersonnelNumber(?string $personnelNumber, \DateTimeImmutable $now): void
    {
        $this->personnelNumber = $this->normalizePersonnelNumber($personnelNumber);
        $this->updatedAt = $now;
    }

    public function changeHiredAt(?\DateTimeImmutable $hiredAt, \DateTimeImmutable $now): void
    {
        $this->hiredAt = $hiredAt;
        $this->updatedAt = $now;
    }

    private function normalizeUserUlid(string $userUlid): string
    {
        $userUlid = trim($userUlid);
        if ('' === $userUlid || !Ulid::isValid($userUlid)) {
            throw new AppException('Некорректный идентификатор пользователя.');
        }

        return $userUlid;
    }

    private function normalizePersonnelNumber(?string $personnelNumber): ?string
    {
        $trimmed = null !== $personnelNumber ? trim($personnelNumber) : null;
        if (null !== $trimmed && mb_strlen($trimmed) > self::MAX_PERSONNEL_NUMBER) {
            throw new AppException(sprintf('Табельный номер не может быть длиннее %d символов.', self::MAX_PERSONNEL_NUMBER));
        }

        return ('' === $trimmed) ? null : $trimmed;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserUlid(): string
    {
        return $this->userUlid;
    }

    public function getFullName(): FullName
    {
        return $this->fullName;
    }

    public function getPosition(): Reference
    {
        return $this->position;
    }

    public function getOrganization(): Reference
    {
        return $this->organization;
    }

    public function getDepartment(): Reference
    {
        return $this->department;
    }

    public function getSizes(): Sizes
    {
        return $this->sizes;
    }

    public function getPersonnelNumber(): ?string
    {
        return $this->personnelNumber;
    }

    public function getHiredAt(): ?\DateTimeImmutable
    {
        return $this->hiredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Оптимистичная блокировка (Doctrine @Version). */
    public function getVersion(): int
    {
        return $this->version;
    }
}
