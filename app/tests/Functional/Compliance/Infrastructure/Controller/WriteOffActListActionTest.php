<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Domain\ValueObject\Commission;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * HTTP-смоук списка актов списания (кросс-организационный, только админ): страница рендерится, фильтр
 * organizationId из query-параметра сужает карточки до её собственных актов.
 */
final class WriteOffActListActionTest extends WebTestCase
{
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = new User(new Email('writeoff_list_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
    }

    public function test_list_page_renders(): void
    {
        $this->client->request('GET', '/cabinet/compliance/writeoff');
        self::assertResponseIsSuccessful();
    }

    public function test_filter_by_organization_in_url_narrows_cards(): void
    {
        ['organizationId' => $orgA, 'requirementId' => $reqA, 'profileId' => $pA] = $this->enrollCompliance('Перчатки');
        ['organizationId' => $orgB, 'requirementId' => $reqB, 'profileId' => $pB] = $this->enrollCompliance('Каска');

        $this->seedAct($reqA, $orgA, $pA, 'А-100');
        $this->seedAct($reqB, $orgB, $pB, 'Б-200');

        $this->client->request('GET', '/cabinet/compliance/writeoff', ['organizationId' => $orgA]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('№ А-100', $html, 'акт своей организации виден');
        self::assertStringNotContainsString('№ Б-200', $html, 'акт чужой организации отфильтрован');
    }

    private function seedAct(string $requirementId, string $organizationId, string $profileId, string $actNumber): string
    {
        $container = $this->client->getContainer();
        $repo = $container->get(WriteOffActRepositoryInterface::class);

        $act = new WriteOffAct(Uuid::v7(), $requirementId, $organizationId, new \DateTimeImmutable());
        $act->addLinesForProfile($profileId, [['recordId' => 'r-'.uniqid('', true), 'labelSnapshot' => 'Позиция', 'quantity' => 1.0]]);
        $act->saveDetails($actNumber, new \DateTimeImmutable(), Commission::fromRows([]), '', null, '', '', new \DateTimeImmutable());
        $repo->add($act);
        $container->get(EntityManagerInterface::class)->clear();

        return $act->getId();
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setValue($obj, $value);
    }
}
