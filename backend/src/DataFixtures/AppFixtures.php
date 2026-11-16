<?php

namespace App\DataFixtures;

use App\Ai\MatchingAgent;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Proposal;
use App\Entity\SupplierEvaluation;
use App\Entity\Tender;
use App\Entity\User;
use App\Service\ReferenceGenerator;
use App\Service\SupplierRatingService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo dataset covering every lifecycle state. All accounts share the password below (dev only).
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'Demo!Tendrix2026';

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, User> */
    private array $users = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ReferenceGenerator $references,
        private readonly MatchingAgent $matcher,
        private readonly SupplierRatingService $ratings,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->organizations($manager);
        $this->users($manager);
        $manager->flush();

        $today = new \DateTimeImmutable('today');

        // 1. Awarded tender with an active contract and an invoicing history.
        $t1 = $this->tender($manager, 'horizon', 'claire', 'Développeur Fullstack Symfony / Angular senior', ['PHP', 'Symfony', 'Angular', 'API Platform', 'PostgreSQL', 'Docker'], 'senior', 650, 180,
            $today->modify('-5 months'), $today->modify('-5 months -10 days'), Tender::STATUS_AWARDED,
            'Renforcement de l\'équipe en charge du portail de souscription en ligne. Le consultant interviendra sur la refonte des API de tarification (API Platform) et sur le front Angular, au sein d\'une squad agile de 7 personnes.');
        $p1 = $this->proposal($manager, $t1, 'craft', 'Sofiane Ben Ali', 'Lead développeur PHP/Angular', ['PHP 8', 'Symfony 7', 'Angular', 'API Platform', 'PostgreSQL', 'Docker', 'GitLab CI'], 9, 640, $t1->getStartDate(), Proposal::STATUS_ACCEPTED);
        $this->proposal($manager, $t1, 'cloudforge', 'Julien Morel', 'Développeur fullstack', ['PHP', 'Laravel', 'Vue.js', 'MySQL'], 5, 560, $t1->getStartDate(), Proposal::STATUS_REJECTED);
        $c1 = $this->contract($manager, $p1, Contract::STATUS_ACTIVE);
        foreach ([4 => 20, 3 => 19, 2 => 21, 1 => 18] as $monthsAgo => $days) {
            $this->invoice($manager, $c1, $today->modify("first day of -$monthsAgo months")->format('Y-m'), $days, 1 === $monthsAgo ? Invoice::STATUS_SUBMITTED : Invoice::STATUS_PAID);
        }

        // 2. Completed contract, evaluated.
        $t2 = $this->tender($manager, 'horizon', 'claire', 'Data engineer – plateforme de données sinistres', ['Python', 'Spark', 'AWS', 'SQL', 'Airflow'], 'confirmed', 600, 90,
            $today->modify('-9 months'), $today->modify('-9 months -15 days'), Tender::STATUS_AWARDED,
            'Industrialisation des pipelines d\'ingestion des données sinistres vers le data lake AWS et mise en place de la supervision des traitements Airflow.');
        $p2 = $this->proposal($manager, $t2, 'datanext', 'Inès Haddad', 'Data engineer', ['Python', 'Spark', 'AWS', 'Airflow', 'SQL', 'dbt'], 5, 580, $t2->getStartDate(), Proposal::STATUS_ACCEPTED);
        $c2 = $this->contract($manager, $p2, Contract::STATUS_COMPLETED);
        foreach ([8 => 20, 7 => 21, 6 => 20, 5 => 19] as $monthsAgo => $days) {
            $this->invoice($manager, $c2, $today->modify("first day of -$monthsAgo months")->format('Y-m'), $days, Invoice::STATUS_PAID);
        }
        $this->evaluation($manager, $c2, 'claire', 5, 4, 5, 4, 'Excellente maîtrise technique, très bonne communication avec les équipes métier.');

        // 3. Contract awaiting the supplier's signature.
        $t3 = $this->tender($manager, 'nova', 'karim', 'Expert cybersécurité – audit et plan de remédiation', ['Cybersecurity', 'ISO 27001', 'Pentest', 'Azure'], 'expert', 950, 40,
            $today->modify('+20 days'), $today->modify('-3 days'), Tender::STATUS_AWARDED,
            'Audit de sécurité du SI e-commerce (périmètre cloud Azure et applications web), tests d\'intrusion et élaboration d\'un plan de remédiation priorisé.');
        $p3 = $this->proposal($manager, $t3, 'atlas', 'Marc Lefèvre', 'Consultant sécurité offensive', ['Pentest', 'Cybersecurity', 'Azure', 'ISO 27001', 'OWASP'], 14, 920, $today->modify('+15 days'), Proposal::STATUS_ACCEPTED);
        $this->contract($manager, $p3, Contract::STATUS_PENDING_SUPPLIER_SIGNATURE);

        // 4. Published tenders open for answers (one restricted consultation).
        $t4 = $this->tender($manager, 'horizon', 'claire', 'Product Owner – application mobile assurés', ['Product Ownership', 'Scrum', 'Mobile', 'UX'], 'senior', 750, 120,
            $today->modify('+6 weeks'), $today->modify('+12 days'), Tender::STATUS_PUBLISHED,
            'Pilotage du backlog de l\'application mobile assurés (iOS/Android), animation des ateliers avec les métiers et priorisation de la feuille de route trimestrielle.');
        $this->proposal($manager, $t4, 'craft', 'Camille Roux', 'Product Owner senior', ['Product Ownership', 'Scrum', 'Mobile', 'Jira'], 8, 720, $today->modify('+5 weeks'), Proposal::STATUS_SHORTLISTED);
        $this->proposal($manager, $t4, 'atlas', 'Thomas Girard', 'Product Manager', ['Product Management', 'UX', 'Scrum'], 6, 800, $today->modify('+8 weeks'), Proposal::STATUS_SUBMITTED);

        $t5 = $this->tender($manager, 'nova', 'karim', 'Développeur Angular – back-office logistique', ['Angular', 'TypeScript', 'RxJS', 'Jest'], 'confirmed', 550, 100,
            $today->modify('+5 weeks'), $today->modify('+9 days'), Tender::STATUS_PUBLISHED,
            'Développement de nouveaux écrans du back-office logistique (suivi des expéditions, gestion des retours) en Angular 19 avec signals et tests automatisés.');
        $t5->addInvitedSupplier($this->orgs['craft'])->addInvitedSupplier($this->orgs['cloudforge']);
        $this->proposal($manager, $t5, 'cloudforge', 'Lina Mansour', 'Développeuse front-end', ['Angular', 'TypeScript', 'RxJS', 'Cypress'], 4, 530, $today->modify('+4 weeks'), Proposal::STATUS_SUBMITTED);

        $this->tender($manager, 'horizon', 'claire', 'Architecte Kubernetes / DevOps', ['Kubernetes', 'Terraform', 'GitLab CI', 'AWS', 'Observability'], 'expert', 900, 60,
            $today->modify('+2 months'), $today->modify('+20 days'), Tender::STATUS_PUBLISHED,
            'Conception de la plateforme d\'hébergement conteneurisée cible, industrialisation des déploiements et mise en place de l\'observabilité (logs, métriques, traces).');

        // 5. Awaiting approval, and a draft.
        $this->tender($manager, 'horizon', 'paul', 'Business analyst – programme Solvabilité II', ['Business Analysis', 'Insurance', 'SQL', 'Reporting'], 'confirmed', 620, 150,
            $today->modify('+2 months'), $today->modify('+25 days'), Tender::STATUS_PENDING_APPROVAL,
            'Recueil des besoins et rédaction des spécifications fonctionnelles pour les reportings réglementaires Solvabilité II, en lien avec la direction actuariat.');
        $this->tender($manager, 'nova', 'nadia', 'Consultant SAP S/4HANA – module MM', ['SAP', 'S/4HANA', 'MM'], 'senior', 850, 200,
            $today->modify('+3 months'), $today->modify('+1 month'), Tender::STATUS_DRAFT,
            'Accompagnement de la migration vers S/4HANA sur le périmètre achats (MM) : ateliers de conception, paramétrage et recette.');

        $manager->flush();

        foreach ($this->orgs as $org) {
            if ($org->isSupplier()) {
                $this->ratings->refresh($org);
            }
        }

        $manager->persist(new Notification($this->users['claire'], 'proposal.received', 'Nouvelle proposition sur '.$t4->getReference(), 'Atlas Freelance Network propose Thomas Girard (Product Manager).', '/tenders/'.$t4->getId()));
        $manager->persist(new Notification($this->users['craft'], 'tender.opportunity', 'Nouvelle opportunité', $t5->getReference().' « '.$t5->getTitle().' » — consultation restreinte.', '/tenders/'.$t5->getId()));
        $manager->flush();
    }

    private function organizations(ObjectManager $manager): void
    {
        $data = [
            'platform' => ['TENDRIX Operations', Organization::TYPE_BUYER, 'Paris', 'Plateforme', []],
            'horizon' => ['Horizon Assurances', Organization::TYPE_BUYER, 'Paris', 'Assurance', []],
            'nova' => ['Nova Retail Group', Organization::TYPE_BUYER, 'Lyon', 'Distribution', []],
            'craft' => ['Digital Craft Consulting', Organization::TYPE_SUPPLIER, 'Paris', 'ESN', ['PHP', 'Symfony', 'Angular', 'Product Ownership', 'Scrum']],
            'datanext' => ['DataNext Partners', Organization::TYPE_SUPPLIER, 'Nantes', 'Data & IA', ['Python', 'Spark', 'AWS', 'Machine Learning', 'SQL']],
            'cloudforge' => ['CloudForge', Organization::TYPE_SUPPLIER, 'Tunis', 'ESN', ['Angular', 'TypeScript', 'Kubernetes', 'PHP', 'Laravel']],
            'atlas' => ['Atlas Freelance Network', Organization::TYPE_SUPPLIER, 'Bordeaux', 'Réseau d\'indépendants', ['Cybersecurity', 'Product Management', 'UX', 'Azure']],
        ];
        foreach ($data as $key => [$name, $type, $city, $sector, $skills]) {
            $org = (new Organization($name, $type))->setCity($city)->setSector($sector)->setSkills($skills)
                ->setDescription(Organization::TYPE_SUPPLIER === $type ? "$name accompagne les grands comptes sur leurs projets numériques." : null);
            $manager->persist($org);
            $this->orgs[$key] = $org;
        }
    }

    /**
     * Supplier users are keyed by their organization key, so proposals/contracts can find them.
     */
    private function users(ObjectManager $manager): void
    {
        $data = [
            'admin' => ['admin@tendrix.test', 'Dhia Eddine', 'Saied', 'platform', [User::ROLE_ADMIN], 'Responsable de la plateforme'],
            'claire' => ['claire.dubois@horizon.test', 'Claire', 'Dubois', 'horizon', [User::ROLE_BUYER], 'Responsable achats IT'],
            'paul' => ['paul.bernard@horizon.test', 'Paul', 'Bernard', 'horizon', [User::ROLE_REQUESTER], 'Directeur de projet'],
            'karim' => ['karim.saidi@nova.test', 'Karim', 'Saidi', 'nova', [User::ROLE_BUYER], 'Acheteur prestations intellectuelles'],
            'nadia' => ['nadia.cohen@nova.test', 'Nadia', 'Cohen', 'nova', [User::ROLE_REQUESTER], 'Responsable SI achats'],
            'craft' => ['contact@digitalcraft.test', 'Yasmine', 'Trabelsi', 'craft', [User::ROLE_SUPPLIER], 'Directrice commerciale'],
            'datanext' => ['bd@datanext.test', 'Hugo', 'Lambert', 'datanext', [User::ROLE_SUPPLIER], 'Business developer'],
            'cloudforge' => ['sales@cloudforge.test', 'Amine', 'Jaziri', 'cloudforge', [User::ROLE_SUPPLIER], 'Responsable avant-vente'],
            'atlas' => ['hello@atlas-freelance.test', 'Léa', 'Fontaine', 'atlas', [User::ROLE_SUPPLIER], 'Fondatrice'],
        ];
        foreach ($data as $key => [$email, $first, $last, $org, $roles, $job]) {
            $user = (new User())->setEmail($email)->setFirstName($first)->setLastName($last)
                ->setOrganization($this->orgs[$org])->setRoles($roles)->setJobTitle($job);
            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
            $manager->persist($user);
            $this->users[$key] = $user;
        }
    }

    /** @param list<string> $skills */
    private function tender(ObjectManager $manager, string $org, string $author, string $title, array $skills, string $seniority, int $budgetMax, int $days, \DateTimeImmutable $start, \DateTimeImmutable $deadline, string $status, string $context): Tender
    {
        $tender = (new Tender())
            ->setReference($this->references->next(ReferenceGenerator::TENDER))
            ->setTitle($title)
            ->setDescription("CONTEXTE\n$context\n\nMISSIONS\n- Contribuer à la conception et à la réalisation des évolutions ;\n- Garantir la qualité des livrables (tests, revues, documentation) ;\n- Participer aux rituels agiles et au partage de connaissances.\n\nMODALITÉS\nCharge estimée : $days jours. Merci de préciser TJM, disponibilité et CV détaillé.")
            ->setSkills($skills)->setSeniority($seniority)->setBudgetMin((int) round($budgetMax * 0.8 / 10) * 10)->setBudgetMax($budgetMax)
            ->setEstimatedDays($days)->setStartDate($start)->setDeadline($deadline->setTime(18, 0))->setStatus($status)
            ->setLocation($this->orgs[$org]->getCity() ?? 'Paris')->setRemotePolicy('hybrid')
            ->setOrganization($this->orgs[$org])->setCreatedBy($this->users[$author])
            ->setSuggestedDailyRate((int) round($budgetMax * 0.95 / 10) * 10);
        if (\in_array($status, [Tender::STATUS_PUBLISHED, Tender::STATUS_CLOSED, Tender::STATUS_AWARDED], true)) {
            $tender->setPublishedAt($deadline->modify('-3 weeks'))->setApprovedBy($this->buyerOf($this->orgs[$org]));
        }
        $manager->persist($tender);

        return $tender;
    }

    /** @param list<string> $skills */
    private function proposal(ObjectManager $manager, Tender $tender, string $supplier, string $name, string $title, array $skills, int $years, int $rate, ?\DateTimeImmutable $available, string $status): Proposal
    {
        $proposal = (new Proposal())
            ->setTender($tender)->setSupplier($this->orgs[$supplier])->setSubmittedBy($this->users[$supplier])
            ->setCandidateName($name)->setCandidateTitle($title)->setCandidateSkills($skills)->setExperienceYears($years)
            ->setDailyRate($rate)->setAvailableFrom($available)->setStatus($status)
            ->setCandidateSummary(\sprintf('%s, %s avec %d ans d\'expérience. Interventions récentes chez des grands comptes (assurance, retail, industrie) sur des projets %s. Habitué(e) aux environnements agiles et au travail en équipe pluridisciplinaire.', $name, mb_strtolower($title), $years, implode(', ', \array_slice($skills, 0, 3))))
            ->setCoverLetter('Nous vous proposons ce profil, disponible et parfaitement aligné avec votre besoin.');
        $tender->getProposals()->add($proposal);

        $match = $this->matcher->evaluate($proposal);
        $proposal->setMatchScore($match->score)->setMatchBreakdown($match->breakdown)->setMatchAnalysis($match->analysis);
        $manager->persist($proposal);

        return $proposal;
    }

    private function contract(ObjectManager $manager, Proposal $proposal, string $status): Contract
    {
        $contract = Contract::fromAcceptedProposal($proposal, $this->references->next(ReferenceGenerator::CONTRACT));
        if (Contract::STATUS_PENDING_BUYER_SIGNATURE !== $status) {
            $contract->signAsBuyer($this->buyerOf($contract->getBuyer()));
        }
        if (\in_array($status, [Contract::STATUS_ACTIVE, Contract::STATUS_COMPLETED], true)) {
            $contract->signAsSupplier($proposal->getSubmittedBy() ?? throw new \LogicException());
        }
        $contract->setStatus($status);
        $manager->persist($contract);

        return $contract;
    }

    private function invoice(ObjectManager $manager, Contract $contract, string $period, float $days, string $status): void
    {
        $invoice = (new Invoice())->setContract($contract)->setPeriod($period)->setDaysWorked($days)
            ->setNumber($this->references->next(ReferenceGenerator::INVOICE));
        $invoice->computeAmounts();
        $invoice->markSubmitted();
        if (\in_array($status, [Invoice::STATUS_APPROVED, Invoice::STATUS_PAID], true)) {
            $invoice->markApproved($this->buyerOf($contract->getBuyer()));
        }
        if (Invoice::STATUS_PAID === $status) {
            $invoice->markPaid();
        }
        $invoice->setStatus($status);
        $contract->getInvoices()->add($invoice);
        $manager->persist($invoice);
    }

    private function evaluation(ObjectManager $manager, Contract $contract, string $evaluator, int $quality, int $reactivity, int $communication, int $deadlines, string $comment): void
    {
        $manager->persist((new SupplierEvaluation())->setContract($contract)->setEvaluator($this->users[$evaluator])
            ->setQuality($quality)->setReactivity($reactivity)->setCommunication($communication)->setDeadlines($deadlines)->setComment($comment));
    }

    private function buyerOf(?Organization $org): User
    {
        return $org === $this->orgs['nova'] ? $this->users['karim'] : $this->users['claire'];
    }
}
