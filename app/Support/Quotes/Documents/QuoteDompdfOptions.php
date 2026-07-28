<?php

namespace App\Support\Quotes\Documents;

use Illuminate\Support\Facades\File;

/**
 * Secure Dompdf options applied at render time for customer quote PDFs.
 *
 * Remote assets, PHP, and JavaScript stay off. Fonts and temp files live under
 * the private storage tree; DejaVu Sans is seeded from the Dompdf vendor fonts.
 */
final class QuoteDompdfOptions
{
    public const DEFAULT_FONT = 'DejaVu Sans';

    /**
     * @return array<string, mixed>
     */
    public function secureOptions(): array
    {
        $this->ensurePrivateDirectories();
        $this->ensureDejaVuFonts();

        $privateRoot = storage_path('app/private');
        $fontDir = $privateRoot.DIRECTORY_SEPARATOR.'dompdf'.DIRECTORY_SEPARATOR.'fonts';
        $tempDir = $privateRoot.DIRECTORY_SEPARATOR.'dompdf'.DIRECTORY_SEPARATOR.'temp';

        return [
            'font_dir' => $fontDir.DIRECTORY_SEPARATOR,
            'font_cache' => $fontDir.DIRECTORY_SEPARATOR,
            'temp_dir' => $tempDir,
            'chroot' => realpath($privateRoot) ?: $privateRoot,
            'allowed_protocols' => [
                'file://' => ['rules' => []],
                'data://' => ['rules' => []],
            ],
            'enable_remote' => false,
            'enable_php' => false,
            'enable_javascript' => false,
            'enable_font_subsetting' => true,
            'default_font' => self::DEFAULT_FONT,
            'default_paper_size' => 'letter',
            'default_paper_orientation' => 'portrait',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
        ];
    }

    public function ensurePrivateDirectories(): void
    {
        $base = storage_path('app/private/dompdf');
        File::ensureDirectoryExists($base.'/fonts');
        File::ensureDirectoryExists($base.'/temp');
    }

    /**
     * Copy DejaVu Sans family metrics/fonts into the private font directory once.
     */
    public function ensureDejaVuFonts(): void
    {
        $source = base_path('vendor/dompdf/dompdf/lib/fonts');
        $destination = storage_path('app/private/dompdf/fonts');

        if (! is_dir($source)) {
            throw new InvalidQuoteDocumentException('Dompdf vendor fonts are not installed.');
        }

        File::ensureDirectoryExists($destination);

        foreach (File::files($source) as $file) {
            $name = $file->getFilename();
            if (! str_starts_with($name, 'DejaVuSans')) {
                continue;
            }

            $target = $destination.DIRECTORY_SEPARATOR.$name;
            if (! is_file($target)) {
                File::copy($file->getPathname(), $target);
            }
        }

        $installedFont = $destination.DIRECTORY_SEPARATOR.'DejaVuSans.ttf';
        if (! is_file($installedFont)) {
            throw new InvalidQuoteDocumentException('DejaVu Sans could not be installed for Dompdf.');
        }
    }
}
