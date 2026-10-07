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

    public function test_create_with_template_upload_stores_it(): void
    {
        $name = 'Противопожарный инструктаж '.uniqid('', true);
        $container = $this->client->getContainer();

        $this->client->request(
            'POST',
            '/cabinet/compliance/requirements/create',
            [
                'name' => $name,
                'type' => 'non_material',
                'positionIds' => ['pos-1'],
                'items' => [['label' => 'Повторный инструктаж', 'cadenceKind' => 'once', 'basis' => 'ГОСТ 12.0.004']],
            ],
            ['template' => $this->docxUpload($container->getParameter('kernel.project_dir'))],
        );

        self::assertResponseRedirects();
        $templateFileId = $container->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT template_file_id FROM compliance_requirement WHERE name = ?', [$name]);
        self::assertNotEmpty($templateFileId, 'у созданного требования проставлен шаблон');
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
