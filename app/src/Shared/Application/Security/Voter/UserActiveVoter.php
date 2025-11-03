<?php

declare(strict_types=1);

namespace App\Shared\Application\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class UserActiveVoter extends Voter
{
    public const ACCESS_ACTIVE_USER = 'ACCESS_ACTIVE_USER';

    protected function supports(string $attribute, $subject): bool
    {
        return $attribute === self::ACCESS_ACTIVE_USER;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();;

        if (!($user instanceof UserInterface) || !method_exists($user, 'isActive')) {
            return false;
        }

        // Просто проверяем, активен ли пользователь
        return $user->isActive();
    }
}