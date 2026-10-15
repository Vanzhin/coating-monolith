<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Карточка требования: журнальная форма выдачи (черновика/подписи/скана больше нет). GET с дефицитом рисует
 * форму с одной кнопкой «Записать выдачу»; сабмит server-render формы пишет факт прямо в журнал (held растёт,
 * акта-документа не возникает) и редиректит на карточку человека.
 */
final class IssueActionTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $user = new User(new Email('issue_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->authenticateAsSystem();
    }

    public function test_get_renders_journal_issue_form_without_draft_or_scan(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(); // дефицит: на руках 0 < норма 10

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Записать выдачу', $html, 'единственная кнопка записи');
        self::assertStringContainsString('Перчатки', $html, 'позиция текущей нормы в форме');
        self::assertStringNotContainsString('Сохранить черновик', $html, 'черновика больше нет');
        self::assertStringNotContainsString('Прикрепить скан', $html, 'секция скана убрана');
    }

    public function test_submit_records_issuance_as_journal_fact_and_redirects(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        // Форма server-render: количество предзаполнено дефицитом (10) — сабмитим как есть, без JS.
        $this->client->submit($crawler->selectButton('Записать выдачу')->form());

        self::assertResponseRedirects();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(10.0, $pc->heldOf($k), 1e-9, 'выдача записана прямо в журнал, на руках = норма');
    }

    public function test_get_renders_issue_form_even_when_norm_is_fully_green(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // выдано 10 = норма → позиция Green (дефицита нет)

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Записать выдачу', $html, 'форма выдачи открыта даже при полностью удовлетворённой норме');
        self::assertStringContainsString('Перчатки', $html, 'Green-позиция присутствует в форме');
        self::assertGreaterThan(
            0,
            $crawler->filter('input[name="items[0][amount]"]:not([disabled])')->count(),
            'поле количества редактируемо (замена сверх нормы возможна)',
        );
    }

    public function test_submit_on_green_norm_records_replacement_and_grows_held_beyond_norm(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках = норма = 10

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Записать выдачу')->form();
        $form['items[0][amount]'] = '5'; // переопределяем нулевой дефицит — досрочная замена
        $this->client->submit($form);
        self::assertResponseRedirects();

        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(15.0, $pc->heldOf($k), 1e-9, 'замена записана в журнал: на руках выросло сверх нормы (10 + 5)');
    }

    public function test_writeoff_entry_available_when_something_is_issued(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // есть выданное → списание доступно параллельно

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('write-off/open', $html, 'вход «Списать» доступен на той же странице, что и выдача');
        self::assertStringContainsString('Списать', $html);
    }

    private function issueUrl(string $profileId, string $requirementId): string
    {
        return sprintf('/cabinet/compliance/person/%s/requirement/%s/issue', $profileId, $requirementId);
    }
}
