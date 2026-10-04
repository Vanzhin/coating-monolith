<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Сквозной тест генерации карточки: норма+профиль → GET → отдаётся валидный docx с подставленными
 * идентичностью и нормой (повтор строк движка + шаблон + проектор + DI).
 */
final class CardDownloadControllerTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $user = new User(new Email('card_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->authenticateAsSystem(); // прямые commandBus-вызовы enroll — через системный принципал
    }

    public function test_download_card_streams_docx_with_identity_and_norm(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId] = $this->enrollCompliance();

        $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/requirement/%s/card', $profileId, $requirementId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $text = $this->docxText((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Иван', $text, 'имя сотрудника подставлено');
        self::assertStringContainsString('Перчатки', $text, 'наименование позиции нормы (items.label) подставлено');
        self::assertStringContainsString('ежегодно', $text, 'периодичность (items.unit_cadence) подставлена');
        self::assertStringNotContainsString('{{', $text, 'все плейсхолдеры подставлены');
    }

    private function docxText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'card_').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return preg_replace('/<[^>]*>/', ' ', $xml) ?? '';
    }

    public function test_dashboard_person_and_issue_pages_render(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId] = $this->enrollCompliance();
        $this->client->getContainer()->get(CommandBusInterface::class)->execute(new FormDraftCommand($profileId, $requirementId));

        $this->client->request('GET', '/cabinet/compliance/requirements');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/preview', $profileId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.btn-soft-success'); // «Оформить» по открытому черновику

        $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/requirement/%s/issue', $profileId, $requirementId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#issue-form');
    }

    public function test_write_off_act_pages_render(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $this->issueCard($profileId, $requirementId, $key); // действующая карточка → режим списания

        // Акт выдачи (режим списания) — только кнопка «Перейти к акту списания».
        $crawler = $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/requirement/%s/issue', $profileId, $requirementId));
        self::assertResponseIsSuccessful();
        $openForm = $crawler->filter('form[action$="/write-off/open"]');
        self::assertGreaterThan(0, $openForm->count(), 'на действующей карточке есть кнопка «Перейти к акту списания»');
        $token = $openForm->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', sprintf('/cabinet/compliance/person/%s/requirement/%s/write-off/open', $profileId, $requirementId), [
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects();

        // Проваливаемся на страницу акта списания — там позиции с количеством и причиной.
        $actCrawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#wo-act-form');
        self::assertGreaterThan(0, $actCrawler->filter('input[name="portions[0][recordId]"]')->count(), 'позиция несёт recordId факта');
        self::assertGreaterThan(0, $actCrawler->filter('input[name="portions[0][quantity]"]')->count(), 'есть поле количества к списанию');
        self::assertGreaterThan(0, $actCrawler->filter('select[name="portions[0][reason]"]')->count(), 'есть выбор причины');

        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($profileId);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getWriteOffActs());
        self::assertTrue($pc->getWriteOffActs()[0]->isDraft());
        $actId = $pc->getWriteOffActs()[0]->getId();

        $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/writeoff/%s/download', $profileId, $actId));
        self::assertResponseIsSuccessful();
    }

    public function test_consolidates_position_across_acts_colors_by_sum(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $repo = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class);
        $calc = $this->client->getContainer()->get(ObligationDueCalculator::class);
        $pc = $repo->findByProfile($profileId);
        self::assertNotNull($pc);
        // Два факта одной позиции разными актами: 6 + 4 пары. Каждый < нормы (10), но сумма = норме → зелёный.
        $pc->recordFulfillment(Uuid::v7(), $key, new \DateTimeImmutable('2026-06-01'), $calc, new Quantity(6.0, Unit::Pair));
        $pc->recordFulfillment(Uuid::v7(), $key, new \DateTimeImmutable('2026-07-01'), $calc, new Quantity(4.0, Unit::Pair));
        $pc->setActiveForRequirement($requirementId, true);
        $repo->add($pc);

        $crawler = $this->client->request('GET', sprintf('/cabinet/compliance/person/%s/requirement/%s/issue', $profileId, $requirementId));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('10 / 10 пара', $html, 'консолидировано: бейдж «на руках / норма» по сумме');
        self::assertStringContainsString('text-bg-success', $html, 'сумма 6+4 = норме 10 → зелёный (а не два красных по-фактно)');
        self::assertStringNotContainsString('text-bg-danger', $html, 'ни один факт не красит позицию по своему куску');
        self::assertGreaterThan(0, $crawler->filter('.collapse')->count(), 'у позиции есть разворот (единообразно для всех)');
        self::assertSame(2, substr_count($html, 'Выдан:'), 'в развороте — обе выдачи (2 акта)');
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
