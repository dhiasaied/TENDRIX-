<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/api/me/notifications/unread-count', name: 'notifications_unread_count', methods: ['GET'])]
    public function unreadCount(#[CurrentUser] User $user): JsonResponse
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')->from(Notification::class, 'n')
            ->where('n.recipient = :user AND n.readAt IS NULL')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();

        return $this->json(['count' => (int) $count]);
    }

    #[Route('/api/me/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    public function readAll(#[CurrentUser] User $user): JsonResponse
    {
        $updated = $this->em->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.readAt', ':now')
            ->where('n.recipient = :user AND n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()->execute();

        return $this->json(['updated' => $updated]);
    }
}
