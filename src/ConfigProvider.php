<?php

declare(strict_types=1);

namespace Laminas\Log;

use Laminas\Log\Formatter\Simple;
use Laminas\Log\Writer\Stream;

class ConfigProvider
{
    /**
     * Return configuration for this component.
     *
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencyConfig(),
            'log'          => $this->getDefaultConfig()
        ];
    }

    public function getDefaultConfig()
    {
        return [
            'writers' => [
                'stdout' => [
                    'name' => Stream::class,
                    'options' => [
                        'stream' => 'php://stdout',
                        'formatter' => [
                            'name' => Simple::class,
                            'options' => [
                                'format' => Simple::DEFAULT_FORMAT,
                                'dateTimeFormat' => 'Y-m-d H:i:s.v'
                            ],
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Return dependency mappings for this component.
     *
     * @return array
     */
    public function getDependencyConfig()
    {
        return [
            // Legacy Zend Framework aliases
            'aliases'            => [
                //phpcs:disable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFullyQualifiedName
                \Zend\Log\Logger::class => Logger::class,
                //phpcs:enable SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFullyQualifiedName
            ],
            'abstract_factories' => [
                LoggerAbstractServiceFactory::class,
                PsrLoggerAbstractAdapterFactory::class,
            ],
            'factories'          => [
                Logger::class         => LoggerServiceFactory::class,
                'LogFilterManager'    => FilterPluginManagerFactory::class,
                'LogFormatterManager' => FormatterPluginManagerFactory::class,
                'LogProcessorManager' => ProcessorPluginManagerFactory::class,
                'LogWriterManager'    => WriterPluginManagerFactory::class,
            ],
        ];
    }
}
