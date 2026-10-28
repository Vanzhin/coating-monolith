# Схема: Inquiries — экстрактор параметров (план 1)

## Структура и поток (flowchart)

```mermaid
flowchart TD
    subgraph APP["Inquiries / Application"]
      IT["InquiryText<br/>готовый текст запроса"]
      PORT["InquiryParametersExtractor (ПОРТ)<br/>extract(InquiryText): ExtractionResult"]
      RES["ExtractionResult<br/>parameters + rawJson + metadata + droppedValues"]
    end

    subgraph INFRA["Inquiries / Infrastructure / Api / NetAngels"]
      NA["NetAngelsExtractor<br/>реализация порта"]
      PB["SystemPromptBuilder<br/>Resources/system-prompt.v1.txt (версионируется)"]
      RP["ExtractionResponseParser<br/>JSON -> ExtractedParameters,<br/>валидация по enum, отброс -> droppedValues"]
    end

    subgraph SHARED["Shared — кубик HTTP-клиент + аудит"]
      AC["app.http_client.netangels<br/>AuditedHttpClientFactory::wrap(scoped 'netangels')"]
      LOG[("http_audit_log<br/>строка на попытку, async")]
    end

    API[("NetAngels AI Studio<br/>POST /chat/completions")]

    PORT -. "реализует" .- NA
    IT --> NA
    NA -- "system prompt" --> PB
    NA -- "POST chat/completions<br/>json_object, model, messages" --> AC
    AC -- "ретрай + аудит даром" --> API
    API -- "JSON ответ" --> NA
    NA -- "choices[0].message.content" --> RP
    RP --> RES
    AC -. "каждая попытка" .-> LOG
```

## Что внутри

- **Порт** `InquiryParametersExtractor` — говорит «текст → параметры», не «модель». Сегодня за ним NetAngels; завтра перед ним кэш/шаблоны (план 5), потребители не меняются.
- **NetAngelsExtractor** шлёт `POST chat/completions` (`response_format: json_object`, модель из env) через **наш аудируемый клиент** — ретрай 5xx/сети и запись каждой попытки в `http_audit_log` получаются бесплатно.
- **SystemPromptBuilder** — обкатанный системный промпт (с `specifiedCoatings`), версионируется в Resources.
- **ExtractionResponseParser** — JSON модели → `ExtractedParameters`; значения вне enum проекта (`Substrate`/`EnvironmentType`/`ComplianceStandard`/`IsoCorrosivityCategory`/`IsoDurability`/`ThermalEnvironment`) отбрасываются в null/[] и попадают в `droppedValues`.
- **ExtractionResult** несёт параметры + сырой JSON (для разбора ошибок) + метаданные (модель, версия промпта, токены, время, `strategy="llm:netangels"`) + отброшенное.

## Границы

- Сетевые/HTTP-ошибки **пробрасываются** — их ловит движок (план 3), превращая в доменный «ошибка связи с моделью».
- Резолв `substances`/`specifiedCoatings` в id и сборка `CoatingSystemsFilter` — **не тут**, это план 2.
```
