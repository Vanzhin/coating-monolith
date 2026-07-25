<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use Symfony\Component\Form\FormInterface;

class ResponseFormatter
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function formatSuccess(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function formatError(string $message, int $code = 400, array $data = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatValidationErrors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->formatError('Ошибки валидации формы', 422, ['errors' => $errors]);
    }
}
