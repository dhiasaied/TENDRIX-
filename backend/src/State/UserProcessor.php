<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Security\SessionCookieRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates and updates accounts with the security rules that cannot be expressed as field constraints:
 *  - users changing their own password must prove they know the current one;
 *  - a password change or a deactivation revokes every session issued before it;
 *  - administrators cannot deactivate or demote themselves (no accidental lock-out).
 *
 * @implements ProcessorInterface<User, User>
 */
final class UserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<User, User> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
        private readonly SessionCookieRefresher $cookies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert($data instanceof User);
        $actor = $this->security->getUser();
        $previous = $context['previous_data'] ?? null;
        $isSelf = $actor instanceof User && null !== $data->getId() && $actor->getId() === $data->getId();

        if ($isSelf && $previous instanceof User && $this->security->isGranted(User::ROLE_ADMIN)) {
            if ($previous->isActive() && !$data->isActive()) {
                $this->fail('active', 'You cannot deactivate your own account.');
            }
            if (!\in_array(User::ROLE_ADMIN, $data->getRoles(), true)) {
                $this->fail('roles', 'You cannot remove your own administrator role.');
            }
        }

        $revoke = false;
        if ($data->getPlainPassword()) {
            if ($isSelf && !$operation instanceof Post) {
                $current = (string) $data->getCurrentPassword();
                $hash = $previous instanceof User ? $previous->getPassword() : $data->getPassword();
                if ('' === $current || !$this->hasher->isPasswordValid((new User())->setPassword($hash), $current)) {
                    $this->fail('currentPassword', 'Your current password is incorrect.');
                }
            }
            $data->setPassword($this->hasher->hashPassword($data, $data->getPlainPassword()));
            $revoke = !$operation instanceof Post;
        }
        if ($previous instanceof User && $previous->isActive() && !$data->isActive()) {
            $revoke = true;
        }
        $data->eraseCredentials();

        if ($revoke) {
            $data->revokeSessions();
            if ($isSelf) {
                // Other devices are signed out; this browser receives a fresh session.
                $this->cookies->reissueFor($data);
            }
        }

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($operation instanceof Post) {
            $this->em->persist(new AuditLog($actor?->getUserIdentifier(), 'user.created', 'user', (int) $result->getId(), [
                'organization' => $result->getOrganization()?->getName(),
                'roles' => array_values(array_diff($result->getRoles(), ['ROLE_USER'])),
            ]));
            $this->em->flush();
        }

        return $result;
    }

    private function fail(string $path, string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, $message, [], null, $path, null)]));
    }
}
