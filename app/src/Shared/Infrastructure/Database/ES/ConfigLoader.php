<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

readonly class ConfigLoader
{
    public function __construct(
        private ParameterBagInterface $params,
    )
    {
    }

    public function loadFromConfig(string $configName): array
    {
        $configPath = $this->params->get('elasticsearch.config_dir') . '/' . $configName . '.php';
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Config file not found: $configPath");
        }

        return include $configPath;
    }

}