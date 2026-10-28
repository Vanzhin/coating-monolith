<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Event\InquirySubmitted;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Запрос — корень домена Inquiries. Слепое типизированное хранилище: вход (текст + ссылки на файлы),
 * распознанные параметры (НЕПРОЗРАЧНЫЙ key-value), снимок ответа, проверка инженером. Домен не знает
 * ни Coatings, ни ChemicalResistance, ни модели — как получены параметры, говорит лишь провенанс
 * (ParameterSource). Статус СЛЕДУЕТ из действий (recognize/match/approve/fail), переход валидирует
 * приватный transitionTo по цепочке InquiryStatus; «Одобрен» — замороженный терминал.
 *
 * ownerId (ulid) — КТО инициировал запрос (аудит/адресат ответа), НЕ ключ доступа: доступ ко всему
 * контексту — только админ (InquiryAccessControl, capability). Отпечаток/эмбеддинг для кэша — НЕ поля
 * агрегата, а инфра-проекция (техника поиска, как tsvector): домен о кэше не знает.
 */
class Inquiry extends Aggregate
{
    private readonly Uuid $id;
    // Вход и тип — не меняются после создания (без сеттеров); readonly не ставим только ради
    // дружелюбия к Doctrine-гидрации, как в Report.
    private InquiryType $type;
    private string $ownerId;
    private string $rawText;
    private StringCollection $attachmentFileIds;

    private InquiryStatus $status;
    private ?RecognizedParameters $recognizedParameters = null;
    private ?ParameterSource $parameterSource = null;
    private ?AnswerSnapshot $answer = null;
    private ?Verification $verification = null;
    private ?string $failureReason = null;
    /** @var list<string> диагностика для инженера: нерезолв веществ, сканы, 0 результатов */
    private array $warnings = [];

    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    public function __construct(
        Uuid $id,
        InquiryType $type,
        string $ownerId,
        string $rawText,
        StringCollection $attachmentFileIds,
        \DateTimeImmutable $now,
    ) {
        if ('' === trim($rawText) && 0 === $attachmentFileIds->count()) {
            throw new AppException('Пустой запрос: нет ни текста, ни вложений.');
        }
        $this->id = $id;
        $this->type = $type;
        $this->ownerId = $ownerId;
        $this->rawText = $rawText;
        $this->attachmentFileIds = $attachmentFileIds;
        $this->status = InquiryStatus::Received;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->raise(new InquirySubmitted($this->getId()));
    }

    /** Параметры распознаны (моделью/из кэша/вручную инженером) вместе с провенансом. Повторный
     *  вход в Recognized (правка инженером из Matched, ретрай из Failed) — чистый лист: старый снимок
     *  ответа и прошлая причина отказа сбрасываются, чтобы не пережили переподбор (инвариант
     *  «ответ ⟺ Matched/Approved», отсутствие фантомной ошибки на удачном результате). */
    public function recognize(RecognizedParameters $parameters, ParameterSource $source, \DateTimeImmutable $now): void
    {
        $this->transitionTo(InquiryStatus::Recognized, $now);
        $this->recognizedParameters = $parameters;
        $this->parameterSource = $source;
        $this->answer = null;
        $this->failureReason = null;
    }

    /** Подбор выполнен — сохраняем снимок найденного. В Matched попадаем только из Recognized, так
     *  что распознанные параметры к этому моменту уже есть (гарантирует transitionTo). */
    public function match(AnswerSnapshot $answer, \DateTimeImmutable $now): void
    {
        $this->transitionTo(InquiryStatus::Matched, $now);
        $this->answer = $answer;
    }

    /** Инженер одобрил результат. Терминально, замораживает запрос. */
    public function approve(?string $verifiedBy, \DateTimeImmutable $now): void
    {
        $this->transitionTo(InquiryStatus::Approved, $now);
        $this->verification = new Verification($verifiedBy, $now);
    }

    /** Ручной повторный прогон всего конвейера. Два кейса: застрявший «Получен» (воркер/контейнер
     *  упал — запись осталась «принят», событие не отработало) переигрываем на месте; «Ошибка» —
     *  сперва Failed → Received. В обоих чистим прошлый ответ и причину отказа и заново поднимаем
     *  InquirySubmitted — воркер отработает как при первичном приёме. Диспетчер идемпотентен (гоняет
     *  только Received), так что зависшее исходное событие и наш повтор друг друга не задвоят. */
    public function requestReprocessing(\DateTimeImmutable $now): void
    {
        if (InquiryStatus::Received !== $this->status && InquiryStatus::Failed !== $this->status) {
            throw new AppException('Повторный запуск доступен только для принятых или проваленных запросов.');
        }
        if (InquiryStatus::Failed === $this->status) {
            $this->transitionTo(InquiryStatus::Received, $now);
        } else {
            $this->updatedAt = $now;
        }
        $this->answer = null;
        $this->failureReason = null;
        $this->raise(new InquirySubmitted($this->getId()));
    }

    /** Ошибка на любом шаге конвейера (извлечение текста, модель, поиск). */
    public function fail(string $reason, \DateTimeImmutable $now): void
    {
        $this->transitionTo(InquiryStatus::Failed, $now);
        $this->failureReason = $reason;
    }

    /** Диагностика для инженера (нерезолв веществ, сканы, 0 результатов). Заменяет целиком. */
    public function noteWarnings(string ...$warnings): void
    {
        $this->warnings = array_values($warnings);
    }

    private function transitionTo(InquiryStatus $to, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new AppException(sprintf('Недопустимый переход статуса: «%s» → «%s».', $this->status->label(), $to->label()));
        }
        $this->status = $to;
        $this->updatedAt = $now;
    }

    public function isApproved(): bool
    {
        return InquiryStatus::Approved === $this->status;
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getType(): InquiryType
    {
        return $this->type;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getRawText(): string
    {
        return $this->rawText;
    }

    public function getAttachmentFileIds(): StringCollection
    {
        return $this->attachmentFileIds;
    }

    public function getStatus(): InquiryStatus
    {
        return $this->status;
    }

    public function getRecognizedParameters(): ?RecognizedParameters
    {
        return $this->recognizedParameters;
    }

    public function getParameterSource(): ?ParameterSource
    {
        return $this->parameterSource;
    }

    public function getAnswer(): ?AnswerSnapshot
    {
        return $this->answer;
    }

    public function getVerification(): ?Verification
    {
        return $this->verification;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
