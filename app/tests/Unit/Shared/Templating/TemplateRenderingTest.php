<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\RenderedDocument;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Domain\Templating\TemplateFormat;
use App\Shared\Domain\Templating\TemplateRenderer;
use App\Shared\Domain\Templating\TemplateVariable;
use App\Shared\Domain\Templating\ValidationResult;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\TemplateRendering;
use PHPUnit\Framework\TestCase;

final class TemplateRenderingTest extends TestCase
{
    public function test_render_dispatches_to_supporting_driver(): void
    {
        $rendering = new TemplateRendering([
            $this->fakeRenderer(TemplateFormat::Docx),
            $this->fakeRenderer(TemplateFormat::Xlsx),
        ]);

        $doc = $rendering->render(new TemplateFile('/t/report.docx'), new RenderData([]));

        self::assertSame('docx', $doc->content);
    }

    public function test_variables_dispatches_by_format(): void
    {
        $rendering = new TemplateRendering([
            $this->fakeRenderer(TemplateFormat::Docx),
            $this->fakeRenderer(TemplateFormat::Xlsx),
        ]);

        $variables = $rendering->variables(new TemplateFile('/t/kp.xlsx'));

        self::assertSame('xlsx', $variables[0]->name);
    }

    public function test_validate_dispatches_by_format(): void
    {
        $rendering = new TemplateRendering([$this->fakeRenderer(TemplateFormat::Docx)]);

        $result = $rendering->validate(new TemplateFile('/t/report.docx'), new RenderData([]));

        self::assertSame(['docx'], $result->missing);
    }

    public function test_throws_when_no_driver_supports_format(): void
    {
        $rendering = new TemplateRendering([$this->fakeRenderer(TemplateFormat::Xlsx)]);

        $this->expectException(AppException::class);

        $rendering->render(new TemplateFile('/t/report.docx'), new RenderData([]));
    }

    private function fakeRenderer(TemplateFormat $format): TemplateRenderer
    {
        return new class($format) implements TemplateRenderer {
            public function __construct(private TemplateFormat $format)
            {
            }

            public function supports(TemplateFile $template): bool
            {
                return $template->format === $this->format;
            }

            public function variables(TemplateFile $template): array
            {
                return [new TemplateVariable($this->format->value, false)];
            }

            public function validate(TemplateFile $template, RenderData $data): ValidationResult
            {
                return new ValidationResult(missing: [$this->format->value]);
            }

            public function render(TemplateFile $template, RenderData $data): RenderedDocument
            {
                return new RenderedDocument($this->format->value, $this->format);
            }
        };
    }
}
