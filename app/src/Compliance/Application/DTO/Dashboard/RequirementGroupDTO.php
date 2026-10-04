<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Группа требования в детали человека: имя + тип + открытый черновик + история подписанных актов + строки. */
final class RequirementGroupDTO
{
    public string $requirementId;
    public string $name;
    /** material|non_material */
    public string $type;
    public string $typeLabel;
    /** id открытого черновика (если есть) — для «Оформить»/«Удалить». */
    public ?string $openDraftId = null;
    /** @var list<IssuanceActDTO> подписанные акты (история выдач со сканами) */
    public array $signedActs = [];
    /** @var list<ObligationBucketRowDTO> */
    public array $rows = [];
}
