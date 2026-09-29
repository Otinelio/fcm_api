<?php

namespace App\Services\Image;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AdvertisementImageService
{
    /**
     * Traite, optimise, redimensionne et enregistre une image publicitaire en format WebP haute qualité.
     *
     * @param  UploadedFile|TemporaryUploadedFile|string  $file
     * @param  string  $directory
     * @param  int  $maxWidth
     * @param  int  $quality
     * @return string Chemin relatif de stockage (ex: advertisements/01J...webp)
     */
    public function optimizeAndStore(
        mixed $file,
        string $directory = 'advertisements',
        int $maxWidth = 1200,
        int $quality = 82
    ): string {
        $disk = Storage::disk('public');

        // Récupérer le contenu binaire de l'image
        $contents = $this->getFileContents($file);
        if (! $contents) {
            throw new \InvalidArgumentException("Impossible de lire le fichier image fourni.");
        }

        // Essayer de traiter et convertir en WebP via GD
        $optimizedWebp = $this->convertToOptimizedWebp($contents, $maxWidth, $quality);

        $filename = (string) Str::ulid() . '.webp';
        $targetPath = trim($directory, '/') . '/' . $filename;

        if ($optimizedWebp) {
            $disk->put($targetPath, $optimizedWebp);
            return $targetPath;
        }

        // Fallback sécurisé si GD échoue (format non supporté ou image corrompue)
        $extension = $this->determineExtension($file);
        $fallbackName = (string) Str::ulid() . '.' . $extension;
        $fallbackPath = trim($directory, '/') . '/' . $fallbackName;
        $disk->put($fallbackPath, $contents);

        return $fallbackPath;
    }

    /**
     * Supprime physiquement l'ancien fichier d'image sur le disque public s'il existe.
     */
    public function deleteIfExists(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * Convertit et redimensionne une image binaire en WebP compressé.
     */
    protected function convertToOptimizedWebp(string $binaryData, int $maxWidth, int $quality): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binaryData);
        if (! $image) {
            return null;
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        if ($origWidth <= 0 || $origHeight <= 0) {
            imagedestroy($image);
            return null;
        }

        // Redimensionnement proportionnel si la largeur dépasse le maximum
        if ($origWidth > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round(($origHeight / $origWidth) * $maxWidth);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagecopyresampled(
                $resized,
                $image,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $origWidth, $origHeight
            );

            imagedestroy($image);
            $image = $resized;
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // Export buffer WebP
        ob_start();
        $saved = imagewebp($image, null, $quality);
        $webpData = ob_get_clean();

        imagedestroy($image);

        return $saved ? $webpData : null;
    }

    /**
     * Extrait le flux binaire selon le type d'objet fichier.
     */
    protected function getFileContents(mixed $file): ?string
    {
        if (is_string($file)) {
            if (file_exists($file)) {
                return file_get_contents($file);
            }
            if (Storage::disk('public')->exists($file)) {
                return Storage::disk('public')->get($file);
            }
            return null;
        }

        if ($file instanceof UploadedFile || $file instanceof TemporaryUploadedFile) {
            return file_get_contents($file->getRealPath());
        }

        return null;
    }

    /**
     * Détermine l'extension d'origine en fallback.
     */
    protected function determineExtension(mixed $file): string
    {
        if ($file instanceof UploadedFile || $file instanceof TemporaryUploadedFile) {
            return $file->getClientOriginalExtension() ?: 'jpg';
        }

        if (is_string($file)) {
            return pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg';
        }

        return 'jpg';
    }
}
