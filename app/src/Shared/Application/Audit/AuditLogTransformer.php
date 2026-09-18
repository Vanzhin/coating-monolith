<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Application\Audit\Present\AuditChangePresenter;
use App\Shared\Domain\Audit\ActorResolverInterface;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\FieldChange;

/**
 * AuditEntry → view-DTO. Подпись+значение поля — из {@see AuditChangePresenter}, которому
 * передаётся подпись и вид (AuditFieldKind) поля из карты TrackedClass. Презентер вернул
 * null — изменение шумовое (is_calculated) и пропускается, а не показывается.
 */
final class AuditLogTransformer
{
    public function __construct(
        private readonly AuditPolicyInterface $policy,
        private readonly ActorResolverInterface $actorResolver,
        private readonly AuditChangePresenter $presenter,
    ) {
    }

    public function view(AuditEntry $e): AuditLogView
    {
        $labels = $this->policy->trackedFields($e->entityClass());
        $kinds = $this->policy->fieldKinds($e->entityClass());

        /** @var list<FieldChangeView> $changes */
        $changes = [];
        foreach ($e->changes()->all() as $c) {
            $view = $this->presentedChange($c, $labels, $kinds);
            if (null !== $view) {
                $changes[] = $view;
            }
        }

        return new AuditLogView(
            $e->action(),
            $e->actorId(),
            $this->actorResolver->resolve($e->actorId()),
            $e->occurredAt(),
            $e->entityId(),
            $changes,
        );
    }

    /**
     * @param array<string, string>         $labels
     * @param array<string, AuditFieldKind> $kinds
     */
    private function presentedChange(FieldChange $c, array $labels, array $kinds): ?FieldChangeView
    {
        $head = explode('.', $c->path)[0];
        $label = $labels[$head] ?? $head;
        $kind = $kinds[$head] ?? AuditFieldKind::Scalar;
        $presented = $this->presenter->present($c, $label, $kind);

        return null === $presented
            ? null
            : new FieldChangeView($presented->op, $presented->label, $presented->oldText, $presented->newText);
    }
}
