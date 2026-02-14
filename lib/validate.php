<?php
/**
 * Centralised validation for slug, option groups, selections, uploads
 */

require_once __DIR__ . '/../config.php';

class Validate {
    private static array $errors = [];

    public static function slug(string $slug): bool {
        self::$errors = [];
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            self::$errors[] = 'Slug must be lowercase alphanumeric with hyphens only';
            return false;
        }
        if (strlen($slug) > 100) {
            self::$errors[] = 'Slug too long';
            return false;
        }
        return true;
    }

    public static function optionGroups($data): bool {
        self::$errors = [];
        if (!is_array($data)) {
            self::$errors[] = 'Option groups must be an array';
            return false;
        }
        if (count($data) > 20) {
            self::$errors[] = 'Too many option groups';
            return false;
        }
        foreach ($data as $group) {
            if (!is_array($group) || !isset($group['label'], $group['choices'])) {
                self::$errors[] = 'Each option group must have label and choices';
                return false;
            }
            if (!is_string($group['label']) || strlen($group['label']) > 100) {
                self::$errors[] = 'Option group label invalid';
                return false;
            }
            if (!is_array($group['choices'])) {
                self::$errors[] = 'Choices must be an array';
                return false;
            }
            if (count($group['choices']) > 50) {
                self::$errors[] = 'Too many choices in option group';
                return false;
            }
            foreach ($group['choices'] as $choice) {
                if (!is_string($choice) || strlen($choice) > 200) {
                    self::$errors[] = 'Invalid choice value';
                    return false;
                }
            }
        }
        return true;
    }

    public static function selections(array $selections, array $optionGroups): bool {
        self::$errors = [];
        $validChoices = [];
        foreach ($optionGroups as $group) {
            $validChoices[$group['label']] = array_flip($group['choices']);
        }
        foreach ($selections as $label => $value) {
            if (!isset($validChoices[$label])) {
                self::$errors[] = "Unknown option group: $label";
                return false;
            }
            if (!isset($validChoices[$label][$value])) {
                self::$errors[] = "Invalid choice for $label";
                return false;
            }
        }
        foreach ($validChoices as $label => $choices) {
            if (!isset($selections[$label])) {
                self::$errors[] = "Missing selection for $label";
                return false;
            }
        }
        return true;
    }

    public static function note(?string $note): bool {
        self::$errors = [];
        if ($note !== null && strlen($note) > 5000) {
            self::$errors[] = 'Note too long';
            return false;
        }
        return true;
    }

    public static function comment(?string $comment): bool {
        self::$errors = [];
        if ($comment !== null && strlen($comment) > 5000) {
            self::$errors[] = 'Comment too long';
            return false;
        }
        return true;
    }

    /** Validate YYYY-MM-DD date string. Returns true if valid or null/empty. */
    public static function dateFormat(?string $date): bool {
        if ($date === null || $date === '') return true;
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && strtotime($date) !== false;
    }

    /** Sanitize date to YYYY-MM-DD or null if invalid. */
    public static function sanitizeDate(?string $date): ?string {
        if ($date === null || $date === '') return null;
        if (!self::dateFormat($date)) return null;
        return $date;
    }

    public static function upload(array $file): array {
        self::$errors = [];
        $result = ['valid' => false, 'path' => null, 'exif' => null];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            self::$errors[] = 'Upload failed';
            return $result;
        }
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            self::$errors[] = 'File too large';
            return $result;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ALLOWED_MIME_TYPES)) {
            self::$errors[] = 'Invalid file type';
            return $result;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) {
            self::$errors[] = 'Invalid file extension';
            return $result;
        }

        // Verify magic bytes for JPEG, PNG, WebP
        $bytes = file_get_contents($file['tmp_name'], false, null, 0, 12);
        $valid = false;
        if (substr($bytes, 0, 2) === "\xff\xd8") {
            $valid = ($mime === 'image/jpeg');
        } elseif (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $valid = ($mime === 'image/png');
        } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            $valid = ($mime === 'image/webp');
        }
        if (!$valid) {
            self::$errors[] = 'File content does not match type';
            return $result;
        }

        $result['valid'] = true;
        $result['mime'] = $mime;
        $result['ext'] = $ext === 'jpeg' ? 'jpg' : $ext;

        if (function_exists('exif_read_data') && in_array($mime, ['image/jpeg', 'image/tiff'])) {
            $exif = @exif_read_data($file['tmp_name']);
            if ($exif) {
                $result['exif'] = $exif;
            }
        }

        return $result;
    }

    public static function getErrors(): array {
        return self::$errors;
    }
}
