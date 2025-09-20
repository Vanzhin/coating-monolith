<?php

declare(strict_types=1);

namespace App\Users\Domain\Service;

use App\Users\Domain\Entity\Channel;

interface ChannelVerifierInterface
{
    public function supports(Channel $channel): bool;

    /**
     * Инициирует верификацию канала
     * Возвращает verificationId для отслеживания попытки верификации
     */
    public function initiateVerification(Channel $channel): string;

    /**
     * Подтверждает верификацию канала с предоставленным кодом
     */
    public function verify(Channel $channel, string $code): void;

    /**
     * Проверяет, подтвержден ли канал
     */
    public function isVerified(Channel $channel): bool;}
