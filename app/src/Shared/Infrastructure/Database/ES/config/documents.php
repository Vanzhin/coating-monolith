<?php

return [
    'settings' => [
        'analysis' => [
            'analyzer' => [
                'custom_ru' => [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => [
                        'lowercase',
                        'russian_stop',
                        'russian_stemmer'
                    ]
                ],
                'custom_en' => [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => [
                        'lowercase',
                        'english_stop',
                        'english_stemmer'
                    ]
                ]
            ],
            'filter' => [
                'russian_stop' => [
                    'type' => 'stop',
                    'stopwords' => '_russian_'
                ],
                'english_stop' => [
                    'type' => 'stop',
                    'stopwords' => '_english_'
                ],
                'russian_stemmer' => [
                    'type' => 'stemmer',
                    'language' => 'russian'
                ],
                'english_stemmer' => [
                    'type' => 'stemmer',
                    'language' => 'english'
                ]
            ]
        ]
    ],
    'mappings' => [
        'properties' => [
            'category' => [
                'type' => 'keyword',
            ],
            'description' => [
                'type' => 'text',
                'analyzer' => 'custom_ru',
                'fields' => [
                    'english' => [
                        'type' => 'text',
                        'analyzer' => 'custom_en'
                    ],
                    'exact' => [
                        'type' => 'keyword',
                        'ignore_above' => 512
                    ],
                    'stemmed' => [
                        'type' => 'text',
                        'analyzer' => 'standard'
                    ]
                ]
            ],
            'title' => [
                'type' => 'text',
                'analyzer' => 'custom_ru',
                'fields' => [
                    'english' => [
                        'type' => 'text',
                        'analyzer' => 'custom_en'
                    ],
                    'exact' => [
                        'type' => 'keyword',
                        'ignore_above' => 512,
                        'doc_values' => true
                    ]
                ]
            ],
            'url' => [
                'type' => 'keyword',
                'null_value' => null,
                'ignore_above' => 2048,
                'doc_values' => true
            ],
            'products' => [
                'type' => 'nested',
                'properties' => [
                    'title' => [
                        'type' => 'text',
                        'analyzer' => 'custom_ru',
                        'fields' => [
                            'english' => [
                                'type' => 'text',
                                'analyzer' => 'custom_en'
                            ],
                            'exact' => [
                                'type' => 'keyword',
                                'ignore_above' => 512
                            ]
                        ]
                    ],
                    'product_id' => [
                        'type' => 'keyword',
                        'ignore_above' => 36,
                        'doc_values' => true,
                        'null_value' => 'NULL'
                    ],
                ],
            ],
            'tags' => [
                'type' => 'nested',
                'properties' => [
                    'title' => [
                        'type' => 'text',
                        'analyzer' => 'custom_ru',
                        'fields' => [
                            'english' => [
                                'type' => 'text',
                                'analyzer' => 'custom_en'
                            ],
                            'exact' => [
                                'type' => 'keyword',
                                'ignore_above' => 512,
                                'doc_values' => true
                            ]
                        ]
                    ],
                    'type' => [
                        'type' => 'keyword',
                        'ignore_above' => 100,
                        'doc_values' => true,
                        'null_value' => 'NULL'
                    ]
                ]
            ],
            'created_at' => [
                'type' => 'date',
                'format' => 'strict_date_optional_time||epoch_millis'
            ],
            'updated_at' => [
                'type' => 'date',
                'format' => 'strict_date_optional_time||epoch_millis'
            ]
        ],
    ],
];