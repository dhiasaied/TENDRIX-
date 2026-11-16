<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Registration;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @implements ProcessorInterface<Registration, Registration>
 */
final class RegistrationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Registration
    {
        \assert($data instanceof Registration);

        $organization = (new Organization($data->companyName, Organization::TYPE_SUPPLIER))
            ->setSiret($data->siret)
            ->setCity($data->city)
            ->setSkills($data->skills);

        $user = (new User())
            ->setEmail($data->email)
            ->setFirstName($data->firstName)
            ->setLastName($data->lastName)
            ->setRoles([User::ROLE_SUPPLIER])
            ->setOrganization($organization);
        $user->setPassword($this->hasher->hashPassword($user, $data->password));

        // Uniqueness (email, company name) is enforced by the entities' constraints.
        $violations = new ConstraintViolationList();
        foreach ([$this->validator->validate($organization), $this->validator->validate($user)] as $list) {
            foreach ($list as $violation) {
                $violations->add(new ConstraintViolation(
                    $violation->getMessage(), $violation->getMessageTemplate(), $violation->getParameters(), $data,
                    'name' === $violation->getPropertyPath() ? 'companyName' : $violation->getPropertyPath(),
                    $violation->getInvalidValue(), null, $violation->getCode(),
                ));
            }
        }
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        $this->em->persist($organization);
        $this->em->persist($user);
        $this->em->flush();

        $data->userId = $user->getId();
        $data->password = '';

        return $data;
    }
}
