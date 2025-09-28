<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Helper;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

trait ExceptionHelperTrait
{
    private function getOriginalException(\Exception $e): \Throwable
    {
        if ($e instanceof HandlerFailedException) {
            return current($e->getWrappedExceptions()) ?? $e->getMessage();
        }

        return $e;
    }

    private function getOriginalExceptionMessage(\Exception $e): string
    {
        $originalException = $this->getOriginalException($e);
        return $originalException->getMessage();
    }
}