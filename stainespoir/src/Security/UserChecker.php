<?php
namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}
    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isApproved()) {
            throw new CustomUserMessageAccountStatusException(
                "Votre compte est en attente de validation par l'équipe Stains Espoir."
            );
        }
    }
}
