<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Infrastructure\Api\NetAngels;

use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Infrastructure\Api\NetAngels\ExtractionResponseParser;
use App\Inquiries\Infrastructure\Api\NetAngels\NetAngelsExtractor;
use App\Inquiries\Infrastructure\Api\NetAngels\SystemPromptBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NetAngelsExtractorTest extends TestCase
{
    public function test_sends_chat_completion_and_parses_result(): void
    {
        $captured = [];
        $modelJson = json_encode([
            'substrates' => ['steel_carbon'],
            'environment' => 'special',
            'specifiedCoatings' => [],
            'chemicalResistance' => ['substances' => ['нафта'], 'maxTemperature' => 40, 'peakTemperature' => 90],
            'durability' => 'MEDIUM',
        ], JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $modelJson): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? '{}', true)];

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => $modelJson]]],
                'usage' => ['prompt_tokens' => 1234, 'completion_tokens' => 56],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $extractor = new NetAngelsExtractor($client, new SystemPromptBuilder(), new ExtractionResponseParser(), 'qwen3.8-flash');

        $result = $extractor->extract(new InquiryText('резервуар хранения нафты'));

        // запрос: POST chat/completions, модель, json_object, два сообщения
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/chat/completions', $captured['url']);
        self::assertSame('qwen3.8-flash', $captured['body']['model']);
        self::assertSame('json_object', $captured['body']['response_format']['type']);
        self::assertSame(0, $captured['body']['temperature']);
        self::assertArrayHasKey('seed', $captured['body']); // фикс. seed для повторяемости
        self::assertIsInt($captured['body']['seed']);
        self::assertSame('system', $captured['body']['messages'][0]['role']);
        self::assertSame('резервуар хранения нафты', $captured['body']['messages'][1]['content']);

        // разбор ответа + метаданные
        self::assertSame(['steel_carbon'], $result->parameters->substrates);
        self::assertSame('special', $result->parameters->environment);
        self::assertSame(['нафта'], $result->parameters->chemicalResistance->substances);
        self::assertSame(40, $result->parameters->chemicalResistance->maxTemperature);
        self::assertSame(90, $result->parameters->chemicalResistance->peakTemperature);
        self::assertSame('llm:netangels', $result->metadata->strategy);
        self::assertSame('qwen3.8-flash', $result->metadata->model);
        self::assertSame(1234, $result->metadata->tokensIn);
        self::assertSame(56, $result->metadata->tokensOut);
    }
}
