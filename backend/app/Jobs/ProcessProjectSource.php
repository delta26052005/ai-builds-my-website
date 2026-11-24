<?php

namespace App\Jobs;

use App\Models\ProjectSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\PdfParser;
use Throwable;

class ProcessProjectSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DISK = 'project_sources';

    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    private const MAX_PAGES = 200;

    private const MAX_TEXT_BYTES = 1_000_000;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $sourceId) {}

    public function handle(): void
    {
        $source = $this->beginProcessing();

        if ($source === null) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        $path = $source->stored_path;

        if (! $disk->exists($path)) {
            $this->finish($source->id, $this->failure(
                'missing_file',
                'The original file is no longer available. Upload it again to process it.',
            ));

            return;
        }

        $absolutePath = $disk->path($path);
        $fileSize = filesize($absolutePath);

        if ($fileSize === false || $fileSize > self::MAX_FILE_BYTES) {
            $this->finish($source->id, $this->failure(
                'document_too_large',
                'This file exceeds the safe processing limit.',
            ));

            return;
        }

        if ($source->mime_type === 'application/pdf') {
            $this->processPdf($source, $absolutePath);

            return;
        }

        if (in_array($source->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->processImage($source, $absolutePath);

            return;
        }

        $this->finish($source->id, $this->failure(
            'unsupported_source_type',
            'This file type cannot be processed.',
        ));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $this->finish($this->sourceId, $this->failure(
            'processing_failed',
            'We could not process this file. Please try again.',
        ));
    }

    private function beginProcessing(): ?ProjectSource
    {
        return DB::transaction(function (): ?ProjectSource {
            $source = ProjectSource::query()
                ->lockForUpdate()
                ->find($this->sourceId);

            if ($source === null) {
                return null;
            }

            $extraction = $source->extraction()->firstOrCreate(
                ['project_source_id' => $source->id],
                ['status' => 'pending'],
            );

            if (in_array($extraction->status, ['completed', 'awaiting_ocr', 'awaiting_vision'], true)) {
                return null;
            }

            if ($extraction->status === 'processing' && $this->attempts() <= 1) {
                return null;
            }

            $extraction->update([
                'status' => 'processing',
                'queued_at' => null,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => null,
            ]);
            $source->update(['processing_status' => 'processing']);

            return $source;
        });
    }

    private function processPdf(ProjectSource $source, string $absolutePath): void
    {
        try {
            $document = (new PdfParser)->parseFile($absolutePath, useInMemoryStream: false);
            $pageCount = $document->getNumberOfPages();

            if ($pageCount > self::MAX_PAGES) {
                $result = $this->failure(
                    'document_too_large',
                    'This PDF exceeds the safe page limit for processing.',
                    ['page_count' => $pageCount],
                    'prinsfrank/pdfparser',
                    $pageCount,
                );
            } else {
                $text = '';
                $textTooLarge = false;

                /** @var Page $page */
                foreach ($document->getPages() as $page) {
                    $pageText = $page->getText();

                    if ($text !== '') {
                        $text .= "\n\n";
                    }

                    $text .= $pageText;

                    if (strlen($text) > self::MAX_TEXT_BYTES) {
                        $text = '';
                        $textTooLarge = true;
                        break;
                    }
                }

                if ($textTooLarge) {
                    $result = $this->failure(
                        'document_too_large',
                        'This PDF contains more text than can be safely stored.',
                        ['page_count' => $pageCount],
                        'prinsfrank/pdfparser',
                        $pageCount,
                    );
                } elseif (trim($text) === '') {
                    $result = [
                        'status' => 'awaiting_ocr',
                        'extracted_text' => null,
                        'extraction_method' => 'prinsfrank/pdfparser',
                        'page_count' => $pageCount,
                        'metadata' => ['parser' => 'prinsfrank/pdfparser'],
                        'error_code' => 'no_extractable_text',
                        'error_message' => 'No selectable text was found. OCR is required for scanned PDFs.',
                    ];
                } else {
                    $result = [
                        'status' => 'completed',
                        'extracted_text' => trim($text),
                        'extraction_method' => 'prinsfrank/pdfparser',
                        'page_count' => $pageCount,
                        'metadata' => ['parser' => 'prinsfrank/pdfparser'],
                        'error_code' => null,
                        'error_message' => null,
                    ];
                }
            }
        } catch (PdfParserException $exception) {
            report($exception);
            $result = $this->failure(
                'unreadable_pdf',
                'We could not read this PDF. Try uploading a valid, text-based PDF.',
                extractionMethod: 'prinsfrank/pdfparser',
            );
        } catch (Throwable $exception) {
            report($exception);
            $result = $this->failure(
                'unreadable_pdf',
                'We could not read this PDF. Try uploading a valid, text-based PDF.',
                extractionMethod: 'prinsfrank/pdfparser',
            );
        }

        $this->finish($source->id, $result);
    }

    private function processImage(ProjectSource $source, string $absolutePath): void
    {
        $dimensions = @getimagesize($absolutePath);

        $this->finish($source->id, [
            'status' => 'awaiting_vision',
            'extracted_text' => null,
            'extraction_method' => 'image_metadata',
            'page_count' => null,
            'metadata' => [
                'mime_type' => $dimensions['mime'] ?? $source->mime_type,
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
            ],
            'error_code' => 'vision_processing_required',
            'error_message' => 'Image understanding is not available yet. Vision processing is required.',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function failure(
        string $errorCode,
        string $errorMessage,
        ?array $metadata = null,
        ?string $extractionMethod = null,
        ?int $pageCount = null,
    ): array {
        return [
            'status' => 'failed',
            'extracted_text' => null,
            'extraction_method' => $extractionMethod,
            'page_count' => $pageCount,
            'metadata' => $metadata,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(int $sourceId, array $result): void
    {
        DB::transaction(function () use ($sourceId, $result): void {
            $source = ProjectSource::query()
                ->lockForUpdate()
                ->find($sourceId);

            if ($source === null) {
                return;
            }

            $extraction = $source->extraction()->firstOrCreate(
                ['project_source_id' => $source->id],
                ['status' => 'pending'],
            );

            if ($extraction->status !== 'processing') {
                return;
            }

            $extraction->update([
                ...$result,
                'processed_at' => now(),
            ]);
            $source->update(['processing_status' => $result['status']]);
        });
    }
}
