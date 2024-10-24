<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\HttpFoundation\Response;

readonly class ResponseDTOTransformer
{
    public function buildResponseData(Response $response): ResponseDTO
    {
        if ($response->getStatusCode() < 400) {
            $result = new ResponseDTO(
                'success',
                $response->getStatusCode(),
                json_decode($response->getContent(), true),
                null
            );
        } else {
            $result = new ResponseDTO(
                'error',
                $response->getStatusCode(),
                null,
                $this->getMessageFromContent($response->getContent())
            );
        }

        return $result;
    }

    private function getMessageFromContent(string $jsonString): ?string
    {
        $data = json_decode($jsonString, true);

        return $data['message'] ?? null;
    }
}
