<?php
/**
 * Embed EXIF and metadata into JPEG using PEL
 */

require_once __DIR__ . '/../config.php';

function embedExifIntoImage(string $imagePath, array $metadata): bool {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    if (!class_exists('lsolesen\pel\PelJpeg')) {
        return false;
    }
    $prev = set_error_handler(function ($n, $msg, $file) {
        if ($n === E_DEPRECATED && strpos($file, 'pel') !== false) return true;
        return false;
    });
    try {
        $jpeg = new \lsolesen\pel\PelJpeg($imagePath);
        $exif = $jpeg->getExif();
        if (!$exif) {
            $exif = new \lsolesen\pel\PelExif();
            $tiff = new \lsolesen\pel\PelTiff();
            $ifd = new \lsolesen\pel\PelIfd(\lsolesen\pel\PelIfd::IFD0);
            $tiff->setIfd($ifd);
            $exif->setTiff($tiff);
        }
        $ifd0 = $exif->getTiff()->getIfd();
        if (!$ifd0) {
            return false;
        }
        $comment = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if (strlen($comment) > 65535) {
            $comment = substr($comment, 0, 65532) . '...';
        }
        $ifd0->addEntry(new \lsolesen\pel\PelEntryAscii(\lsolesen\pel\PelTag::IMAGE_DESCRIPTION, $comment));
        $jpeg->setExif($exif);
        $jpeg->saveFile($imagePath);
        return true;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($prev !== null) set_error_handler($prev);
    }
}
