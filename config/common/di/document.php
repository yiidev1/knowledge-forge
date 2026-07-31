<?php

declare(strict_types=1);

use App\Document\Application\DocumentProcessingParams;
use App\Document\Application\Pdf\PdfIngestionPolicy;
use App\Document\Application\Pdf\PdfTextProbeInterface;
use App\Document\Application\Processing\DocumentProcessorRegistry;
use App\Document\Application\Processing\ImageDocumentProcessor;
use App\Document\Application\Processing\PdfDocumentProcessor;
use App\Document\Application\Processing\TextDocumentProcessor;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\GeneratedDocumentRepositoryInterface;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\Document\Infrastructure\DbDocumentProcessingRepository;
use App\Document\Infrastructure\DbDocumentRepository;
use App\Document\Infrastructure\DbGeneratedDocumentRepository;
use App\Document\Infrastructure\DbIndexedFileRepository;
use App\Document\Infrastructure\DbTextDocumentRepository;
use App\Document\Infrastructure\DbProcessingEventRepository;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\Document\Infrastructure\Pdf\SmalotPdfTextProbe;
use Yiisoft\Definitions\Reference;

/** @var array $params */

// Repositories, storage, the caps object, the PDF ingestion policy and the processor registry. Services,
// validators and actions are autowired from their constructor types.
return [
    DocumentRepositoryInterface::class => DbDocumentRepository::class,
    ProcessingEventRepositoryInterface::class => DbProcessingEventRepository::class,
    DocumentStorageInterface::class => LocalDocumentStorage::class,
    IndexedFileRepositoryInterface::class => DbIndexedFileRepository::class,
    DocumentProcessingRepositoryInterface::class => DbDocumentProcessingRepository::class,
    GeneratedDocumentRepositoryInterface::class => DbGeneratedDocumentRepository::class,
    TextDocumentRepositoryInterface::class => DbTextDocumentRepository::class,

    DocumentProcessingParams::class => [
        '__construct()' => [
            'maxUploadBytes' => $params['app/documents']['maxUploadBytes'],
            'maxImageBytes' => $params['app/documents']['maxImageBytes'],
            'maxDocumentsPerKnowledgeBase' => $params['app/documents']['maxDocumentsPerKnowledgeBase'],
            'imageMaxWidth' => $params['app/documents']['imageMaxWidth'],
            'imageMaxHeight' => $params['app/documents']['imageMaxHeight'],
        ],
    ],

    PdfTextProbeInterface::class => [
        'class' => SmalotPdfTextProbe::class,
        '__construct()' => [
            'maxProbeBytes' => $params['app/pdf']['probeMaxBytes'],
        ],
    ],

    PdfIngestionPolicy::class => [
        '__construct()' => [
            'minCharsPerPage' => $params['app/pdf']['minCharsPerPage'],
            'visionMaxPages' => $params['app/pdf']['visionMaxPages'],
            'visionMaxBytes' => $params['app/pdf']['visionMaxBytes'],
        ],
    ],

    // Ordered by specificity: PDFs first, then images. The registry picks the first that supports a
    // document, so a future processor (DOCX, TXT) is just one more entry here.
    DocumentProcessorRegistry::class => [
        '__construct()' => [
            'processors' => [
                Reference::to(PdfDocumentProcessor::class),
                Reference::to(ImageDocumentProcessor::class),
                Reference::to(TextDocumentProcessor::class),
            ],
        ],
    ],
];
