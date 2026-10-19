<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * HTTP-смоук страниц норм: список и редактор рендерятся под админом (ловит Twig-ошибки шаблонов),
 * сохранение валидной нормы редиректит, пустое основание — ре-рендер с ошибкой.
 */
final class RequirementsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = new User(new Email('compliance_ctrl_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
    }

    public function test_list_page_renders(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements');
        self::assertResponseIsSuccessful();
    }

    public function test_next_due_endpoint_calculates_on_backend(): void
    {
        $this->client->request('GET', '/cabinet/compliance/next-due?date=2026-10-01&kind=periodic&number=1&unit=year');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        // глобальный ResponseListener заворачивает ответ в конверт {result,status,data,message}
        self::assertSame('2027-10-01', $body['data']['nextDue']);
    }

    public function test_create_editor_renders(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements/create');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#req-form');
        self::assertSelectorExists('[data-controller="req-rows"]');
    }

    public function test_create_editor_shows_template_upload(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements/create');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#req-form[enctype="multipart/form-data"]');
        self::assertSelectorExists('input[type="file"][name="template"]');
    }

    public function test_create_editor_offers_periodic_type_and_quantity_without_mode_choice(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements/create');
        self::assertResponseIsSuccessful();
        // Тип: инвентарь/периодическая — без спец-фильтра.
        self::assertSelectorExists('input[name="type"][value="periodic"]');
        self::assertSelectorExists('input[name="type"][value="material"]');
        // Количество — на месте (материал/период.), а выбор режима (инвентарь/расходник) из формы убран.
        self::assertSelectorExists('[data-item-quantity] input[name^="items["][name$="][amount]"]');
        self::assertSelectorNotExists('select[name^="items["][name$="][mode]"]');
    }

    public function test_create_with_periodic_requirement_saves(): void
    {
        $name = 'Противопожарный инструктаж (периодическая) '.uniqid('', true);

        $this->client->request(
            'POST',
            '/cabinet/compliance/requirements/create',
            [
                'name' => $name,
                'type' => 'periodic',
                'positionIds' => ['pos-periodic-1'],
                'items' => [[
                    'label' => 'Противопожарный инструктаж',
                    'cadenceKind' => 'periodic',
                    'cadenceNumber' => '1',
                    'cadenceUnit' => 'year',
                    'amount' => '1',
                    'unit' => 'pcs',
                    'basis' => 'ГОСТ 12.0.004',
                ]],
            ],
        );

        self::assertResponseRedirects();
        $container = $this->client->getContainer();
        $type = $container->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT type FROM compliance_requirement WHERE name = ?', [$name]);
        self::assertSame('periodic', $type);
    }

    public function test_create_with_template_upload_stores_it(): void
    {
        $name = 'Противопожарный инструктаж '.uniqid('', true);
        $container = $this->client->getContainer();

        $this->client->request(
            'POST',
            '/cabinet/compliance/requirements/create',
            [
                'name' => $name,
                'type' => 'periodic',
                'positionIds' => ['pos-1'],
                'items' => [['label' => 'Повторный инструктаж', 'cadenceKind' => 'once', 'amount' => '1', 'unit' => 'pcs', 'basis' => 'ГОСТ 12.0.004']],
            ],
            ['template' => $this->docxUpload($container->getParameter('kernel.project_dir'))],
        );

        self::assertResponseRedirects();
        $templateFileId = $container->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT template_file_id FROM compliance_requirement WHERE name = ?', [$name]);
        self::assertNotEmpty($templateFileId, 'у созданного требования проставлен шаблон');
    }

    public function test_edit_form_renders_remove_confirmation_modal_for_items(): void
    {
        $name = 'Выдача СИЗ для удаления позиции '.uniqid('', true);
        $this->client->request(
            'POST',
            '/cabinet/compliance/requirements/create',
            [
                'name' => $name,
                'type' => 'material',
                'positionIds' => ['pos-rm-item-1'],
                'items' => [[
                    'label' => 'Перчатки',
                    'cadenceKind' => 'once',
                    'amount' => '2',
                    'unit' => 'pcs',
                    'basis' => 'Типовые нормы',
                ]],
            ],
        );
        self::assertResponseRedirects();

        $container = $this->client->getContainer();
        $id = $container->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT id FROM compliance_requirement WHERE name = ?', [$name]);
        self::assertNotEmpty($id);

        $this->client->request('GET', "/cabinet/compliance/requirements/{$id}/edit");
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#rm-item-modal');
        self::assertSelectorExists('[data-req-rows-target="row"] button[data-action="req-rows#requestRemove"][data-bs-target="#rm-item-modal"]');
    }

    private function docxUpload(string $projectDir): UploadedFile
    {
        $src = $projectDir.'/src/Compliance/Infrastructure/Resources/templates/requirement_card.docx';
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        copy($src, $tmp);

        return new UploadedFile($tmp, 'journal.docx', null, null, true);
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setValue($obj, $value);
    }
}
