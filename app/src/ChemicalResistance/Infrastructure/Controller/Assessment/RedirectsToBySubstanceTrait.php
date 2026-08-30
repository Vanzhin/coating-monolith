<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Controller\Assessment;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Редирект обратно на страницу «Химстойкость» с сохранением фильтра
 * (substanceIds[]/includeAll читаются из query action-URL). Общее для тонких
 * by-substance экшенов оценок.
 */
trait RedirectsToBySubstanceTrait
{
    private function redirectToBySubstance(Request $request): Response
    {
        $substanceIds = array_values(array_filter(
            $request->query->all('substanceIds'),
            static fn (mixed $v): bool => is_string($v),
        ));

        $params = ['substanceIds' => $substanceIds];
        if ($request->query->getBoolean('includeAll')) {
            $params['includeAll'] = 1;
        }

        return $this->redirectToRoute('app_cabinet_coating_coating_by_substance', $params);
    }
}
