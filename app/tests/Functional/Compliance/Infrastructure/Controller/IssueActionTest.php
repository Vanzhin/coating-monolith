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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

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

        self::assertStringContainsString('Выдать выбранные', $html, 'групповое действие выдачи в савбаре');
        self::assertStringContainsString('Перчатки', $html, 'позиция текущей нормы в форме');
        self::assertStringNotContainsString('Сохранить черновик', $html, 'черновика больше нет');
        self::assertStringNotContainsString('Прикрепить скан', $html, 'секция скана убрана');
    }

    public function test_submit_records_issuance_as_journal_fact_and_redirects(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        // Дефицитная позиция отмечена по умолчанию (чекбокс checked), кол-во предзаполнено дефицитом (10) —
        // сабмитим форму как есть, без JS (бэк пишет только отмеченные).
        $this->client->submit($crawler->filter('#issue-form')->form());

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

        self::assertStringContainsString('Выдать выбранные', $html, 'действие выдачи доступно даже при полностью удовлетворённой норме');
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
        $form = $crawler->filter('#issue-form')->form();
        $selected = $form['items[0][selected]'];
        self::assertInstanceOf(ChoiceFormField::class, $selected);
        $selected->tick(); // Green-позиция по умолчанию снята — отмечаем для выдачи
        $form['items[0][amount]'] = '5'; // переопределяем нулевой дефицит — досрочная замена
        $this->client->submit($form);
        self::assertResponseRedirects();

        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(15.0, $pc->heldOf($k), 1e-9, 'замена записана в журнал: на руках выросло сверх нормы (10 + 5)');
    }

    public function test_submit_skips_positions_left_unchecked(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#issue-form')->form();
        $selected = $form['items[0][selected]'];
        self::assertInstanceOf(ChoiceFormField::class, $selected);
        $selected->untick(); // снимаем отметку — позиция не должна попасть в запись
        $this->client->submit($form);
        self::assertResponseRedirects();

        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(0.0, $pc->heldOf($k), 1e-9, 'снятая позиция в журнал не записана');
    }

    public function test_item_card_shows_status_badge_and_lazy_history_toggle(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('не выдано', $html, 'единая карточка: статус-бейдж того же бакета, что у дашборда (Missing — дефицит)');
        self::assertStringContainsString('История', $html, 'ленивый тоггл истории позиции присутствует в карточке');
        self::assertStringContainsString(
            sprintf('/cabinet/compliance/person/%s/requirement/%s/obligation/', $p, $r),
            $html,
            'url партиала истории зашит в data-атрибут карточки',
        );
    }

    /**
     * Регрессия (прод 500): наименование позиции с «/» (напр. «Каска защитная до 1000В-1500В/50дж») делает
     * obligationKey = requirementId|lower(label) с «/» внутри. Ключ истории — query-параметр, не путь-сегмент
     * (см. ObligationHistoryAction) — иначе `path()` в карточке кидает 500 на генерации URL партиала.
     */
    public function test_item_card_renders_without_500_when_label_contains_slash(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance('Каска защитная до 1000В-1500В/50дж');

        $this->client->request('GET', $this->issueUrl($p, $r));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('key=', (string) $this->client->getResponse()->getContent(), 'ключ ушёл в query, не в путь');
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
