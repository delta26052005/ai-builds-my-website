<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProjectSource;
use App\Models\ProjectSource;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessProjectSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_text_from_a_text_based_pdf(): void
    {
        Storage::fake('project_sources');
        $source = $this->makeSource('application/pdf', $this->pdf('Northstar Studio business profile'));

        (new ProcessProjectSource($source->id))->handle();

        $this->assertDatabaseHas('source_extractions', [
            'project_source_id' => $source->id,
            'status' => 'completed',
            'extracted_text' => 'Northstar Studio business profile',
            'extraction_method' => 'prinsfrank/pdfparser',
            'page_count' => 1,
        ]);
        $this->assertSame('completed', $source->fresh()->processing_status);
    }

    public function test_it_marks_image_only_pdfs_as_awaiting_ocr(): void
    {
        Storage::fake('project_sources');
        $source = $this->makeSource('application/pdf', $this->pdf(''));

        (new ProcessProjectSource($source->id))->handle();

        $this->assertDatabaseHas('source_extractions', [
            'project_source_id' => $source->id,
            'status' => 'awaiting_ocr',
            'error_code' => 'no_extractable_text',
        ]);
        $this->assertNull($source->fresh()->extraction->extracted_text);
    }

    public function test_it_marks_corrupted_pdfs_as_failed_without_exposing_parser_errors(): void
    {
        Storage::fake('project_sources');
        $source = $this->makeSource('application/pdf', 'not a pdf');

        (new ProcessProjectSource($source->id))->handle();

        $this->assertSame('failed', $source->fresh()->processing_status);
        $this->assertSame('unreadable_pdf', $source->fresh()->extraction->error_code);
        $this->assertSame(
            'We could not read this PDF. Try uploading a valid, text-based PDF.',
            $source->fresh()->extraction->error_message,
        );
    }

    public function test_images_record_dimensions_and_wait_for_vision_processing(): void
    {
        Storage::fake('project_sources');
        $project = $this->makeProject();
        $file = UploadedFile::fake()->image('photo.png', 40, 30);
        $path = $file->storeAs((string) $project->id, 'photo.png', 'project_sources');
        $source = $project->sources()->create([
            'original_filename' => 'photo.png',
            'stored_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => $file->getSize(),
            'source_type' => 'image',
        ]);

        (new ProcessProjectSource($source->id))->handle();

        $extraction = $source->fresh()->extraction;
        $this->assertSame('awaiting_vision', $extraction->status);
        $this->assertSame(40, $extraction->metadata['width']);
        $this->assertSame(30, $extraction->metadata['height']);
        $this->assertNull($extraction->extracted_text);
    }

    public function test_duplicate_dispatches_do_not_create_duplicate_extractions(): void
    {
        Storage::fake('project_sources');
        $source = $this->makeSource('application/pdf', $this->pdf('Once only'));
        $job = new ProcessProjectSource($source->id);

        $job->handle();
        $job->handle();

        $this->assertDatabaseCount('source_extractions', 1);
        $this->assertSame('Once only', $source->fresh()->extraction->extracted_text);
    }

    public function test_missing_source_files_fail_safely(): void
    {
        Storage::fake('project_sources');
        $project = $this->makeProject();
        $source = $project->sources()->create([
            'original_filename' => 'missing.pdf',
            'stored_path' => $project->id.'/missing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'source_type' => 'pdf',
        ]);
        $source->extraction()->create(['status' => 'pending']);

        (new ProcessProjectSource($source->id))->handle();

        $this->assertSame('missing_file', $source->fresh()->extraction->error_code);
        $this->assertSame('failed', $source->fresh()->processing_status);
    }

    public function test_a_queued_job_does_not_recreate_records_after_source_deletion(): void
    {
        Storage::fake('project_sources');
        $source = $this->makeSource('application/pdf', $this->pdf('Will be deleted'));
        $sourceId = $source->id;

        $source->delete();

        (new ProcessProjectSource($sourceId))->handle();

        $this->assertDatabaseMissing('source_extractions', ['project_source_id' => $sourceId]);
        $this->assertDatabaseMissing('project_sources', ['id' => $sourceId]);
    }

    private function makeSource(string $mimeType, string $contents): ProjectSource
    {
        $project = $this->makeProject();
        $filename = Str::uuid().($mimeType === 'application/pdf' ? '.pdf' : '.bin');
        $path = $project->id.'/'.$filename;
        Storage::disk('project_sources')->put($path, $contents);

        $source = $project->sources()->create([
            'original_filename' => $filename,
            'stored_path' => $path,
            'mime_type' => $mimeType,
            'file_size' => strlen($contents),
            'source_type' => 'pdf',
        ]);
        $source->extraction()->create(['status' => 'pending']);

        return $source;
    }

    private function makeProject(): WebsiteProject
    {
        $user = User::factory()->create();

        return $user->websiteProjects()->create([
            'business_name' => 'Northstar Studio',
            'slug' => 'northstar-'.Str::uuid(),
            'city' => 'Portland',
            'country' => 'United States',
            'business_category' => 'Design studio',
            'status' => 'draft',
        ]);
    }

    private function pdf(string $text): string
    {
        $stream = $text === ''
            ? '0 0 m 100 100 l S'
            : 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];
        $document = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($document);
            $document .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($document);
        $document .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $document .= sprintf("%010d 00000 n \n", $offset);
        }

        return $document
            ."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n"
            ."startxref\n".$xrefOffset."\n%%EOF";
    }
}
