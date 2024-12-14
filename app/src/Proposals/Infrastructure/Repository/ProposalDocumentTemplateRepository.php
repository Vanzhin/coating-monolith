<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Repository;

use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocumentTemplate;
use App\Proposals\Domain\Repository\ProposalDocumentTemplateRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProposalDocumentTemplateRepository extends ServiceEntityRepository implements ProposalDocumentTemplateRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProposalDocumentTemplate::class);
    }

    public function add(ProposalDocumentTemplate $template): void
    {
        $this->getEntityManager()->persist($template);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?ProposalDocumentTemplate
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function remove(ProposalDocumentTemplate $template): void
    {
        $this->getEntityManager()->remove($template);
        $this->getEntityManager()->flush();
    }
}