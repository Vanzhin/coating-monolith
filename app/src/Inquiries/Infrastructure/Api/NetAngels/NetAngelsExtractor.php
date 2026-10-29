<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Api\NetAngels;

use App\Inquiries\Application\DTO\ExtractionMetadata;
use App\Inquiries\Application\DTO\ExtractionResult;
use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Application\Port\InquiryParametersExtractor;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Извлечение через NetAngels AI Studio (OpenAI-совместимый). Ходит через наш аудируемый
 * HTTP-клиент (scoped-клиент netangels, обёрнутый AuditedHttpClientFactory): ретраи и аудит
 * каждой попытки — даром. Здесь только то, что зависит от провайдера: формат запроса/ответа,
 * response_format json_object, модель. Сетевые/HTTP-ошибки пробрасываются — их обрабатывает
 * движок (план 3).
 */
final readonly class NetAngelsExtractor implements InquiryParametersExtractor
{
    /** Фиксированный seed: вместе с temperature:0 — near-повторяемость на поддерживающем бэкенде;
     *  незнакомое поле OpenAI-совместимый шлюз игнорирует (хуже не будет). */
    private const SEED = 7;

    public function __construct(
        private HttpClientInterface $netangelsClient,
        private SystemPromptBuilder $promptBuilder,
        private ExtractionResponseParser $parser,
        private string $model,
    ) {
    }

    public function extract(InquiryText $text): ExtractionResult
    {
        $start = microtime(true);

        $response = $this->netangelsClient->request('POST', 'chat/completions', [
            'json' => [
                'model' => $this->model,
                'temperature' => 0,
                'seed' => self::SEED,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->promptBuilder->build()],
                    ['role' => 'user', 'content' => $text->text],
                ],
            ],
        ]);

        $data = $response->toArray();
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        ['parameters' => $parameters, 'dropped' => $dropped, 'conflicts' => $conflicts] = $this->parser->parse($content);

        return new ExtractionResult(
            parameters: $parameters,
            rawJson: $content,
            metadata: new ExtractionMetadata(
                strategy: 'llm:netangels',
                model: $this->model,
                promptVersion: $this->promptBuilder->version(),
                tokensIn: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
                tokensOut: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
                durationMs: $durationMs,
            ),
            droppedValues: $dropped,
            conflicts: $conflicts,
        );
    }
}
